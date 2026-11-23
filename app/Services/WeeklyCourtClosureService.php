<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\Court;
use App\Models\WeeklyCourtClosure;
use App\Support\BookingHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WeeklyCourtClosureService
{
    public function validateWeekdays(array $weekdays): array
    {
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        if (! $weekdays || collect($weekdays)->contains(fn ($day) => $day < 0 || $day > 6)) {
            throw ValidationException::withMessages(['weekdays' => 'Select at least one valid weekday.']);
        }
        $allDayClosed = BookingHours::closedWeekdays();
        $closed = array_values(array_intersect($weekdays, $allDayClosed));
        if ($closed) {
            $names = collect($closed)->map(fn ($day) => CarbonImmutable::parse('2024-01-07', 'Asia/Manila')->addDays($day)->format('l'))->implode(', ');
            throw ValidationException::withMessages(['weekdays' => "These days are closed all day in Configuration: {$names}."]);
        }
        return $weekdays;
    }

    public function validateHours(string $startTime, string $endTime): array
    {
        $settings = BookingHours::settings();
        $openMinute = (int) $settings->open_hour * 60;
        $closeMinute = (int) $settings->close_hour * 60;
        $overnight = (int) $settings->close_hour <= (int) $settings->open_hour;
        $endOffset = $closeMinute + ($overnight ? 1440 : 0);
        $rawStart = $this->toMinutes($startTime);
        $rawEnd = $this->toMinutes($endTime);
        if ($rawEnd <= $rawStart) throw ValidationException::withMessages(['end_time' => 'End time must be later than start time.']);
        $startAllowed = $overnight ? ($rawStart >= $openMinute || $rawStart <= $closeMinute) : ($rawStart >= $openMinute && $rawStart < $endOffset);
        $endAllowed = $overnight ? ($rawEnd > $openMinute || $rawEnd <= $closeMinute) : ($rawEnd > $openMinute && $rawEnd <= $endOffset);
        if (! $startAllowed) throw ValidationException::withMessages(['start_time' => 'The closure cannot start outside configured operating hours.']);
        if (! $endAllowed) throw ValidationException::withMessages(['end_time' => 'The closure must end within configured operating hours.']);
        $start = $overnight && $rawStart < $openMinute ? $rawStart + 1440 : $rawStart;
        $end = $overnight && $rawEnd <= $closeMinute ? $rawEnd + 1440 : $rawEnd;
        if ($end <= $start) throw ValidationException::withMessages(['end_time' => 'End time must be later than start time.']);
        $step = max(1, BookingHours::stepMinutes());
        $endFromOpen = ($end - $openMinute) % 1440;
        if (($start - $openMinute) % $step !== 0 || $endFromOpen % $step !== 0) {
            throw ValidationException::withMessages(['start_time' => "Choose start and end times on the {$step}-minute booking step."]);
        }
        return [$start, $end];
    }

    public function validateHoursForWeekdays(array $weekdays, string $startTime, string $endTime): array
    {
        $weekdays = $this->validateWeekdays($weekdays);
        $hours = BookingHours::operatingHoursByWeekday();
        $window = $this->sharedOperatingWindow($weekdays, $hours);
        $selectedHours = $window['days'];
        if (count($selectedHours) !== count($weekdays)) {
            throw ValidationException::withMessages(['weekdays' => 'A selected weekday is closed all day.']);
        }
        if ($window['open'] === null || $window['close'] <= $window['open']) {
            throw ValidationException::withMessages(['weekdays' => 'The selected weekdays have no shared operating time.']);
        }

        $start = $this->toMinutes($startTime);
        $end = $this->toMinutes($endTime);
        if (BookingHours::isOvernight()) {
            if ($start < $window['open']) $start += 1440;
            if ($end <= $window['open']) $end += 1440;
        }
        if ($start < $window['open'] || $start >= $window['close']) {
            throw ValidationException::withMessages(['start_time' => 'The closure cannot start outside the shared operating hours.']);
        }
        if ($end <= $start || $end > $window['close']) {
            throw ValidationException::withMessages(['end_time' => 'The closure must end within the shared operating hours and after its start.']);
        }
        $step = max(1, BookingHours::stepMinutes());
        if (($start - $window['open']) % $step !== 0 || ($end - $window['open']) % $step !== 0) {
            throw ValidationException::withMessages(['start_time' => "Choose start and end times on the {$step}-minute booking step."]);
        }
        return [$start, $end];
    }

    /** Intersection uses the same Sunday=0 weekday keys sent by the browser. */
    public function sharedOperatingWindow(array $weekdays, array $hours): array
    {
        $days = array_values(array_filter($hours, fn (array $day) => in_array((int) $day['weekday'], $weekdays, true) && empty($day['closed_all_day'])));
        return ['days' => $days,
            'open' => $days ? max(array_column($days, 'open_minutes')) : null,
            'close' => $days ? min(array_column($days, 'close_offset')) : null,
            'closed' => array_values(array_filter($hours, fn (array $day) => in_array((int) $day['weekday'], $weekdays, true) && ! empty($day['closed_all_day']))),
        ];
    }

    public function lockCourts(?int $courtId): array
    {
        $query = Court::query()->orderBy('id');
        if ($courtId !== null) $query->whereKey($courtId);
        $ids = $query->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! $ids) throw ValidationException::withMessages(['court_id' => 'No courts are available.']);
        return $ids;
    }

    public function overlappingRule(array $weekdays, int $start, int $end, ?int $courtId, ?int $ignoreId = null): ?WeeklyCourtClosure
    {
        return WeeklyCourtClosure::query()->where('active', true)->whereIn('weekday', $weekdays)
            ->where(function ($query) use ($courtId) {
                if ($courtId === null) $query->whereNotNull('id');
                else $query->whereNull('court_id')->orWhere('court_id', $courtId);
            })
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get()->first(function (WeeklyCourtClosure $rule) use ($start, $end) {
                [$otherStart, $otherEnd] = $this->validateHours(substr((string) $rule->start_time, 0, 5), substr((string) $rule->end_time, 0, 5));
                return $start < $otherEnd && $end > $otherStart;
            });
    }

    public function upcomingBookingWarnings(array $weekdays, string $startTime, string $endTime, ?int $courtId): array
    {
        $startMinute = $this->toMinutes($startTime);
        $endMinute = $this->toMinutes($endTime);
        $courtIds = $courtId === null ? Court::query()->pluck('id')->map(fn ($id) => (int) $id)->all() : [$courtId];
        $courtNames = Court::query()->whereIn('id', $courtIds)->pluck('name', 'id');
        if (! $courtIds) return [];
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
        $through = $today->addDays(60);
        $warnings = [];
        $seen = [];
        $slotsByDate = BookingSlot::query()->with('booking')->whereIn('court_id', $courtIds)
            ->whereDate('date', '>=', $today->toDateString())->whereDate('date', '<=', $through->toDateString())
            ->whereHas('booking', fn ($query) => $query->whereNotIn('status', ['cancelled', 'expired']))->get()
            ->groupBy(fn (BookingSlot $slot) => $slot->date?->toDateString() ?? substr((string) $slot->getRawOriginal('date'), 0, 10));
        $legacyByDate = Booking::query()->whereIn('court_id', $courtIds)->whereDate('date', '>=', $today->toDateString())
            ->whereDate('date', '<=', $through->toDateString())->whereNotIn('status', ['cancelled', 'expired'])
            ->whereDoesntHave('slots')->get()->groupBy(fn (Booking $booking) => $booking->date?->toDateString() ?? substr((string) $booking->getRawOriginal('date'), 0, 10));
        for ($day = $today; $day->lessThanOrEqualTo($through); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeek, $weekdays, true)) continue;
            $rangeStart = $day->addMinutes($startMinute);
            $rangeEnd = $day->startOfDay()->addMinutes($endMinute);
            if ($rangeEnd->lessThanOrEqualTo($rangeStart)) $rangeEnd = $rangeEnd->addDay();
            if ($rangeEnd->lessThanOrEqualTo(CarbonImmutable::now('Asia/Manila'))) continue;
            foreach ($slotsByDate->get($day->toDateString(), collect()) as $slot) {
                $booking = $slot->booking;
                if (! $booking) continue;
                $slotDate = $slot->date?->toDateString() ?? substr((string) $slot->getRawOriginal('date'), 0, 10);
                $slotStart = CarbonImmutable::parse($slotDate.' '.$slot->start_time, 'Asia/Manila');
                $slotEnd = CarbonImmutable::parse($slotDate.' '.$slot->end_time, 'Asia/Manila');
                if ($slotEnd->lessThanOrEqualTo($slotStart)) $slotEnd = $slotEnd->addDay();
                if (! ($rangeStart->lessThan($slotEnd) && $rangeEnd->greaterThan($slotStart))
                    || $slotEnd->lessThanOrEqualTo(CarbonImmutable::now('Asia/Manila'))) continue;
                $key = 'booking:'.$booking->id;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $warnings[] = $this->warning($booking, (int) $slot->court_id, $slotDate, $slot->start_time, $slot->end_time, $courtNames);
            }
            foreach ($legacyByDate->get($day->toDateString(), collect()) as $booking) {
                $bookingDate = $booking->date?->toDateString() ?? substr((string) $booking->getRawOriginal('date'), 0, 10);
                $bookingStart = CarbonImmutable::parse($bookingDate.' '.$booking->start_time, 'Asia/Manila');
                $bookingEnd = CarbonImmutable::parse($bookingDate.' '.$booking->end_time, 'Asia/Manila');
                if ($bookingEnd->lessThanOrEqualTo($bookingStart)) $bookingEnd = $bookingEnd->addDay();
                if (! ($rangeStart->lessThan($bookingEnd) && $rangeEnd->greaterThan($bookingStart))
                    || $bookingEnd->lessThanOrEqualTo(CarbonImmutable::now('Asia/Manila'))) continue;
                $key = 'booking:'.$booking->id;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $warnings[] = $this->warning($booking, (int) $booking->court_id, $bookingDate, $booking->start_time, $booking->end_time, $courtNames);
            }
        }
        return $warnings;
    }

    private function warning(Booking $booking, int $courtId, string $date, string $start, string $end, Collection $courtNames): array
    {
        return ['customer' => $booking->customer_name ?: 'Existing booking', 'court' => $courtNames[$courtId] ?? 'Court '.$courtId,
            'date' => $date, 'start_time' => substr((string) $start, 0, 5), 'end_time' => substr((string) $end, 0, 5), 'status' => ucfirst((string) $booking->status)];
    }

    private function toMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
        return $hour * 60 + $minute;
    }
}
