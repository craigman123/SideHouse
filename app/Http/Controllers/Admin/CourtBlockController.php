<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Services\CourtConflictService;
use App\Support\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CourtBlockController extends Controller
{
    public function store(Request $request, CourtConflictService $conflicts): JsonResponse
    {
        $validated = $request->validate([
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
            'type' => ['required', Rule::in(['closed', 'maintenance', 'open_play'])],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'block_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::now('Asia/Manila')->toDateString()],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
        ]);
        $venueNow = CarbonImmutable::now('Asia/Manila');
        if ($validated['block_date'] === $venueNow->toDateString()
            && $validated['start_time'] < $venueNow->format('H:i')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'start_time' => 'Choose a start time that has not passed in Asia/Manila.',
            ]);
        }

        $courtId = isset($validated['court_id']) ? (int) $validated['court_id'] : null;
        $start = $validated['start_time'];
        $end = $validated['end_time'];
        if ($end < $start) {
            $duration = (24 * 60 - $this->minutes($start)) + $this->minutes($end);
        } else {
            $duration = $this->minutes($end) - $this->minutes($start);
        }

        if ($duration <= 0 || $duration > 24 * 60) {
            return response()->json(['message' => 'The block duration must be between one minute and 24 hours.'], 422);
        }

        $result = DB::transaction(function () use ($validated, $courtId, $conflicts) {
            $courts = $courtId === null
                ? Court::query()->orderBy('id')->lockForUpdate()->get(['id'])
                : Court::query()->whereKey($courtId)->lockForUpdate()->get(['id']);

            if ($courts->isEmpty()) {
                return ['conflict' => ['type' => 'court', 'id' => null, 'title' => 'No courts are available.']];
            }

            foreach ($courts as $court) {
                $conflict = $conflicts->findConflict(
                    (int) $court->id,
                    $validated['block_date'],
                    $validated['start_time'],
                    $validated['end_time'],
                );

                if ($conflict !== null) {
                    $conflict['court_id'] = (int) $court->id;
                    return ['conflict' => $conflict];
                }
            }

            $block = CourtBlock::create([
                ...$validated,
                'court_id' => $courtId,
                'created_by' => auth()->user()->user_id,
            ]);

            return ['block' => $block];
        });

        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'The selected time conflicts with an existing booking, block, or closure.',
                'conflict' => $result['conflict'],
            ], 422);
        }

        /** @var CourtBlock $block */
        $block = $result['block'];
        ActivityLogger::log(
            'court_block.created',
            sprintf('%s created a %s block (%s) for %s.', auth()->user()->name, $block->type, $block->title, $block->block_date->format('M j, Y')),
            subject: $block,
            properties: $block->only(['court_id', 'type', 'title', 'block_date', 'start_time', 'end_time']),
        );

        return response()->json(['message' => 'Court block created.', 'block' => $block], 201);
    }

    public function destroy(CourtBlock $block): JsonResponse
    {
        $deleted = DB::transaction(function () use ($block) {
            $initial = CourtBlock::query()->whereKey($block->id)->firstOrFail();

            if ($initial->court_id === null) {
                Court::query()->orderBy('id')->lockForUpdate()->get(['id']);
            } else {
                Court::query()->whereKey($initial->court_id)->lockForUpdate()->firstOrFail();
            }

            $locked = CourtBlock::query()->whereKey($block->id)->lockForUpdate()->firstOrFail();
            $details = $locked->only(['id', 'court_id', 'type', 'title', 'block_date', 'start_time', 'end_time']);
            $locked->delete();

            return $details;
        });

        ActivityLogger::log(
            'court_block.deleted',
            sprintf('%s deleted the %s block (%s) for %s.', auth()->user()->name, $deleted['type'], $deleted['title'], $deleted['block_date']),
            subject: $block,
            properties: $deleted,
        );

        return response()->json(['message' => 'Court block deleted.', 'id' => $block->id]);
    }

    public function update(Request $request, CourtBlock $block, CourtConflictService $conflicts): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'required', Rule::in(['closed', 'maintenance', 'open_play'])],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'court_id' => ['sometimes', 'nullable', 'integer', 'exists:courts,id'],
            'block_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::now('Asia/Manila')->toDateString()],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i', 'different:start_time'],
            'expected_updated_at' => ['sometimes', 'required', 'date'],
        ]);

        $result = DB::transaction(function () use ($block, $validated, $conflicts) {
            $locked = CourtBlock::query()->whereKey($block->id)->lockForUpdate()->firstOrFail();
            if (isset($validated['expected_updated_at'])
                && \Carbon\Carbon::parse($validated['expected_updated_at'])->format('U.u') !== $locked->updated_at->format('U.u')) {
                return ['stale' => $locked->load('court')];
            }
            $courtId = array_key_exists('court_id', $validated) ? $validated['court_id'] : $locked->court_id;
            $date = $validated['block_date'] ?? $locked->block_date->toDateString();
            $startTime = $validated['start_time'] ?? substr((string) $locked->start_time, 0, 5);
            $endTime = $validated['end_time'] ?? substr((string) $locked->end_time, 0, 5);
            $scheduleChanged = $courtId !== $locked->court_id
                || $date !== $locked->block_date->toDateString()
                || $startTime !== substr((string) $locked->start_time, 0, 5)
                || $endTime !== substr((string) $locked->end_time, 0, 5);
            $venueNow = CarbonImmutable::now('Asia/Manila');
            $oldStart = substr((string) $locked->start_time, 0, 5);
            $oldEnd = substr((string) $locked->end_time, 0, 5);
            $oldIsPast = $locked->block_date->toDateString() < $venueNow->toDateString()
                || ($locked->block_date->toDateString() === $venueNow->toDateString()
                    && $oldEnd > $oldStart && $oldEnd <= $venueNow->format('H:i'));
            if ($oldIsPast && ($date !== $locked->block_date->toDateString()
                || $startTime !== $oldStart || $endTime !== $oldEnd || $courtId !== $locked->court_id)) {
                return ['rejected' => 'Past calendar events cannot have their date, time, or court changed.'];
            }
            if ($scheduleChanged && $date === $venueNow->toDateString() && $startTime < $venueNow->format('H:i')) {
                return ['rejected' => 'Choose a start time that has not passed in Asia/Manila.'];
            }
            $duration = $this->minutes($endTime) <= $this->minutes($startTime)
                ? (1440 - $this->minutes($startTime)) + $this->minutes($endTime)
                : $this->minutes($endTime) - $this->minutes($startTime);
            if ($duration <= 0 || $duration > 1440) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'end_time' => 'The block duration must be between one minute and 24 hours.',
                ]);
            }

            $allCourtIds = $locked->court_id === null || $courtId === null;
            if ($allCourtIds) {
                $courtRows = Court::query()->orderBy('id')->lockForUpdate()->get(['id']);
            } else {
                $ids = array_values(array_unique([(int) $locked->court_id, (int) $courtId]));
                sort($ids);
                $courtRows = Court::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            }
            $targetCourts = $courtId === null
                ? $courtRows
                : $courtRows->where('id', (int) $courtId)->values();
            if ($targetCourts->isEmpty()) {
                return ['conflict' => ['type' => 'court', 'id' => null, 'title' => 'No courts are available.']];
            }

            foreach ($targetCourts as $court) {
                $conflict = $conflicts->findConflict(
                    (int) $court->id,
                    $date,
                    $startTime,
                    $endTime,
                    null,
                    (int) $locked->id,
                );
                if ($conflict !== null) {
                    $conflict['court_id'] = (int) $court->id;
                    return ['conflict' => $conflict];
                }
            }

            $locked->fill([
                'type' => $validated['type'] ?? $locked->type,
                'title' => $validated['title'] ?? $locked->title,
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $locked->notes,
                'court_id' => $courtId,
                'block_date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ])->save();

            return ['block' => $locked->load('court')];
        });

        if (isset($result['stale'])) {
            $stale = $result['stale'];
            return response()->json([
                'message' => 'This calendar item changed while you were moving it. The latest version has been loaded.',
                'block' => [
                    'id' => $stale->id, 'updated_at' => $stale->updated_at?->format('Y-m-d\\TH:i:s.uP'),
                    'court_id' => $stale->court_id, 'type' => $stale->type, 'title' => $stale->title,
                    'notes' => $stale->notes, 'block_date' => $stale->block_date->toDateString(),
                    'start_time' => substr((string) $stale->start_time, 0, 5),
                    'end_time' => substr((string) $stale->end_time, 0, 5),
                ],
            ], 409);
        }

        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'The selected time conflicts with an existing booking, block, or closure.',
                'conflict' => $result['conflict'],
            ], 422);
        }
        if (isset($result['rejected'])) return response()->json(['message' => $result['rejected']], 422);

        /** @var CourtBlock $updated */
        $updated = $result['block'];
        ActivityLogger::log(
            'court_block.updated',
            sprintf('%s edited the %s block (%s) for %s.', auth()->user()->name, $updated->type, $updated->title, $updated->block_date->format('M j, Y')),
            subject: $updated,
            properties: $updated->only(['court_id', 'type', 'title', 'block_date', 'start_time', 'end_time']),
        );

        return response()->json([
            'message' => 'Calendar event updated.',
            'block' => [
                'id' => $updated->id,
                'updated_at' => $updated->updated_at?->format('Y-m-d\\TH:i:s.uP'),
                'court_id' => $updated->court_id,
                'type' => $updated->type,
                'title' => $updated->title,
                'notes' => $updated->notes,
                'block_date' => $updated->block_date->toDateString(),
                'start_time' => substr((string) $updated->start_time, 0, 5),
                'end_time' => substr((string) $updated->end_time, 0, 5),
            ],
        ]);
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        return $hour * 60 + $minute;
    }
}
