<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central place for "who did what" logging across the whole system.
 *
 * Every call writes to two places:
 *   1. The `activity_logs` table — the admin-facing audit trail
 *      (see App\Http\Controllers\Admin\ActivityLogController).
 *   2. storage/logs/activity-*.log — a plain-text daily log file, kept
 *      separate from Laravel's default log so it doesn't get buried in
 *      framework/error noise. Built on demand via Log::build() so this
 *      doesn't require touching config/logging.php at all.
 *
 * Usage:
 *   ActivityLogger::log('booking.created', "Wen booked Court 1", subject: $booking, properties: [...]);
 */
class ActivityLogger
{
    /**
     * Sentinel used to distinguish "actor not provided" (default to
     * auth()->user()) from "actor explicitly passed as null" (a genuinely
     * actor-less event, e.g. a failed login attempt). func_num_args()
     * isn't reliable for this once named arguments are involved, so an
     * explicit sentinel default is used instead.
     */
    private const ACTOR_NOT_PROVIDED = '__activity_logger_actor_not_provided__';

    /**
     * @param string      $action      Dot-namespaced event key, e.g. 'booking.created'
     * @param string      $description Human-readable summary shown in the admin log table
     * @param mixed|null  $actor       The user performing the action. Defaults to auth()->user(),
     *                                 pass null explicitly for genuinely actor-less events (rare),
     *                                 or pass a specific user object (e.g. right before deleting them,
     *                                 or when logging in — auth() may not reflect it yet at call time).
     * @param mixed|null  $subject     The model the action is about (e.g. a Booking, Court, or User).
     * @param array       $properties  Extra structured context, stored as JSON.
     */
    public static function log(
        string $action,
        string $description,
        mixed $actor = self::ACTOR_NOT_PROVIDED,
        mixed $subject = null,
        array $properties = []
    ): void {
        $actor = $actor === self::ACTOR_NOT_PROVIDED ? auth()->user() : $actor;

        $payload = [
            'user_id'      => $actor?->user_id,
            'user_name'    => $actor?->name ?? 'Guest',
            'action'       => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->id ?? $subject?->user_id ?? null,
            'description'  => $description,
            'properties'   => $properties ?: null,
            'ip_address'   => request()?->ip(),
            'user_agent'   => request()?->userAgent(),
        ];

        // A logging failure should never break the actual user-facing
        // action it's attached to (e.g. don't fail a booking just because
        // the audit-log insert hiccupped) — log the failure itself to the
        // default logger and move on.
        try {
            ActivityLog::create($payload);
        } catch (Throwable $e) {
            Log::error('ActivityLogger: failed to write DB audit log', [
                'error'   => $e->getMessage(),
                'action'  => $action,
            ]);
        }

        try {
            self::fileChannel()->info($description, [
                'action'  => $action,
                'user'    => $payload['user_name'],
                'user_id' => $payload['user_id'],
                'subject' => $payload['subject_type'] && $payload['subject_id']
                    ? $payload['subject_type'] . '#' . $payload['subject_id']
                    : null,
                'ip'      => $payload['ip_address'],
                'context' => $properties ?: null,
            ]);
        } catch (Throwable $e) {
            Log::error('ActivityLogger: failed to write activity log file', [
                'error'  => $e->getMessage(),
                'action' => $action,
            ]);
        }
    }

    /**
     * An on-demand daily-rotating log channel at storage/logs/activity-*.log.
     * Built at runtime (rather than a named channel in config/logging.php)
     * so this works without any config file changes.
     */
    private static function fileChannel()
    {
        return Log::build([
            'driver' => 'daily',
            'path'   => storage_path('logs/activity.log'),
            'level'  => 'info',
            'days'   => 30,
        ]);
    }
}