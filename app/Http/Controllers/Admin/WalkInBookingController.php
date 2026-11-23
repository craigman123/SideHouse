<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Services\AdminCalendarOverrideWarnings;
use App\Support\ActivityLogger;
use App\Support\BookingHours;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalkInBookingController extends Controller
{
    public function store(Request $request, AdminCalendarOverrideWarnings $overrideWarnings): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'court_id' => ['required', 'integer', 'exists:courts,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::now('Asia/Manila')->toDateString()],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'payment_method' => ['nullable', 'in:cash,gcash'],
        ]);

        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $validated['date'].' '.$validated['start_time'], config('app.timezone'));
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $validated['date'].' '.$validated['end_time'], config('app.timezone'));
        if ($end->equalTo($start)) throw ValidationException::withMessages(['end_time' => 'End time must be after start time.']);
        if ($end->lessThanOrEqualTo($start)) $end = $end->addDay();
        $duration = $start->diffInMinutes($end);
        $step = BookingHours::stepMinutes();
        if ($duration < $step) {
            throw ValidationException::withMessages(['end_time' => "Choose a duration of at least {$step} minutes."]);
        }

        $result = DB::transaction(function () use ($validated, $overrideWarnings, $start, $end, $step) {
            $court = Court::query()->whereKey($validated['court_id'])->lockForUpdate()->firstOrFail();
            $warnings = $overrideWarnings->forInterval(
                (int) $court->id,
                $validated['date'],
                $validated['start_time'],
                $validated['end_time'],
            );

            $settings = BusinessSetting::current();
            $slotPrices = [];
            $usesOpenPlayPrice = false;
            for ($cursor = $start; $cursor->lessThan($end);) {
                $slotEnd = $cursor->addMinutes($step);
                if ($slotEnd->greaterThan($end)) $slotEnd = $end;
                $slotMinutes = $cursor->diffInMinutes($slotEnd);
                $rate = $court->hourly_rate;
                $event = app(\App\Services\OpenPlayAvailability::class)->eventForSlot(
                    (int) $court->id, $cursor->toDateString(), $cursor->format('H:i:s'), $slotEnd->format('H:i:s')
                );
                if ($event && app(\App\Services\OpenPlayAvailability::class)->joined($event, (int) $court->id) >= (int) $event->max_players) {
                    throw ValidationException::withMessages(['start_time' => 'Open play is full for the selected time.']);
                }
                $usesOpenPlayPrice = $usesOpenPlayPrice || $event !== null;
                $slotPrices[] = $event ? (float) $event->price_per_player : ($rate === null ? null : round(
                    $settings->applyPeakAdjustment((float) $rate, $cursor->hour) * ($slotMinutes / 60),
                    2
                ));
                $cursor = $slotEnd;
            }
            $priced = ! in_array(null, $slotPrices, true);
            if (! $priced && ! array_key_exists('amount', $validated)) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount because this court range could not be priced automatically.']);
            }

            $total = $priced ? round(array_sum($slotPrices), 2) : (float) $validated['amount'];
            if (! $usesOpenPlayPrice && array_key_exists('amount', $validated) && $validated['amount'] !== null) $total = (float) $validated['amount'];

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

            for ($cursor = $start, $index = 0; $cursor->lessThan($end); $index++) {
                $slotEnd = $cursor->addMinutes($step);
                if ($slotEnd->greaterThan($end)) $slotEnd = $end;
                $booking->slots()->create([
                    'court_id' => $court->id,
                    'date' => $cursor->toDateString(),
                    'start_time' => $cursor->format('H:i:s'),
                    'end_time' => $slotEnd->format('H:i:s'),
                    'price' => $slotPrices[$index] ?? 0,
                ]);
                $cursor = $slotEnd;
            }

            return ['booking' => $booking->load(['court', 'slots']), 'warnings' => $warnings];
        });

        /** @var Booking $booking */
        $booking = $result['booking'];
        ActivityLogger::log(
            'booking.walk_in_created',
            sprintf('%s recorded a walk-in booking for %s on %s.', auth()->user()->name, $booking->customer_name, $booking->date->format('M j, Y')),
            subject: $booking,
            properties: $booking->only(['court_id', 'date', 'start_time', 'end_time', 'amount', 'payment_method', 'source']),
        );
        $overlappedItemIds = collect($result['warnings'])->filter(fn ($warning) => str_starts_with($warning['code'], 'overlaps_'))
            ->flatMap(fn ($warning) => $warning['item_ids'])->unique()->values()->all();
        ActivityLogger::log(
            'calendar.admin_override',
            sprintf('%s created walk-in booking #%s with admin override rules.', auth()->user()->name, $booking->id),
            subject: $booking,
            properties: [
                'admin_override' => true, 'booking_id' => $booking->id, 'warnings' => $result['warnings'],
                'old_time' => null,
                'new_time' => ['date' => $booking->date->toDateString(), 'start' => substr((string) $booking->start_time, 0, 5), 'end' => substr((string) $booking->end_time, 0, 5)],
                'old_amount' => null, 'new_amount' => (float) $booking->amount, 'overlapped_item_ids' => $overlappedItemIds,
            ],
        );

        return response()->json([
            'message' => 'Walk-in booking created.',
            'admin_override' => true,
            'warnings' => $result['warnings'],
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
