<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\LumiSuggestionDismissal;
use App\Models\User;
use App\Support\BookingHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class LumiSuggestionService
{
    public function __construct(
        private readonly BookingAvailabilityService $availability,
        private readonly OpenPlayAvailability $openPlayAvailability,
        private readonly CourtBlockClosureAvailability $blockAvailability,
    ) {
    }

    public function forCustomer(User $customer): array
    {
        $cacheKey = $this->cacheKey((int) $customer->user_id);
        $cacheSeconds = max(0, (int) config('lumi.cache_seconds', 120));

        $suggestions = $cacheSeconds > 0
            ? Cache::remember($cacheKey, now()->addSeconds($cacheSeconds), fn () => $this->buildForCustomer($customer))
            : $this->buildForCustomer($customer);

        $dismissedKeys = LumiSuggestionDismissal::query()
            ->where('user_id', $customer->user_id)
            ->where('dismissed_until', '>', now())
            ->pluck('suggestion_key')
            ->all();

        return collect($suggestions)
            ->reject(fn (array $suggestion) => in_array($suggestion['key'], $dismissedKeys, true))
            ->take(max(0, (int) config('lumi.max_suggestions', 3)))
            ->values()
            ->all();
    }

    public function forgetForUser(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
    }

    private function buildForCustomer(User $customer): array
    {
        $timezone = (string) config('lumi.timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $lookback = max(0, (int) config('lumi.lookback_days', 90));
        $bookings = Booking::query()
            ->where('user_id', $customer->user_id)
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->whereDate('date', '>=', $today->subDays($lookback)->toDateString())
            ->get(['id', 'court_id', 'date', 'start_time', 'end_time', 'status']);

        $courts = Court::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'hourly_rate']);
        if ($courts->isEmpty()) {
            return [];
        }

        $rangesByCourtDate = [];
        $suggestions = [];
        $minimumBookings = max(1, (int) config('lumi.minimum_bookings', 2));
        if ($bookings->count() < $minimumBookings) {
            $openPlaySuggestions = $this->openPlaySuggestions(
                $today,
                $today->addDays($this->searchDays()),
                $courts,
                false,
                (int) $customer->user_id,
            );
            $maxSuggestions = max(1, (int) config('lumi.max_suggestions', 3));
            $suggestions = array_slice($openPlaySuggestions, 0, $maxSuggestions - 1);
            $firstSlot = $this->findAvailableSlots(
                $today,
                $today->addDays($this->searchDays()),
                $courts,
                false,
                1,
                $rangesByCourtDate,
            );
            if ($firstSlot !== []) {
                $suggestions[] = $this->slotSuggestion($firstSlot[0], 'first_booking', 10);
            }

            return $this->orderAndLimit($suggestions);
        }

        $sameDayCounts = $bookings
            ->groupBy(fn (Booking $booking) => $booking->date->toDateString())
            ->filter(fn ($dayBookings, string $date) => $date >= $today->toDateString() && $dayBookings->count() >= 2)
            ->sortKeys();
        foreach ($sameDayCounts as $date => $dayBookings) {
            $freeSlots = $this->findAvailableSlots(
                CarbonImmutable::parse($date, $timezone),
                CarbonImmutable::parse($date, $timezone),
                $courts,
                false,
                1,
                $rangesByCourtDate,
            );
            if ($freeSlots === []) {
                continue;
            }

            $slot = $freeSlots[0];
            $weekday = CarbonImmutable::parse($date, $timezone)->format('l');
            $timeLabel = $this->timeLabel($slot['time']);
            $suggestions[] = $this->slotSuggestion($slot, 'free_slot_same_day', 10, [
                'title' => 'A little more court time?',
                'message' => "You're booked twice on {$weekday}. {$timeLabel} is still open if you'd like another game.",
            ]);
            break;
        }

        $weekendRatio = $this->weekendShare($bookings, $timezone);
        $weekendOpenPlay = $this->openPlaySuggestions(
            $today,
            $today->addDays($this->searchDays()),
            $courts,
            true,
            (int) $customer->user_id,
        );
        $weekendHeavy = $weekendRatio >= (float) config('lumi.weekend_heavy_ratio', 0.6);
        if ($weekendHeavy) {
            $weekendOpenPlay = array_map(function (array $suggestion): array {
                $suggestion['priority'] = 15;
                return $suggestion;
            }, $weekendOpenPlay);
            $weekdayLimit = $weekendOpenPlay === [] ? 2 : 1;
            $weekdaySlots = $this->findAvailableSlots(
                $today,
                $today->addDays($this->searchDays()),
                $courts,
                true,
                $weekdayLimit,
                $rangesByCourtDate,
            );
            foreach ($weekdaySlots as $slot) {
                $suggestions[] = $this->slotSuggestion($slot, 'weekday_vacancy', 20, [
                    'title' => 'Try a weekday game',
                    'message' => 'A weekday court time is open if you feel like a quieter change of pace.',
                ]);
            }
        }
        $suggestions = [...$suggestions, ...$weekendOpenPlay];

        return $this->orderAndLimit($suggestions);
    }

    private function openPlaySuggestions(
        CarbonImmutable $from,
        CarbonImmutable $through,
        $courts,
        bool $weekendsOnly,
        int $customerId,
    ): array {
        $timezone = (string) config('lumi.timezone', 'Asia/Manila');
        $now = CarbonImmutable::now($timezone);
        $events = CourtBlock::query()
            ->where('type', 'open_play')
            ->whereDate('block_date', '>=', $from->toDateString())
            ->whereDate('block_date', '<=', $through->toDateString())
            ->orderBy('block_date')
            ->orderBy('start_time')
            ->get();
        $suggestions = [];

        foreach ($events as $event) {
            $date = $event->block_date->toDateString();
            $eventDate = CarbonImmutable::parse($date, $timezone);
            if ($weekendsOnly && ! in_array($eventDate->dayOfWeek, [CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY], true)) {
                continue;
            }

            [$start, $end] = $this->blockAvailability->blockInterval($event);
            if ($start->lessThanOrEqualTo($now->addHour())
                || ! $this->isWithinOperatingHours($start, $end)
                || $event->max_players === null
                || $event->price_per_player === null) {
                continue;
            }
            $participantBookings = $this->openPlayAvailability->participantBookings($event);
            if ($participantBookings->contains(fn (Booking $booking) => (int) $booking->user_id === $customerId)) {
                continue;
            }

            $eligibleCourts = $event->court_id === null
                ? $courts
                : $courts->where('id', (int) $event->court_id)->values();
            $bestChoice = null;
            foreach ($eligibleCourts as $court) {
                $lastOccupiedInstant = $end->subSecond();
                if (BookingHours::isClosed((int) $court->id, $date)
                    || BookingHours::isClosed((int) $court->id, $lastOccupiedInstant->toDateString())
                    || $this->blockAvailability->firstClosedOverlap((int) $court->id, $start, $end) !== null) {
                    continue;
                }

                $spotsLeft = (int) $event->max_players - $this->openPlayAvailability->joined($event, (int) $court->id);
                if ($spotsLeft <= 0) {
                    continue;
                }

                if ($bestChoice === null || $spotsLeft > $bestChoice['spots_left']) {
                    $bestChoice = [
                        'court' => $court,
                        'spots_left' => $spotsLeft,
                    ];
                }
            }

            if ($bestChoice === null) {
                continue;
            }

            $court = $bestChoice['court'];
            $time = substr((string) $event->start_time, 0, 5);
            $spotsLeft = $bestChoice['spots_left'];
            $suggestions[] = [
                'key' => 'open-play:'.$event->id.':'.$court->id,
                'type' => 'open_play',
                'title' => $event->title ?: 'Weekend Open Play',
                'message' => "Join the group for {$this->timeLabel($time)}. There are {$spotsLeft} real spot".($spotsLeft === 1 ? '' : 's')." left.",
                'details' => [
                    'date' => $date,
                    'time' => $time,
                    'court' => $court->name,
                    'court_id' => (int) $court->id,
                    'price' => (float) $event->price_per_player,
                    'spots_left' => $spotsLeft,
                ],
                'cta_label' => 'Join Open Play',
                'cta_url' => $this->bookingUrl($date, $time, (int) $court->id),
                'priority' => 30,
            ];
        }

        return $suggestions;
    }

    private function findAvailableSlots(
        CarbonImmutable $from,
        CarbonImmutable $through,
        $courts,
        bool $weekdaysOnly,
        int $limit,
        array &$rangesByCourtDate,
    ): array {
        $timezone = (string) config('lumi.timezone', 'Asia/Manila');
        $now = CarbonImmutable::now($timezone);
        $results = [];
        $stepMinutes = max(1, BookingHours::stepMinutes());
        $minimumSlots = max(1, (int) ceil(BookingHours::minDurationHours() * 60 / $stepMinutes));
        $openHour = BookingHours::openHour();
        $closeHour = BookingHours::closeHour();
        if ($openHour === 0 && $closeHour === 0) {
            $closeHour = 24;
        }
        $overnight = $closeHour <= $openHour;

        for ($date = $from->startOfDay(); $date->lessThanOrEqualTo($through->startOfDay()); $date = $date->addDay()) {
            if ($weekdaysOnly && in_array($date->dayOfWeek, [CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY], true)) {
                continue;
            }

            foreach ($courts as $court) {
                $courtId = (int) $court->id;
                if (BookingHours::isClosed($courtId, $date->toDateString())) {
                    continue;
                }

                $segments = [];
                if ($overnight && $closeHour > 0
                    && ! BookingHours::isClosed($courtId, $date->subDay()->toDateString())) {
                    $segments[] = [0, $closeHour * 60];
                }
                $segments[] = [$openHour * 60, ($overnight ? 1440 : $closeHour * 60)];

                foreach ($segments as [$segmentStart, $segmentEnd]) {
                    for ($minute = $segmentStart; $minute + $stepMinutes <= $segmentEnd; $minute += $stepMinutes) {
                        $candidate = $date->setTime(intdiv($minute, 60), $minute % 60);
                        if ($candidate->lessThanOrEqualTo($now->addHour())) {
                            continue;
                        }

                        $available = true;
                        for ($index = 0; $index < $minimumSlots; $index++) {
                            $slotStart = $candidate->addMinutes($index * $stepMinutes);
                            $slotEnd = $slotStart->addMinutes($stepMinutes);
                            if (! $this->isWithinOperatingHours($slotStart, $slotEnd)
                                || BookingHours::isClosed($courtId, $slotStart->toDateString())
                                || BookingHours::isClosed($courtId, $slotEnd->subSecond()->toDateString())
                                || ! $this->isSlotFree($courtId, $slotStart, $slotEnd, $rangesByCourtDate)) {
                                $available = false;
                                break;
                            }
                        }

                        if (! $available) {
                            continue;
                        }

                        $results[] = [
                            'date' => $candidate->toDateString(),
                            'time' => $candidate->format('H:i'),
                            'court_id' => $courtId,
                            'court' => $court->name,
                        ];
                        if (count($results) >= $limit) {
                            return $results;
                        }
                    }
                }
            }
        }

        return $results;
    }

    private function isSlotFree(int $courtId, CarbonImmutable $start, CarbonImmutable $end, array &$rangesByCourtDate): bool
    {
        $date = $start->toDateString();
        $key = $courtId.':'.$date;
        $rangesByCourtDate[$key] ??= $this->availability->forCourtDate($courtId, $date);
        $data = $rangesByCourtDate[$key];
        $startTime = $start->format('H:i');
        $endTime = $end->format('H:i');
        if ($endTime === '00:00') {
            $endTime = '24:00';
        }

        foreach ($data['booked'] as $range) {
            if ($this->overlaps($startTime, $endTime, $range['start'], $range['end'])) {
                return false;
            }
        }

        foreach ($data['closed_blocks'] as $range) {
            if ($this->overlaps($startTime, $endTime, $range['start'], $range['end'])) {
                return false;
            }
        }

        foreach ($data['open_play'] as $event) {
            if ($this->overlaps($startTime, $endTime, $event['start'], $event['end'])) {
                return false;
            }
        }

        return true;
    }

    private function isWithinOperatingHours(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $openHour = BookingHours::openHour();
        $closeHour = BookingHours::closeHour();
        if ($openHour === 0 && $closeHour === 0) {
            $closeHour = 24;
        }
        $overnight = $closeHour <= $openHour;
        $timeMinutes = ((int) $start->format('H')) * 60 + (int) $start->format('i');
        $startDate = $start;
        if ($overnight && $timeMinutes < $closeHour * 60) {
            $startDate = $start->subDay();
        }

        $sessionStart = $startDate->setTime($openHour, 0);
        $sessionEnd = $startDate->setTime($closeHour === 24 ? 0 : $closeHour, 0);
        if ($closeHour === 24 || $sessionEnd->lessThanOrEqualTo($sessionStart)) {
            $sessionEnd = $sessionEnd->addDay();
        }

        return $start->greaterThanOrEqualTo($sessionStart) && $end->lessThanOrEqualTo($sessionEnd);
    }

    private function overlaps(string $start, string $end, string $rangeStart, string $rangeEnd): bool
    {
        $endMinutes = $end === '24:00' ? 1440 : $this->minutes($end);
        $rangeEndMinutes = in_array($rangeEnd, ['24:00', '00:00'], true) ? 1440 : $this->minutes($rangeEnd);

        return $this->minutes($start) < $rangeEndMinutes
            && $endMinutes > $this->minutes($rangeStart);
    }

    private function weekendShare($bookings, string $timezone): float
    {
        $today = CarbonImmutable::now($timezone)->toDateString();
        $history = $bookings->filter(fn (Booking $booking) => $booking->date->toDateString() < $today);
        if ($history->isEmpty()) {
            return 0.0;
        }

        $weekendCount = $history->filter(fn (Booking $booking) => in_array(
            CarbonImmutable::parse($booking->date->toDateString(), $timezone)->dayOfWeek,
            [CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY],
            true,
        ))->count();

        return $weekendCount / $history->count();
    }

    private function slotSuggestion(array $slot, string $type, int $priority, array $copy = []): array
    {
        $date = CarbonImmutable::parse($slot['date'], (string) config('lumi.timezone', 'Asia/Manila'));
        $timeLabel = $this->timeLabel($slot['time']);
        return [
            'key' => $type.':'.$slot['date'].':'.$slot['time'].':'.$slot['court_id'],
            'type' => $type,
            'title' => $copy['title'] ?? ($type === 'first_booking' ? 'Ready for your first game?' : 'A weekday court is open'),
            'message' => $copy['message'] ?? ($type === 'first_booking'
                ? "The next open time we found at {$slot['court']} is {$date->format('l')}, {$timeLabel}."
                : "A weekday time at {$slot['court']} is open on {$date->format('l')}, {$timeLabel}."),
            'details' => [
                'date' => $slot['date'],
                'time' => $slot['time'],
                'court' => $slot['court'],
                'court_id' => $slot['court_id'],
            ],
            'cta_label' => 'Book this time',
            'cta_url' => $this->bookingUrl($slot['date'], $slot['time'], $slot['court_id']),
            'priority' => $priority,
        ];
    }

    private function orderAndLimit(array $suggestions): array
    {
        return collect($suggestions)
            ->sortBy('priority')
            ->unique(fn (array $suggestion) => implode('|', [
                $suggestion['details']['date'] ?? '',
                $suggestion['details']['time'] ?? '',
                $suggestion['details']['court_id'] ?? $suggestion['details']['court'] ?? '',
            ]))
            ->values()
            ->all();
    }

    private function bookingUrl(string $date, string $time, int $courtId): string
    {
        return '/book?'.http_build_query([
            'date' => $date,
            'time' => $time,
            'court' => $courtId,
        ]);
    }

    private function timeLabel(string $time): string
    {
        return CarbonImmutable::createFromFormat('!H:i', $time, (string) config('lumi.timezone', 'Asia/Manila'))
            ->format('g:i A');
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        return $hour * 60 + $minute;
    }

    private function searchDays(): int
    {
        return max(1, (int) config('lumi.availability_search_days', 30));
    }

    private function cacheKey(int $userId): string
    {
        return 'lumi:suggestions:user:'.$userId;
    }
}
