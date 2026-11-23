<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\WeeklyCourtClosure;
use App\Services\UserAccessService;
use App\Services\WeeklyCourtClosureService;
use App\Support\ActivityLogger;
use App\Support\BookingHours;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SpecificDateClosureController extends Controller
{
    public function index(Request $request, UserAccessService $access, WeeklyCourtClosureService $closures): JsonResponse
    {
        $this->authorizeManage($request, $access);
        $rules = WeeklyCourtClosure::query()->with(['court:id,name', 'creator:user_id,name'])->orderBy('weekday')->orderBy('start_time')->get();
        $items = $rules->map(function (WeeklyCourtClosure $rule) use ($closures) {
            $warnings = $closures->upcomingBookingWarnings([(int) $rule->weekday], substr((string) $rule->start_time, 0, 5), substr((string) $rule->end_time, 0, 5), $rule->court_id ? (int) $rule->court_id : null);
            return $this->serializeRule($rule, $warnings);
        })->values()->all();
        return response()->json(['closures' => $items]);
    }

    public function operatingHours(Request $request, UserAccessService $access): JsonResponse
    {
        $this->authorizeManage($request, $access);
        $validated = $request->validate(['weekdays' => ['nullable', 'array'], 'weekdays.*' => ['integer', 'between:0,6']]);
        $selected = array_map('intval', $validated['weekdays'] ?? []);
        $weekdayHours = BookingHours::operatingHoursByWeekday();
        return response()->json([
            'open_minutes' => $weekdayHours[0]['open_minutes'],
            'close_offset' => $weekdayHours[0]['close_offset'],
            'step_minutes' => BookingHours::stepMinutes(),
            'overnight' => BookingHours::isOvernight(),
            'closed_weekdays' => BookingHours::closedWeekdays(),
            'weekdays' => array_map(fn ($day) => [...$day, 'selected' => in_array($day['weekday'], $selected, true)], $weekdayHours),
        ]);
    }

    public function store(Request $request, UserAccessService $access, WeeklyCourtClosureService $closures): JsonResponse
    {
        $this->authorizeManage($request, $access);
        $validated = $request->validate([
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);
        $weekdays = $closures->validateWeekdays($validated['weekdays']);
        [$start, $end] = $closures->validateHoursForWeekdays($weekdays, $validated['start_time'], $validated['end_time']);
        $courtId = filled($validated['court_id'] ?? null) ? (int) $validated['court_id'] : null;
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;
        $result = DB::transaction(function () use ($closures, $weekdays, $start, $end, $courtId, $validated, $reason, $request) {
            $closures->lockCourts($courtId);
            if ($conflict = $closures->overlappingRule($weekdays, $start, $end, $courtId)) {
                throw ValidationException::withMessages(['weekdays' => 'This overlaps an active weekly closure for '.($conflict->court?->name ?? 'all courts').' on '.CarbonImmutable::parse('2024-01-07')->addDays($conflict->weekday)->format('l').'.']);
            }
            $records = [];
            foreach ($weekdays as $weekday) {
                $rule = WeeklyCourtClosure::query()->create([
                    'weekday' => $weekday, 'court_id' => $courtId,
                    'start_time' => $validated['start_time'], 'end_time' => $validated['end_time'],
                    'reason' => $reason, 'created_by' => $request->user()->user_id, 'active' => true,
                ]);
                $records[] = $rule;
            }
            ActivityLogger::log('weekly_court_closure.created', $request->user()->name.' created a weekly court closure.', actor: $request->user(), subject: $records[0], properties: [
                'weekdays' => $weekdays, 'court_id' => $courtId, 'court' => $courtId ? Court::find($courtId)?->name : 'All courts',
                'start_time' => $validated['start_time'], 'end_time' => $validated['end_time'], 'reason' => $reason,
            ]);
            return [
                'records' => $records,
                'warnings' => $closures->upcomingBookingWarnings($weekdays, $validated['start_time'], $validated['end_time'], $courtId),
            ];
        });

        return response()->json(['message' => 'Weekly closure added.', 'created' => count($result['records']), 'warnings' => $result['warnings'], 'closures' => $this->listRules($closures)], 201);
    }

    public function update(Request $request, WeeklyCourtClosure $closure, UserAccessService $access, WeeklyCourtClosureService $closures): JsonResponse
    {
        $this->authorizeManage($request, $access);
        $validated = $request->validate([
            'weekday' => ['required', 'integer', 'between:0,6'],
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
            'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);
        $weekdays = $closures->validateWeekdays([(int) $validated['weekday']]);
        [$start, $end] = $closures->validateHoursForWeekdays($weekdays, $validated['start_time'], $validated['end_time']);
        $courtId = filled($validated['court_id'] ?? null) ? (int) $validated['court_id'] : null;
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;
        $updated = DB::transaction(function () use ($request, $closure, $closures, $validated, $weekdays, $start, $end, $courtId, $reason) {
            $locked = WeeklyCourtClosure::query()->whereKey($closure->id)->lockForUpdate()->firstOrFail();
            $ids = array_values(array_unique(array_filter([$locked->court_id, $courtId])));
            sort($ids);
            if ($locked->court_id === null || $courtId === null) $closures->lockCourts(null);
            elseif ($ids) Court::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            if ($conflict = $closures->overlappingRule($weekdays, $start, $end, $courtId, (int) $locked->id)) {
                throw ValidationException::withMessages(['weekday' => 'This overlaps an active weekly closure for '.($conflict->court?->name ?? 'all courts').'.']);
            }
            $before = $locked->only(['weekday', 'court_id', 'start_time', 'end_time', 'reason', 'active']);
            $locked->forceFill(['weekday' => $weekdays[0], 'court_id' => $courtId, 'start_time' => $validated['start_time'], 'end_time' => $validated['end_time'], 'reason' => $reason])->save();
            ActivityLogger::log('weekly_court_closure.updated', $request->user()->name.' updated a weekly court closure.', actor: $request->user(), subject: $locked, properties: [
                'old' => $before, 'weekdays' => $weekdays, 'court_id' => $courtId, 'court' => $courtId ? Court::find($courtId)?->name : 'All courts',
                'start_time' => $validated['start_time'], 'end_time' => $validated['end_time'], 'reason' => $reason,
            ]);
            return $locked;
        });
        $warnings = $closures->upcomingBookingWarnings([(int) $updated->weekday], substr((string) $updated->start_time, 0, 5), substr((string) $updated->end_time, 0, 5), $updated->court_id ? (int) $updated->court_id : null);
        return response()->json(['message' => 'Weekly closure updated.', 'warnings' => $warnings, 'closures' => $this->listRules($closures)]);
    }

    public function destroy(Request $request, WeeklyCourtClosure $closure, UserAccessService $access, WeeklyCourtClosureService $closures): JsonResponse
    {
        $this->authorizeManage($request, $access);
        DB::transaction(function () use ($request, $closure, $closures) {
            $locked = WeeklyCourtClosure::query()->whereKey($closure->id)->lockForUpdate()->firstOrFail();
            $closures->lockCourts($locked->court_id === null ? null : (int) $locked->court_id);
            ActivityLogger::log('weekly_court_closure.removed', $request->user()->name.' removed a weekly court closure.', actor: $request->user(), subject: $locked, properties: [
                'weekday' => (int) $locked->weekday, 'court_id' => $locked->court_id, 'court' => $locked->court?->name ?? 'All courts',
                'start_time' => substr((string) $locked->start_time, 0, 5), 'end_time' => substr((string) $locked->end_time, 0, 5), 'reason' => $locked->reason,
            ]);
            $locked->delete();
        });
        return response()->json(['message' => 'Weekly closure removed.', 'closures' => $this->listRules($closures)]);
    }

    private function authorizeManage(Request $request, UserAccessService $access): void
    {
        if ($request->user() && $access->allows($request->user(), 'calendar_events.manage')) return;
        ActivityLogger::log('calendar.weekly_closure.denied', 'An account was denied access to weekly court closures.', actor: $request->user(), properties: ['action' => $request->route()?->getName()]);
        abort(403, 'Only an admin or owner can manage weekly closures.');
    }

    private function listRules(WeeklyCourtClosureService $service): array
    {
        return WeeklyCourtClosure::query()->with(['court:id,name', 'creator:user_id,name'])->orderBy('weekday')->orderBy('start_time')->get()
            ->map(function (WeeklyCourtClosure $rule) use ($service) {
                return $this->serializeRule($rule, $service->upcomingBookingWarnings([(int) $rule->weekday], substr((string) $rule->start_time, 0, 5), substr((string) $rule->end_time, 0, 5), $rule->court_id ? (int) $rule->court_id : null));
            })->all();
    }

    private function serializeRule(WeeklyCourtClosure $rule, array $warnings): array
    {
        return ['id' => (int) $rule->id, 'weekday' => (int) $rule->weekday,
            'weekday_label' => CarbonImmutable::parse('2024-01-07', 'Asia/Manila')->addDays($rule->weekday)->format('D'),
            'weekdays' => [(int) $rule->weekday], 'court_id' => $rule->court_id, 'court' => $rule->court?->name ?? 'All courts',
            'start_time' => substr((string) $rule->start_time, 0, 5), 'end_time' => substr((string) $rule->end_time, 0, 5),
            'reason' => $rule->reason, 'created_by' => $rule->creator?->name ?? 'Unknown', 'active' => (bool) $rule->active,
            'conflict_count' => count($warnings), 'warnings' => $warnings];
    }
}
