<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\CourtBlock;
use Illuminate\Support\Collection;

class OpenPlayAvailability
{
    public function forDate(int $courtId, string $date): array
    {
        return CourtBlock::query()->where('type', 'open_play')->whereDate('block_date', $date)
            ->where(fn ($q) => $q->whereNull('court_id')->orWhere('court_id', $courtId))
            ->orderBy('start_time')->get()->map(function (CourtBlock $event) use ($date, $courtId) {
                $start = substr((string) $event->start_time, 0, 5);
                $end = substr((string) $event->end_time, 0, 5);
                $joined = BookingSlot::query()->whereDate('date', $date)->where('start_time', '<', $end.':00')->where('end_time', '>', $start.':00')
                    ->whereHas('booking', fn ($q) => $q->where('court_id', $courtId)->where('status', '!=', 'cancelled'))
                    ->distinct('booking_id')->count('booking_id');
                $joined += Booking::query()->where('court_id', $courtId)->whereDate('date', $date)->where('status', '!=', 'cancelled')
                    ->whereDoesntHave('slots')->where('start_time', '<', $end.':00')->where('end_time', '>', $start.':00')->count();
                return [
                    'start' => $start, 'end' => $end, 'title' => $event->title,
                    'max_players' => (int) $event->max_players, 'price_per_player' => (float) $event->price_per_player,
                    'joined' => $joined,
                ];
            })->all();
    }

    public function eventForSlot(int $courtId, string $date, string $start, string $end): ?CourtBlock
    {
        return CourtBlock::query()->where('type', 'open_play')->whereDate('block_date', $date)
            ->where(fn ($q) => $q->whereNull('court_id')->orWhere('court_id', $courtId))
            ->where('start_time', '<=', $start)->where('end_time', '>=', $end)->first();
    }

    public function joined(CourtBlock $event, int $courtId): int
    {
        $date = $event->block_date->toDateString();
        $start = substr((string) $event->start_time, 0, 8);
        $end = substr((string) $event->end_time, 0, 8);
        $joined = BookingSlot::query()->whereDate('date', $date)->where('start_time', '<', $end)->where('end_time', '>', $start)
            ->whereHas('booking', fn ($q) => $q->where('court_id', $courtId)->where('status', '!=', 'cancelled'))
            ->distinct('booking_id')->count('booking_id');
        return $joined + Booking::query()->where('court_id', $courtId)->whereDate('date', $date)->where('status', '!=', 'cancelled')
            ->whereDoesntHave('slots')->where('start_time', '<', $end)->where('end_time', '>', $start)->count();
    }

    public function participants(CourtBlock $event, Collection $bookings): array
    {
        $date = $event->block_date->toDateString();
        $start = substr((string) $event->start_time, 0, 8);
        $end = substr((string) $event->end_time, 0, 8);

        $participants = [];

        foreach ($bookings as $booking) {
            if ($booking->status === 'cancelled') continue;
            if ($event->court_id !== null && (int) $booking->court_id !== (int) $event->court_id) continue;

            $matchedSlot = $booking->slots
                ->where('is_active', true)
                ->first(function (BookingSlot $slot) use ($date, $start, $end) {
                    return $slot->date?->toDateString() === $date
                        && (string) $slot->start_time < $end
                        && (string) $slot->end_time > $start;
                });

            $legacyMatch = $booking->slots->isEmpty()
                && $booking->date?->toDateString() === $date
                && (string) $booking->start_time < $end
                && (string) $booking->end_time > $start;

            if (!$matchedSlot && !$legacyMatch) continue;

            $paymentState = $booking->status === 'paid' ? 'Paid'
                : ($booking->status === 'pending' ? 'Pending' : ucfirst((string) $booking->status));

            $joinedAt = $matchedSlot?->created_at ?? $booking->created_at;
            $participants[$booking->id] = [
                'name' => $booking->customer_name ?: 'Player',
                'joined_time' => $joinedAt?->format('M j, Y g:i A'),
                'status' => $paymentState,
                'payment_reference_id' => $booking->payment_reference_id,
            ];
        }

        return array_values($participants);
    }
}
