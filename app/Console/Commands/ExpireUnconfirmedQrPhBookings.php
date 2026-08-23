<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

class ExpireUnconfirmedQrPhBookings extends Command
{
    protected $signature = 'bookings:expire-unconfirmed-qrph';

    protected $description = 'Cancel pending QR Ph bookings whose payment hold has expired';

    public function handle(): int
    {
        $count = Booking::query()
            ->where('payment_method', 'qrph')
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'cancelled']);

        $this->info("Cancelled {$count} expired QR Ph booking(s).");

        return self::SUCCESS;
    }
}
