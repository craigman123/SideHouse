<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\CourtBlock;

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
}
