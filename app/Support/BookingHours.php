<?php

namespace App\Support;

use App\Models\BusinessSetting;
use App\Models\CourtClosure;
use Carbon\Carbon;

/**
 * Replaces the OPEN_HOUR/CLOSE_HOUR/etc. consts that used to be
 * hardcoded and duplicated in GuestBookingController and
 * User_UserController. Both should call into this instead of defining
 * their own copies — see admin.schedule for where these values (and
 * closures) actually get edited.
 */
class BookingHours
{
    public static function settings(): BusinessSetting
    {
        return BusinessSetting::current();
    }

    public static function openHour(): int
    {
        return self::settings()->open_hour;
    }

    public static function closeHour(): int
    {
        return self::settings()->close_hour;
    }

    public static function stepMinutes(): int
    {
        return self::settings()->step_minutes;
    }

    public static function minDurationHours(): int
    {
        return self::settings()->min_duration_hours;
    }

    public static function maxDurationHours(): int
    {
        return self::settings()->max_duration_hours;
    }

    /**
     * True when close_hour <= open_hour, e.g. open 8 AM, close 7 AM —
     * the operating window crosses midnight into the next calendar day.
     */
    public static function isOvernight(): bool
    {
        return self::closeHour() <= self::openHour();
    }

    /**
     * Days of week (0 = Sunday ... 6 = Saturday) the business is closed
     * every week — set on the admin Schedule page, applies store-wide
     * to every court.
     */
    public static function closedWeekdays(): array
    {
        return self::settings()->closed_weekdays;
    }

    /**
     * True when this date falls on a recurring weekly closure day
     * (e.g. "closed every Sunday"), regardless of any one-off
     * CourtClosure rows.
     */
    public static function isWeekdayClosed(string $date): bool
    {
        return in_array(Carbon::parse($date)->dayOfWeek, self::closedWeekdays(), true);
    }

    /**
     * The one-off closure covering this court on this date, if any —
     * either one scoped to that exact court or a store-wide one. Null
     * if there's no explicit CourtClosure row, even if the date is
     * closed via closedWeekdays() — use isClosed() for the combined
     * check.
     */
    public static function closureFor(int $courtId, string $date): ?CourtClosure
    {
        return CourtClosure::forCourtAndDate($courtId, $date)->first();
    }

    /**
     * The combined "is this court closed on this date" check — true for
     * a recurring weekly closure OR an explicit one-off CourtClosure.
     * This is what booking/availability code should call.
     */
    public static function isClosed(int $courtId, string $date): bool
    {
        return self::isWeekdayClosed($date) || self::closureFor($courtId, $date) !== null;
    }

    /**
     * Human-readable reason for isClosed() being true, for surfacing to
     * guests/users — prefers the explicit closure's reason (more
     * specific) over the generic weekly-closure label.
     */
    public static function closedReason(int $courtId, string $date): ?string
    {
        $closure = self::closureFor($courtId, $date);

        if ($closure) {
            return $closure->reason ?: 'Closed';
        }

        if (self::isWeekdayClosed($date)) {
            return 'Closed on ' . Carbon::parse($date)->format('l') . 's';
        }

        return null;
    }
}