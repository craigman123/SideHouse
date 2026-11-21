<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Models\SpecificDateTimeClosure;
use App\Support\BookingHours;
use Carbon\CarbonImmutable;

class CourtConflictService
{
    /**
     * Return the first conflicting item for the requested court interval.
     * Call this from inside a transaction after locking the court row.
     */
    public function findConflict(
        int $courtId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $ignoreBookingId = null,
        ?int $ignoreBlockId = null,
        bool $includeBookingConflicts = true
    ): ?array {
        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse("{$date} {$startTime}", $timezone);
        $end = CarbonImmutable::parse("{$date} {$endTime}", $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        $firstDate = $start->startOfDay();
        $lastDate = $end->subMicrosecond()->startOfDay();
        $segments = [];
        for ($cursor = $firstDate; $cursor->lessThanOrEqualTo($lastDate); $cursor = $cursor->addDay()) {
            $segmentStart = $cursor->greaterThan($start) ? $cursor : $start;
            $nextDay = $cursor->addDay();
            $segmentEnd = $nextDay->lessThan($end) ? $nextDay : $end;
            $segments[] = [
                'date' => $cursor->toDateString(),
                'start' => $segmentStart->format('H:i:s'),
                'end' => $segmentEnd->format('H:i:s'),
            ];
        }

        $withinOperatingHours = false;
        for ($operatingDate = $firstDate->subDay(); $operatingDate->lessThanOrEqualTo($lastDate); $operatingDate = $operatingDate->addDay()) {
            $openAt = $operatingDate->setTime(BookingHours::openHour(), 0);
            $closeMinutes = BookingHours::closeHourForDate($operatingDate->toDateString());
            $closeAt = $operatingDate->startOfDay()->addMinutes($closeMinutes);
            if ($closeAt->lessThanOrEqualTo($openAt)) {
                $closeAt = $closeAt->addDay();
            }
            if ($start->getTimestamp() >= $openAt->getTimestamp()
                && $end->getTimestamp() <= $closeAt->getTimestamp()) {
                $withinOperatingHours = true;
                break;
            }
        }

        if (! $withinOperatingHours) {
            $specificClosure = SpecificDateTimeClosure::whereDate('date', $date)->first();
            return [
                'type' => $specificClosure ? 'specific_date_closure' : 'outside_business_hours',
                'id' => $specificClosure?->id,
                'title' => $specificClosure ? 'Specific date closing time' : 'Outside business hours',
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ];
        }

        foreach ($segments as $segment) {
            if (BookingHours::isClosed($courtId, $segment['date'])) {
                $closure = BookingHours::closureFor($courtId, $segment['date']);
                return [
                    'type' => $closure ? 'court_closure' : 'closed_weekday',
                    'id' => $closure?->id,
                    'title' => $closure?->reason ?: BookingHours::closedReason($courtId, $segment['date']),
                    'date' => $segment['date'],
                    'start_time' => $segment['start'],
                    'end_time' => $segment['end'],
                ];
            }

            $cutoffMinutes = BookingHours::closeHourForDate($segment['date']);
            $segmentEndMinutes = $segment['end'] === '00:00:00'
                ? 1440
                : ((int) substr($segment['end'], 0, 2) * 60 + (int) substr($segment['end'], 3, 2));
            $openMinutes = BookingHours::openHour() * 60;
            $cutoffWrapsOvernight = $cutoffMinutes <= $openMinutes;
            $outsideCutoff = $cutoffWrapsOvernight
                ? ($segmentEndMinutes > $cutoffMinutes && $segmentEndMinutes < $openMinutes)
                : ($segmentEndMinutes > $cutoffMinutes);

            if ($outsideCutoff) {
                $specificClosure = SpecificDateTimeClosure::whereDate('date', $segment['date'])->first();
                return [
                    'type' => $specificClosure ? 'specific_date_closure' : 'outside_business_hours',
                    'id' => $specificClosure?->id,
                    'title' => $specificClosure ? 'Specific date closing time' : 'Outside business hours',
                    'date' => $segment['date'],
                    'start_time' => $segment['start'],
                    'end_time' => $segment['end'],
                ];
            }

            $slot = $includeBookingConflicts ? BookingSlot::query()
                ->where('court_id', $courtId)
                ->whereDate('date', $segment['date'])
                ->where('is_active', true)
                ->where('start_time', '<', $segment['end'])
                ->where('end_time', '>', $segment['start'])
                ->whereHas('booking', function ($query) use ($ignoreBookingId) {
                    $query->whereNotIn('status', ['cancelled', 'expired']);
                    if ($ignoreBookingId !== null) {
                        $query->where('id', '!=', $ignoreBookingId);
                    }
                })
                ->with('booking')
                ->first() : null;

            if ($slot) {
                return [
                    'type' => 'booking',
                    'id' => $slot->booking_id,
                    'title' => $slot->booking?->customer_name ?? 'Existing booking',
                    'date' => $segment['date'],
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                ];
            }

            $segmentStart = CarbonImmutable::parse($segment['date'] . ' ' . $segment['start'], $timezone);
            $segmentEnd = CarbonImmutable::parse($segment['date'] . ' ' . $segment['end'], $timezone);
            if ($segmentEnd->lessThanOrEqualTo($segmentStart)) {
                $segmentEnd = $segmentEnd->addDay();
            }

            $legacyBookings = $includeBookingConflicts ? Booking::query()
                ->where('court_id', $courtId)
                ->whereDate('date', '>=', CarbonImmutable::parse($segment['date'], $timezone)->subDay()->toDateString())
                ->whereDate('date', '<=', $segment['date'])
                ->whereNotIn('status', ['cancelled', 'expired'])
                ->whereDoesntHave('slots')
                ->when($ignoreBookingId !== null, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
                ->get() : collect();

            foreach ($legacyBookings as $legacyBooking) {
                $legacyDate = $legacyBooking->date?->toDateString() ?? (string) $legacyBooking->getRawOriginal('date');
                $legacyStart = CarbonImmutable::parse($legacyDate . ' ' . $legacyBooking->start_time, $timezone);
                $legacyEnd = CarbonImmutable::parse($legacyDate . ' ' . $legacyBooking->end_time, $timezone);
                if ($legacyEnd->lessThanOrEqualTo($legacyStart)) {
                    $legacyEnd = $legacyEnd->addDay();
                }

                if ($segmentStart->lessThan($legacyEnd) && $segmentEnd->greaterThan($legacyStart)) {
                    return [
                        'type' => 'booking',
                        'id' => $legacyBooking->id,
                        'title' => $legacyBooking->customer_name,
                        'date' => $legacyDate,
                        'start_time' => $legacyBooking->start_time,
                        'end_time' => $legacyBooking->end_time,
                    ];
                }
            }
        }

        $blocks = CourtBlock::query()
            ->where(function ($query) use ($courtId) {
                $query->whereNull('court_id')->orWhere('court_id', $courtId);
            })
            ->when($ignoreBlockId !== null, fn ($query) => $query->where('id', '!=', $ignoreBlockId))
            ->whereDate('block_date', '>=', $firstDate->subDay()->toDateString())
            ->whereDate('block_date', '<=', $lastDate->toDateString())
            ->get();

        foreach ($blocks as $block) {
            $blockStart = CarbonImmutable::parse($block->block_date->toDateString() . ' ' . $block->start_time, $timezone);
            $blockEnd = CarbonImmutable::parse($block->block_date->toDateString() . ' ' . $block->end_time, $timezone);
            if ($blockEnd->lessThanOrEqualTo($blockStart)) {
                $blockEnd = $blockEnd->addDay();
            }

            if ($start->lessThan($blockEnd) && $end->greaterThan($blockStart)) {
                return [
                    'type' => $block->type,
                    'id' => $block->id,
                    'title' => $block->title,
                    'date' => $block->block_date->toDateString(),
                    'start_time' => $block->start_time,
                    'end_time' => $block->end_time,
                ];
            }
        }

        $closures = CourtClosure::query()
            ->whereDate('date', '>=', $firstDate->toDateString())
            ->whereDate('date', '<=', $lastDate->toDateString())
            ->where(function ($query) use ($courtId) {
                $query->whereNull('court_id')->orWhere('court_id', $courtId);
            })
            ->first();

        if ($closures) {
            return [
                'type' => 'court_closure',
                'id' => $closures->id,
                'title' => $closures->reason ?: 'Court closed',
                'date' => $closures->date->toDateString(),
                'start_time' => null,
                'end_time' => null,
            ];
        }

        return null;
    }
}
