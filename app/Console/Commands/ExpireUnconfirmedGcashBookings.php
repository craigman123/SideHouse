<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Frees up court slots held by GCash bookings that were never confirmed —
 * whether the guest never actually paid, sent a fake reference/screenshot,
 * or a real payment's SMS just never matched (network hiccup, wrong
 * amount, etc.). Without this, a pending booking blocks that slot forever
 * regardless of whether anyone actually paid.
 *
 * Register in app/Console/Kernel.php:
 *   protected function schedule(Schedule $schedule): void
 *   {
 *       $schedule->command('bookings:expire-unconfirmed')->everyMinute();
 *   }
 * and make sure the Laravel scheduler cron entry is set up on the server:
 *   * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
 */
class ExpireUnconfirmedGcashBookings extends Command
{
    protected $signature = 'bookings:expire-unconfirmed';

    protected $description = 'Cancel GCash bookings that passed their confirmation window without a matching payment';

    public function handle(): int
    {
        $expired = Booking::where('status', 'pending')
            ->where('payment_method', 'gcash')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $booking) {
            $booking->update(['status' => 'cancelled']);
            $this->info("Expired booking #{$booking->id} (no matching GCash payment within the window).");
        }

        if ($expired->isEmpty()) {
            $this->info('No unconfirmed GCash bookings to expire.');
        }

        return self::SUCCESS;
    }
}
