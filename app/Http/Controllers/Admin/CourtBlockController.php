<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Services\CourtConflictService;
use App\Support\ActivityLogger;
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
            'block_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
        ]);

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

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        return $hour * 60 + $minute;
    }
}
