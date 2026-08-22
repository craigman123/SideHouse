<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Support\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Frees up court slots held by Landbank bookings that were never
 * confirmed — whether the guest never actually paid, sent a fake
 * reference/screenshot, or a real transfer's SMS just never matched
 * (network hiccup, wrong amount, etc.). Without this, a pending booking
 * blocks that slot forever regardless of whether anyone actually paid.
 *
 * Deliberately kept as its own command rather than folded into
 * ExpireUnconfirmedGcashBookings — same shape, separate class, separate
 * signature, one method each. Keep the two in sync if the expiry logic
 * ever changes (same window rule applies to both: must stay >= the
 * matching webhook controller's MATCH_WINDOW_MINUTES).
 *
 * Register in app/Console/Kernel.php:
 *   protected function schedule(Schedule $schedule): void
 *   {
 *       $schedule->command('bookings:expire-unconfirmed-landbank')->everyMinute();
 *   }
 * and make sure the Laravel scheduler cron entry is set up on the server:
 *   * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
 */
class ExpireUnconfirmedLandbankBookings extends Command
{
    protected $signature = 'bookings:expire-unconfirmed-landbank';

    protected $description = 'Cancel Landbank bookings that passed their confirmation window without a matching payment';

    public function handle(): int
    {
        // This is only the CANDIDATE list — a booking's actual status is
        // re-checked under lockForUpdate() per-row below, not trusted
        // from this query. Without that, LandbankWebhookController::handleSms()
        // (which does lock and re-check) could confirm a booking's
        // payment in the gap between this query running and the
        // ->update(['status' => 'cancelled']) call that used to follow
        // it directly — silently cancelling a booking that had just been
        // paid for. Same fix as ExpireUnconfirmedGcashBookings — keep
        // the two in sync.
        $candidateIds = Booking::where('status', 'pending')
            ->where('payment_method', 'landbank')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('id');

        $expiredCount = 0;

        foreach ($candidateIds as $id) {
            $booking = DB::transaction(function () use ($id) {
                $booking = Booking::where('id', $id)->lockForUpdate()->first();

                // Re-check under the lock: the webhook (or a manual
                // admin action) may have confirmed, cancelled, or
                // otherwise moved this booking on since the query above.
                if ($booking === null || $booking->status !== 'pending') {
                    return null;
                }

                if ($booking->expires_at === null || $booking->expires_at->isFuture()) {
                    return null;
                }

                $booking->update(['status' => 'cancelled']);

                return $booking;
            });

            if ($booking === null) {
                continue;
            }

            NotificationService::bookingStatus(
                $booking->user_id,
                $booking->id,
                'Booking expired',
                'We never received a matching Landbank payment in time, so this booking was cancelled and the slot released.',
            );

            $this->info("Expired booking #{$booking->id} (no matching Landbank payment within the window).");
            $expiredCount++;
        }

        if ($expiredCount === 0) {
            $this->info('No unconfirmed Landbank bookings to expire.');
        }

        return self::SUCCESS;
    }
}