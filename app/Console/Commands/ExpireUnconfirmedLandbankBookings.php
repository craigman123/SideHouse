<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

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
        $expired = Booking::where('status', 'pending')
            ->where('payment_method', 'landbank')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $booking) {
            $booking->update(['status' => 'cancelled']);
            $this->info("Expired booking #{$booking->id} (no matching Landbank payment within the window).");
        }

        if ($expired->isEmpty()) {
            $this->info('No unconfirmed Landbank bookings to expire.');
        }

        return self::SUCCESS;
    }
}
