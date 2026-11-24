<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\PaymentReference;
use App\Services\CreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireUnconfirmedQrPhBookings extends Command
{
    protected $signature = 'bookings:expire-unconfirmed-qrph';

    protected $description = 'Cancel pending QR Ph bookings whose payment hold has expired';

    public function handle(): int
    {
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

        $count = 0;
        $referenceIds = Booking::query()
            ->whereIn('id', $expiredIds)
            ->whereNotNull('payment_reference_id')
            ->pluck('payment_reference_id')
            ->unique();

        foreach ($referenceIds as $referenceId) {
            DB::transaction(function () use ($referenceId, &$count) {
                $reference = PaymentReference::whereKey($referenceId)->lockForUpdate()->first();
                if (! $reference) return;

                app(CreditService::class)->releasePaymentHold($reference);

                $bookings = Booking::where('payment_reference_id', $referenceId)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->get();
                foreach ($bookings as $booking) {
                    $booking->update(['status' => 'cancelled']);
                    $booking->slots()->update(['is_active' => false]);
                    $count++;
                }
            });
        }

        $this->info("Cancelled {$count} expired QR Ph booking(s).");

        return self::SUCCESS;
    }
}