<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Models\SpecificDateTimeClosure;
use App\Support\BookingHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Builds warnings for admin-only calendar overrides. It never rejects a save. */
class AdminCalendarOverrideWarnings
{
    public function forInterval(
        int $courtId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $ignoreBookingId = null,
        ?int $ignoreBlockId = null
    ): array {
        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse("{$date} {$startTime}", $timezone);
        $end = CarbonImmutable::parse("{$date} {$endTime}", $timezone);
        if ($end->lessThanOrEqualTo($start)) $end = $end->addDay();

        $warnings = [];
        $add = static function (string $code, string $message, $id = null) use (&$warnings): void {
            $warnings[$code] ??= ['code' => $code, 'message' => $message, 'item_ids' => []];
            if ($id !== null && ! in_array((int) $id, $warnings[$code]['item_ids'], true)) {
                $warnings[$code]['item_ids'][] = (int) $id;
            }
        };

        $step = BookingHours::stepMinutes();
        if ($start->minute % $step !== 0 || $end->minute % $step !== 0) {
            $add('outside_step', "Outside the usual {$step}-minute booking step");
        }

        $venueNow = CarbonImmutable::now('Asia/Manila');
        if ($start->toDateString() === $venueNow->toDateString() && $start->lessThan($venueNow)) {
            $add('past_time', 'Starts earlier than the current venue time');
        }

        $from = $start->startOfDay()->subDay();
        $through = $end->subMicrosecond()->startOfDay();
        $specificClosures = SpecificDateTimeClosure::query()
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $through->toDateString())
            ->get()->keyBy(fn ($closure) => $closure->date->toDateString());

        $openHour = BookingHours::openHour();
        $closeHour = BookingHours::closeHour();
        $openAt = $start->startOfDay()->setTime($openHour, 0);
        $fitsHours = false;
        $closingMessage = null;
        for ($operatingDate = $from; $operatingDate->lessThanOrEqualTo($through); $operatingDate = $operatingDate->addDay()) {
            $windowOpen = $operatingDate->setTime($openHour, 0);
            $specific = $specificClosures->get($operatingDate->toDateString());
            $closeMinutes = $specific
                ? ((int) substr((string) $specific->time_closing, 0, 2) * 60 + (int) substr((string) $specific->time_closing, 3, 2))
                : ($closeHour === 0 ? 1440 : $closeHour * 60);
            $windowClose = $operatingDate->startOfDay()->addMinutes($closeMinutes);
            if ($windowClose->lessThanOrEqualTo($windowOpen)) $windowClose = $windowClose->addDay();
            if ($start->greaterThanOrEqualTo($windowOpen) && $end->lessThanOrEqualTo($windowClose)) {
                $fitsHours = true;
                break;
            }
            if ($start->greaterThanOrEqualTo($windowOpen) && $start->lessThan($windowClose) && $end->greaterThan($windowClose)) {
                $closingMessage = 'Past closing time ('.$windowClose->format('g:i A').')';
            }
        }
        if (! $fitsHours) {
            if ($closingMessage) $add('past_closing', $closingMessage);
            elseif ($start->lessThan($openAt)) $add('before_opening', 'Before opening time ('.$openAt->format('g:i A').')');
            else $add('outside_business_hours', 'Outside business hours');
        }

        for ($day = $start->startOfDay(); $day->lessThanOrEqualTo($through); $day = $day->addDay()) {
            $dayStart = $day->startOfDay();
            $dayEnd = $dayStart->addDay();
            if (! ($start->lessThan($dayEnd) && $end->greaterThan($dayStart))) continue;

            if (in_array($day->dayOfWeek, BookingHours::closedWeekdays(), true)) {
                $add('overlaps_closure', 'Overlaps a closed weekday');
            }
            $closure = CourtClosure::query()->whereDate('date', $day->toDateString())
                ->where(fn ($query) => $query->whereNull('court_id')->orWhere('court_id', $courtId))->first();
            if ($closure) $add('overlaps_closure', 'Overlaps a court closure', $closure->id);
            $specific = $specificClosures->get($day->toDateString());
            if ($specific) {
                $cutoffMinutes = (int) substr((string) $specific->time_closing, 0, 2) * 60
                    + (int) substr((string) $specific->time_closing, 3, 2);
                $cutoff = $dayStart->addMinutes($cutoffMinutes);
                if ($start->lessThan($dayStart->addDay()) && $end->greaterThan($cutoff)) {
                    $add('past_closing', 'Past closing time ('.$cutoff->format('g:i A').')', $specific->id);
                }
            }
        }

