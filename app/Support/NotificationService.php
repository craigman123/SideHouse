<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;

/**
 * Creates notifications, mirroring ActivityLogger's static-call style so
 * it reads the same way at call sites (NotificationService::notify(...)
 * next to ActivityLogger::log(...)) rather than introducing a different
 * pattern for a very similar concern.
 */
class NotificationService
{
    /**
     * Notify a single user. Silently no-ops when $userId is null — every
     * call site that might be triggered by a guest booking (no user_id)
     * goes through this rather than checking for null itself everywhere.
     */
    public static function notify(?int $userId, string $type, string $title, string $body, array $data = []): ?Notification
    {
        if ($userId === null) {
            return null;
        }

        return Notification::create([
            'user_id' => $userId,
            'type'    => $type,
            'title'   => $title,
            'body'    => $body,
            'data'    => $data,
        ]);
    }

    /**
     * Booking status changed (paid / cancelled) — used by the webhook
     * controllers, GuestBookingController, and User_UserController
     * wherever a booking's status actually changes. Guest bookings have
     * no user_id and are silently skipped via notify()'s null check
     * above — there's no account to deliver a notification to.
     */
    public static function bookingStatus(int|null $userId, int $bookingId, string $title, string $body): ?Notification
    {
        return self::notify(
            $userId,
            Notification::TYPE_BOOKING_STATUS,
            $title,
            $body,
            ['booking_id' => $bookingId],
        );
    }

    public static function bookingReminder(int $userId, int $bookingId, string $title, string $body): ?Notification
    {
        return self::notify(
            $userId,
            Notification::TYPE_BOOKING_REMINDER,
            $title,
            $body,
            ['booking_id' => $bookingId],
        );
    }

    /**
     * Broadcasts one notification to every user — inserts one row per
     * recipient (see the migration comment for why) via a single bulk
     * insert rather than looping Eloquent::create(), since this could
     * realistically run against every registered user at once. Returns
     * how many rows were actually inserted, for the admin composer's
     * confirmation message.
     */
    public static function announceToAll(string $title, string $body): int
    {
        $userIds = User::pluck('user_id');
        $now = now();

        $rows = $userIds->map(fn ($userId) => [
            'user_id'    => $userId,
            'type'       => Notification::TYPE_ANNOUNCEMENT,
            'title'      => $title,
            'body'       => $body,
            'data'       => null,
            'read_at'    => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if (empty($rows)) {
            return 0;
        }

        Notification::insert($rows);

        return count($rows);
    }
}
