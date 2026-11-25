<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSlot;

class BookingAvailabilityService
{
    public function forCourtDate(int $courtId, string $date): array
    {
        $slots = BookingSlot::query()
            ->whereDate('date', $date)
            ->whereHas('booking', fn ($query) => $query
                ->where('court_id', $courtId)
                ->where('status', '!=', 'cancelled'))
            ->get(['start_time', 'end_time']);

        $booked = $slots->map(fn (BookingSlot $slot) => [
            'start' => substr((string) $slot->start_time, 0, 5),
            'end' => substr((string) $slot->end_time, 0, 5),
        ])->all();

        $legacyBookings = Booking::query()
            ->where('court_id', $courtId)
            ->whereDate('date', $date)
            ->where('status', '!=', 'cancelled')
            ->whereDoesntHave('slots')
            ->get(['start_time', 'end_time']);

        foreach ($legacyBookings as $booking) {
            $booked[] = [
                'start' => substr((string) $booking->start_time, 0, 5),
                'end' => substr((string) $booking->end_time, 0, 5),
            ];
        }

        return [
            'booked' => $booked,
            'open_play' => app(OpenPlayAvailability::class)->forDate($courtId, $date),
            'closed_blocks' => app(CourtBlockClosureAvailability::class)->closedRangesForDate($courtId, $date),
        ];
    }
}