        $blocks = CourtBlock::query()
            ->where(fn ($query) => $query->whereNull('court_id')->orWhere('court_id', $courtId))
            ->when($ignoreBlockId !== null, fn ($query) => $query->where('id', '!=', $ignoreBlockId))
            ->whereDate('block_date', '>=', $from->toDateString())->whereDate('block_date', '<=', $through->toDateString())
            ->get();
        foreach ($blocks as $block) {
            $blockStart = CarbonImmutable::parse($block->block_date->toDateString().' '.$block->start_time, $timezone);
            $blockEnd = CarbonImmutable::parse($block->block_date->toDateString().' '.$block->end_time, $timezone);
            if ($blockEnd->lessThanOrEqualTo($blockStart)) $blockEnd = $blockEnd->addDay();
            if (! ($start->lessThan($blockEnd) && $end->greaterThan($blockStart))) continue;
            [$code, $message] = match ($block->type) {
                'maintenance' => ['overlaps_maintenance', 'Overlaps a maintenance block'],
                'open_play' => ['overlaps_open_play', 'Overlaps an open play session'],
                default => ['overlaps_closure', 'Overlaps a closure'],
            };
            $add($code, $message, $block->id);
        }

        $slots = DB::table('booking_slots')->join('bookings', 'bookings.id', '=', 'booking_slots.booking_id')
            ->where('booking_slots.court_id', $courtId)->where('booking_slots.is_active', true)
            ->whereNotIn('bookings.status', ['cancelled', 'expired'])
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('bookings.id', '!=', $ignoreBookingId))
            ->whereDate('booking_slots.date', '>=', $from->toDateString())->whereDate('booking_slots.date', '<=', $through->toDateString())
            ->get(['bookings.id as booking_id', 'booking_slots.date', 'booking_slots.start_time', 'booking_slots.end_time']);
        foreach ($slots as $slot) {
            $slotDate = substr((string) $slot->date, 0, 10);
            $slotStart = CarbonImmutable::parse($slotDate.' '.$slot->start_time, $timezone);
            $slotEnd = CarbonImmutable::parse($slotDate.' '.$slot->end_time, $timezone);
            if ($slotEnd->lessThanOrEqualTo($slotStart)) $slotEnd = $slotEnd->addDay();
            if ($start->lessThan($slotEnd) && $end->greaterThan($slotStart)) {
                $add('overlaps_booking', 'Overlaps another booking', $slot->booking_id);
            }
        }

        $legacy = Booking::query()->where('court_id', $courtId)->whereNotIn('status', ['cancelled', 'expired'])
            ->whereDoesntHave('slots')->when($ignoreBookingId !== null, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $through->toDateString())->get();
        foreach ($legacy as $booking) {
            $bookingDate = substr((string) $booking->date, 0, 10);
            $bookingStart = CarbonImmutable::parse($bookingDate.' '.$booking->start_time, $timezone);
            $bookingEnd = CarbonImmutable::parse($bookingDate.' '.$booking->end_time, $timezone);
            if ($bookingEnd->lessThanOrEqualTo($bookingStart)) $bookingEnd = $bookingEnd->addDay();
            if ($start->lessThan($bookingEnd) && $end->greaterThan($bookingStart)) {
                $add('overlaps_booking', 'Overlaps another booking', $booking->id);
            }
        }

        return array_values($warnings);
    }
}
