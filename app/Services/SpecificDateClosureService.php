<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Models\SpecificDateTimeClosure;
use App\Support\BookingHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SpecificDateClosureService
{
    public function intervalForDate(string $date, string $startTime, string $endTime): array
    {
        $settings = BookingHours::settings();
        $open = CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay()->setTime($settings->open_hour, 0);
        $closeMinutes = BookingHours::closeHourForDate($date);
        $overnight = $closeMinutes <= ((int) $settings->open_hour * 60);
        $close = CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay()->addMinutes($closeMinutes);
        if ($overnight) $close = $close->addDay();

        $dateStart = CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay();
        $start = $dateStart->addMinutes($this->timeMinutes($startTime));
        $end = $dateStart->addMinutes($this->timeMinutes($endTime));
        if ($end->equalTo($start)) {
            throw ValidationException::withMessages(['end_time' => 'End time must be after start time.']);
        }
        if ($end->lessThan($start)) {
            if ($overnight) $end = $end->addDay();
            else throw ValidationException::withMessages(['end_time' => 'End time must be after start time.']);
        }

        if ($start->lessThan($open)) {
            throw ValidationException::withMessages(['start_time' => 'The closure cannot start before the court opens at '.$open->format('g:i A').'.']);
        }
        if ($end->greaterThan($close)) {
            throw ValidationException::withMessages(['end_time' => 'The closure must end by the court closing time of '.$close->format('g:i A').'.']);
        }

        $step = max(1, BookingHours::stepMinutes());
        if (($start->getTimestamp() - $open->getTimestamp()) % ($step * 60) !== 0
            || ($end->getTimestamp() - $open->getTimestamp()) % ($step * 60) !== 0) {
            throw ValidationException::withMessages(['start_time' => "Choose start and end times on the {$step}-minute booking step."]);
        }

        if ($date === CarbonImmutable::now('Asia/Manila')->toDateString()
            && $start->lessThan(CarbonImmutable::now('Asia/Manila'))) {
            throw ValidationException::withMessages(['start_time' => 'A specific closure cannot start in the past.']);
        }

        return [$start, $end];
    }

    public function validateRange(string $from, string $to): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Asia/Manila');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Asia/Manila');
        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
        }
        if ($start->diffInDays($end) > 30) {
            throw ValidationException::withMessages(['end_date' => 'A closure range cannot exceed 31 dates.']);
        }
        if ($start->lessThan(CarbonImmutable::now('Asia/Manila')->startOfDay())) {
            throw ValidationException::withMessages(['start_date' => 'Past dates cannot be closed.']);
        }

        $dates = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /** @return array<int, array<string, mixed>> */
    public function conflicts(array $courtIds, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreBlockId = null): array
    {
        $conflicts = [];
        $seenBookings = [];
        $from = $start->startOfDay()->subDay()->toDateString();
        $through = $end->startOfDay()->toDateString();
        $courtNames = Court::query()->whereIn('id', $courtIds)->pluck('name', 'id');

        $slotRows = BookingSlot::query()->with('booking')
            ->whereIn('court_id', $courtIds)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $through)
            ->where('is_active', true)
            ->whereHas('booking', fn ($query) => $query->whereNotIn('status', ['cancelled', 'expired']))
            ->get();
        foreach ($slotRows as $slot) {
            $booking = $slot->booking;
            if (! $booking || isset($seenBookings[$booking->id])) continue;
            $slotDate = $slot->date?->toDateString() ?? substr((string) $slot->getRawOriginal('date'), 0, 10);
            $bookingStart = CarbonImmutable::parse($slotDate.' '.$slot->start_time, 'Asia/Manila');
            $bookingEnd = CarbonImmutable::parse($slotDate.' '.$slot->end_time, 'Asia/Manila');
            if ($bookingEnd->lessThanOrEqualTo($bookingStart)) $bookingEnd = $bookingEnd->addDay();
            if (! $this->overlaps($start, $end, $bookingStart, $bookingEnd)) continue;

            $seenBookings[$booking->id] = true;
            $conflicts[] = $this->bookingConflict($booking, (int) $slot->court_id, $slotDate, $slot->start_time, $slot->end_time, $courtNames);
        }

        $legacyBookings = Booking::query()->whereIn('court_id', $courtIds)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $through)
            ->whereNotIn('status', ['cancelled', 'expired'])->whereDoesntHave('slots')->get();
        foreach ($legacyBookings as $booking) {
            if (isset($seenBookings[$booking->id])) continue;
            $date = $booking->date?->toDateString() ?? substr((string) $booking->getRawOriginal('date'), 0, 10);
            $bookingStart = CarbonImmutable::parse($date.' '.$booking->start_time, 'Asia/Manila');
            $bookingEnd = CarbonImmutable::parse($date.' '.$booking->end_time, 'Asia/Manila');
            if ($bookingEnd->lessThanOrEqualTo($bookingStart)) $bookingEnd = $bookingEnd->addDay();
            if (! $this->overlaps($start, $end, $bookingStart, $bookingEnd)) continue;

            $seenBookings[$booking->id] = true;
            $conflicts[] = $this->bookingConflict($booking, (int) $booking->court_id, $date, $booking->start_time, $booking->end_time, $courtNames);
        }

        $blocks = CourtBlock::query()->with('court')
            ->where(fn ($query) => $query->whereNull('court_id')->orWhereIn('court_id', $courtIds))
            ->whereIn('type', ['open_play', 'closed', 'maintenance'])
            ->when($ignoreBlockId !== null, fn ($query) => $query->where('id', '!=', $ignoreBlockId))
            ->whereDate('block_date', '>=', $from)->whereDate('block_date', '<=', $through)->get();
        foreach ($blocks as $block) {
            $blockStart = CarbonImmutable::parse($block->block_date->toDateString().' '.$block->start_time, 'Asia/Manila');
            $blockEnd = CarbonImmutable::parse($block->block_date->toDateString().' '.$block->end_time, 'Asia/Manila');
            if ($blockEnd->lessThanOrEqualTo($blockStart)) $blockEnd = $blockEnd->addDay();
            if (! $this->overlaps($start, $end, $blockStart, $blockEnd)) continue;

            $status = match ($block->type) {
                'open_play' => 'Open play',
                'maintenance' => 'Maintenance',
                default => 'Closed',
            };
            $conflicts[] = [
                'customer' => $block->title ?: $status,
                'court' => $block->court?->name ?? ($block->court_id ? ($courtNames[$block->court_id] ?? 'Court') : 'All courts'),
                'date' => $block->block_date->toDateString(),
                'start_time' => substr((string) $block->start_time, 0, 5),
                'end_time' => substr((string) $block->end_time, 0, 5),
                'status' => $status,
            ];
        }

        $fullDayClosures = CourtClosure::query()
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $through)
            ->where(fn ($query) => $query->whereNull('court_id')->orWhereIn('court_id', $courtIds))->get();
        foreach ($fullDayClosures as $closure) {
            $date = $closure->date->toDateString();
            $dayStart = CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay();
            if (! $this->overlaps($start, $end, $dayStart, $dayStart->addDay())) continue;
            $conflicts[] = [
                'customer' => $closure->reason ?: 'Court closed all day',
                'court' => $closure->court_id ? ($courtNames[$closure->court_id] ?? 'Court') : 'All courts',
                'date' => $date, 'start_time' => 'All day', 'end_time' => '', 'status' => 'Closed all day',
            ];
        }

        $weekdays = BookingHours::closedWeekdays();
        for ($day = $start->startOfDay(); $day->lessThanOrEqualTo($end->startOfDay()); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeek, $weekdays, true)) continue;
            $dayStart = $day->startOfDay();
            if (! $this->overlaps($start, $end, $dayStart, $dayStart->addDay())) continue;
            foreach ($courtIds as $courtId) {
                $conflicts[] = [
                    'customer' => 'Weekly closure',
                    'court' => $courtNames[$courtId] ?? 'Court',
                    'date' => $day->toDateString(), 'start_time' => 'All day', 'end_time' => '', 'status' => 'Closed weekday',
                ];
            }
        }

        return $conflicts;
    }

    public function lockCourts(?int $courtId): array
    {
        $query = Court::query()->orderBy('id');
        if ($courtId !== null) $query->whereKey($courtId);
        $courts = $query->lockForUpdate()->get(['id']);
        if ($courts->isEmpty()) {
            throw ValidationException::withMessages(['court_id' => 'No courts are available for this closure.']);
        }

        return $courts->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function bookingConflict(Booking $booking, int $courtId, string $date, string $start, string $end, $courtNames): array
    {
        return [
            'customer' => $booking->customer_name ?: 'Existing booking',
            'court' => $courtNames[$courtId] ?? 'Court '.$courtId,
            'date' => $date,
            'start_time' => substr((string) $start, 0, 5),
            'end_time' => substr((string) $end, 0, 5),
            'status' => ucfirst((string) $booking->status),
        ];
    }

    private function timeMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
        return $hour * 60 + $minute;
    }

    private function overlaps(CarbonImmutable $leftStart, CarbonImmutable $leftEnd, CarbonImmutable $rightStart, CarbonImmutable $rightEnd): bool
    {
        return $leftStart->lessThan($rightEnd) && $leftEnd->greaterThan($rightStart);
    }
}
