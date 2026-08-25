<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingSlot;
use Illuminate\Console\Command;

class ExpireUnconfirmedQrPhBookings extends Command
{
    protected $signature = 'bookings:expire-unconfirmed-qrph';

    protected $description = 'Cancel pending QR Ph bookings whose payment hold has expired';

    public function handle(): int
    {
        // Capture the IDs before the bulk update, so the same set of
        // bookings can be used afterward to also deactivate their
        // booking_slots rows — a plain bulk update() has no per-row hook
        // to do that inline, and query builder updates don't fire model
        // events either way.
        $expiredIds = Booking::query()
            ->where('payment_method', 'qrph')
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->pluck('id');

        if ($expiredIds->isEmpty()) {
            $this->info('Cancelled 0 expired QR Ph booking(s).');

            return self::SUCCESS;
        }

        $count = Booking::whereIn('id', $expiredIds)->update(['status' => 'cancelled']);

        // Same reasoning as the other cancellation paths — a cancelled
        // booking's slots must be marked inactive so the DB-level
        // uniqueness constraint (booking_slots_court_date_start_unique)
        // frees the slot for someone else to book, instead of staying
        // permanently blocked by a stale active row.
        BookingSlot::whereIn('booking_id', $expiredIds)->update(['is_active' => false]);

        $this->info("Cancelled {$count} expired QR Ph booking(s).");

        return self::SUCCESS;
    }
}