<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Services\CourtConflictService;
use App\Support\ActivityLogger;
use App\Support\BookingHours;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalkInBookingController extends Controller
{
    public function store(Request $request, CourtConflictService $conflicts): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'payment_method' => ['nullable', 'in:cash,gcash'],
        ]);

        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $validated['date'].' '.$validated['start_time'], config('app.timezone'));
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $validated['date'].' '.$validated['end_time'], config('app.timezone'));
        if ($end->lessThanOrEqualTo($start)) $end = $end->addDay();
        $duration = $start->diffInMinutes($end);
        $step = BookingHours::stepMinutes();
        if ($duration < BookingHours::minDurationHours() * 60 || $duration > BookingHours::maxDurationHours() * 60
            || $start->minute % $step !== 0 || $end->minute % $step !== 0) {
            throw ValidationException::withMessages(['end_time' => 'Choose a duration and time aligned with the configured booking step.']);
        }

        $result = DB::transaction(function () use ($validated, $conflicts, $start, $end, $step) {
            $court = Court::query()->whereKey($validated['court_id'])->lockForUpdate()->firstOrFail();
            $conflict = $conflicts->findConflict(
                (int) $court->id,
                $validated['date'],
                $validated['start_time'],
                $validated['end_time'],
            );
            if ($conflict !== null) return ['conflict' => $conflict];

            $settings = BusinessSetting::current();
            $slotPrices = [];
            for ($cursor = $start; $cursor->lessThan($end); $cursor = $cursor->addMinutes($step)) {
                $rate = $court->hourly_rate;
                $slotPrices[] = $rate === null ? null : round(
                    $settings->applyPeakAdjustment((float) $rate, $cursor->hour) * ($step / 60),
                    2
                );
            }
            $priced = ! in_array(null, $slotPrices, true);
            if (! $priced && ! array_key_exists('amount', $validated)) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount because this court range could not be priced automatically.']);
            }

            $total = $priced ? round(array_sum($slotPrices), 2) : (float) $validated['amount'];
            if (array_key_exists('amount', $validated) && $validated['amount'] !== null) $total = (float) $validated['amount'];

            $booking = Booking::create([
                'user_id' => null,
                'customer_name' => $validated['customer_name'],
                'contact_number' => $validated['contact_number'],
                'email' => $validated['email'] ?? null,
                'court_id' => $court->id,
                'date' => $validated['date'],
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'amount' => $total,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'notes' => $validated['notes'] ?? null,
                'status' => 'confirmed',
                'source' => 'walk_in',
                'confirmed_at' => now(),
            ]);

            for ($cursor = $start, $index = 0; $cursor->lessThan($end); $cursor = $cursor->addMinutes($step), $index++) {
                $booking->slots()->create([
                    'court_id' => $court->id,
                    'date' => $cursor->toDateString(),
                    'start_time' => $cursor->format('H:i:s'),
                    'end_time' => $cursor->addMinutes($step)->format('H:i:s'),
                    'price' => $slotPrices[$index] ?? 0,
                ]);
            }

            return ['booking' => $booking->load(['court', 'slots'])];
        });

        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'The selected time conflicts with an existing booking, block, or closure.',
                'conflict' => $result['conflict'],
            ], 422);
        }

        /** @var Booking $booking */
        $booking = $result['booking'];
        ActivityLogger::log(
            'booking.walk_in_created',
            sprintf('%s recorded a walk-in booking for %s on %s.', auth()->user()->name, $booking->customer_name, $booking->date->format('M j, Y')),
            subject: $booking,
            properties: $booking->only(['court_id', 'date', 'start_time', 'end_time', 'amount', 'payment_method', 'source']),
        );

        return response()->json([
            'message' => 'Walk-in booking created.',
            'booking' => [
                'id' => $booking->id,
                'customer' => $booking->customer_name,
                'court_id' => $booking->court_id,
                'court' => $booking->court?->name,
                'date' => $booking->date->toDateString(),
                'start' => substr((string) $booking->start_time, 0, 5),
                'end' => substr((string) $booking->end_time, 0, 5),
                'status' => $booking->status,
                'source' => $booking->source,
                'amount' => (float) $booking->amount,
                'slots' => $booking->slots->map(fn ($slot) => [
                    'date' => $slot->date->toDateString(),
                    'start' => substr((string) $slot->start_time, 0, 5),
                    'end' => substr((string) $slot->end_time, 0, 5),
                ])->values(),
                'equipment' => [],
            ],
        ], 201);
    }
}
