<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Services\SpecificDateClosureService;
use App\Services\UserAccessService;
use App\Support\ActivityLogger;
use App\Support\BookingHours;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SpecificDateClosureController extends Controller
{
    public function index(Request $request, UserAccessService $access): JsonResponse
    {
        $this->authorizeManage($request, $access);
        return response()->json(['closures' => $this->upcomingClosures()]);
    }

    public function operatingHours(Request $request, UserAccessService $access): JsonResponse
    {
        $this->authorizeManage($request, $access);
        $today = CarbonImmutable::now('Asia/Manila')->toDateString();
        $validated = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
        ]);
        $dates = app(SpecificDateClosureService::class)->validateRange($validated['start_date'], $validated['end_date']);
        $settings = BookingHours::settings();
        $openMinutes = (int) $settings->open_hour * 60;
        $days = [];

        foreach ($dates as $date) {
            $closeMinutes = BookingHours::closeHourForDate($date);
            $closeOffset = ($closeMinutes - $openMinutes + 1440) % 1440;
            if ($closeOffset === 0) $closeOffset = 1440;
            $days[] = [
                'date' => $date,
                'close_minutes' => $closeMinutes,
                'close_offset' => $closeOffset,
                'closed_weekday' => in_array(CarbonImmutable::parse($date)->dayOfWeek, BookingHours::closedWeekdays(), true),
                'has_specific_close' => $closeMinutes !== ((int) $settings->close_hour === 0 ? 1440 : (int) $settings->close_hour * 60),
            ];
        }

        return response()->json([
            'today' => $today,
            'venue_now' => CarbonImmutable::now('Asia/Manila')->toIso8601String(),
            'open_hour' => (int) $settings->open_hour,
            'open_minutes' => $openMinutes,
            'step_minutes' => (int) $settings->step_minutes,
            'overnight' => (int) $settings->close_hour <= (int) $settings->open_hour,
            'court_id' => $validated['court_id'] ?? null,
            'days' => $days,
        ]);
    }

    public function store(Request $request, UserAccessService $access, SpecificDateClosureService $closures): JsonResponse
    {
        $this->authorizeManage($request, $access);
        $validated = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);
        $dates = $closures->validateRange($validated['start_date'], $validated['end_date']);
        $courtId = isset($validated['court_id']) && $validated['court_id'] !== '' ? (int) $validated['court_id'] : null;
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

        $result = DB::transaction(function () use ($closures, $validated, $dates, $courtId, $reason) {
            $courtIds = $closures->lockCourts($courtId);
            $windows = [];
            $conflicts = [];

            foreach ($dates as $date) {
                [$start, $end] = $closures->intervalForDate($date, $validated['start_time'], $validated['end_time']);
                $windows[$date] = [$start, $end];
                $conflicts = [...$conflicts, ...$closures->conflicts($courtIds, $start, $end)];
            }

            if ($conflicts) {
                return ['conflicts' => $this->uniqueConflicts($conflicts)];
            }

            $created = [];
            foreach ($dates as $date) {
                $block = CourtBlock::query()->create([
                    'court_id' => $courtId,
                    'type' => 'closed',
                    'title' => 'Closed',
                    'notes' => $reason,
                    'block_date' => $date,
                    'start_time' => $validated['start_time'],
                    'end_time' => $validated['end_time'],
                    'created_by' => $requestUserId = auth()->user()->user_id,
                ]);
                ActivityLogger::log(
                    'court_block.specific_closure_created',
                    sprintf('%s created a specific court closure for %s (%s–%s).', auth()->user()->name, $date, $validated['start_time'], $validated['end_time']),
                    actor: auth()->user(), subject: $block,
                    properties: ['court_id' => $courtId, 'court' => $courtId ? Court::find($courtId)?->name : 'All courts', 'date' => $date, 'start_time' => $validated['start_time'], 'end_time' => $validated['end_time'], 'reason' => $reason],
                );
                $created[] = (int) $block->id;
            }

            return ['ids' => $created];
        });

        if (isset($result['conflicts'])) {
            return response()->json([
                'message' => 'This closure overlaps a booking, open play, or an existing closure/maintenance period. Resolve the conflicts first; nothing was changed.',
                'conflicts' => $result['conflicts'],
            ], 409);
        }

        return response()->json([
            'message' => count($result['ids']) === 1 ? 'Specific date closure added.' : 'Specific date closures added.',
            'created' => count($result['ids']),
            'closures' => $this->upcomingClosures(),
        ], 201);
    }

    public function update(Request $request, CourtBlock $closure, UserAccessService $access, SpecificDateClosureService $closures): JsonResponse
    {
        $this->authorizeManage($request, $access);
        abort_unless($closure->type === 'closed' && $closure->title === 'Closed', 404);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);
        $dates = $closures->validateRange($validated['date'], $validated['date']);
        $nextCourtId = isset($validated['court_id']) && $validated['court_id'] !== '' ? (int) $validated['court_id'] : null;
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

        $result = DB::transaction(function () use ($request, $closure, $closures, $validated, $dates, $nextCourtId, $reason) {
            $locked = CourtBlock::query()->whereKey($closure->id)->lockForUpdate()->firstOrFail();
            $lockAll = $locked->court_id === null || $nextCourtId === null;
            if ($lockAll) $closures->lockCourts(null);
            else {
                $ids = array_values(array_unique([(int) $locked->court_id, $nextCourtId]));
                sort($ids);
                Court::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            }
            $courtIds = $nextCourtId === null
                ? Court::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [$nextCourtId];
            if (! $courtIds) throw ValidationException::withMessages(['court_id' => 'No courts are available for this closure.']);

            [$start, $end] = $closures->intervalForDate($dates[0], $validated['start_time'], $validated['end_time']);
            $conflicts = $closures->conflicts($courtIds, $start, $end, (int) $locked->id);
            if ($conflicts) return ['conflicts' => $this->uniqueConflicts($conflicts)];

            $before = [
                'court_id' => $locked->court_id,
                'date' => $locked->block_date->toDateString(),
                'start_time' => substr((string) $locked->start_time, 0, 5),
                'end_time' => substr((string) $locked->end_time, 0, 5),
                'reason' => $locked->notes,
            ];
            $locked->forceFill([
                'court_id' => $nextCourtId,
                'title' => 'Closed',
                'notes' => $reason,
                'block_date' => $dates[0],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
            ])->save();

            ActivityLogger::log(
                'court_block.specific_closure_updated',
                sprintf('%s updated a specific court closure for %s (%s–%s).', auth()->user()->name, $dates[0], $validated['start_time'], $validated['end_time']),
                actor: auth()->user(), subject: $locked,
                properties: ['old' => $before, 'new' => ['court_id' => $nextCourtId, 'court' => $nextCourtId ? Court::find($nextCourtId)?->name : 'All courts', 'date' => $dates[0], 'start_time' => $validated['start_time'], 'end_time' => $validated['end_time'], 'reason' => $reason]],
            );

            return ['id' => (int) $locked->id];
        });

        if (isset($result['conflicts'])) {
            return response()->json([
                'message' => 'This change overlaps a booking, open play, or an existing closure/maintenance period. Resolve the conflicts first; nothing was changed.',
                'conflicts' => $result['conflicts'],
            ], 409);
        }

        return response()->json(['message' => 'Specific date closure updated.', 'closures' => $this->upcomingClosures()]);
    }

    public function destroy(Request $request, CourtBlock $closure, UserAccessService $access, SpecificDateClosureService $closures): JsonResponse
    {
        $this->authorizeManage($request, $access);
        abort_unless($closure->type === 'closed' && $closure->title === 'Closed', 404);

        DB::transaction(function () use ($request, $closure, $closures) {
            $locked = CourtBlock::query()->whereKey($closure->id)->lockForUpdate()->firstOrFail();
            $courtIds = $closures->lockCourts($locked->court_id === null ? null : (int) $locked->court_id);
            $properties = [
                'court_id' => $locked->court_id,
                'court' => $locked->court_id ? Court::find($locked->court_id)?->name : 'All courts',
                'date' => $locked->block_date->toDateString(),
                'start_time' => substr((string) $locked->start_time, 0, 5),
                'end_time' => substr((string) $locked->end_time, 0, 5),
                'reason' => $locked->notes,
            ];
            ActivityLogger::log(
                'court_block.specific_closure_removed',
                sprintf('%s removed a specific court closure for %s (%s–%s).', auth()->user()->name, $properties['date'], $properties['start_time'], $properties['end_time']),
                actor: auth()->user(), subject: $locked, properties: $properties,
            );
            $locked->delete();
        });

        return response()->json(['message' => 'Specific date closure removed.', 'closures' => $this->upcomingClosures()]);
    }

    private function authorizeManage(Request $request, UserAccessService $access): void
    {
        if ($request->user() && $access->allows($request->user(), 'calendar_events.manage')) return;

        ActivityLogger::log(
            'calendar.specific_closure.denied',
            'An account was denied access to specific court closures.',
            actor: $request->user(),
            properties: ['action' => $request->route()?->getName(), 'reason' => 'Only the effective admin or owner role can manage closures.'],
        );
        abort(403, 'Only an admin or owner can manage specific date closures.');
    }

    private function upcomingClosures(): array
    {
        return CourtBlock::query()->with(['court:id,name', 'creator:user_id,name'])
            ->where('type', 'closed')
            ->where('title', 'Closed')
            ->whereDate('block_date', '>=', CarbonImmutable::now('Asia/Manila')->toDateString())
            ->orderBy('block_date')->orderBy('start_time')->get()
            ->map(fn (CourtBlock $block) => [
                'id' => (int) $block->id,
                'court_id' => $block->court_id,
                'court' => $block->court?->name ?? 'All courts',
                'date' => $block->block_date->toDateString(),
                'start_time' => substr((string) $block->start_time, 0, 5),
                'end_time' => substr((string) $block->end_time, 0, 5),
                'reason' => $block->notes ?: ($block->title === 'Closed' ? '' : $block->title),
                'created_by' => $block->creator?->name ?? 'Unknown',
            ])->all();
    }

    private function uniqueConflicts(array $conflicts): array
    {
        $unique = [];
        foreach ($conflicts as $conflict) {
            $key = implode('|', [$conflict['status'], $conflict['court'], $conflict['date'], $conflict['start_time'], $conflict['end_time'], $conflict['customer']]);
            $unique[$key] = $conflict;
        }

        return array_values($unique);
    }
}
