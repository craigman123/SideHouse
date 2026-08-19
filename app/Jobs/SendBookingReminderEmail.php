<?php

namespace App\Jobs;

use App\Mail\BookingReminderMail;
use App\Models\Booking;
use App\Support\BrevoMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendBookingReminderEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;          // Laravel will retry the job up to 3 times
    public int $backoff = 30;       // wait 30 seconds between retries

    public function __construct(
        public Booking $booking
    ) {}

    public function handle(): void
    {
        // Safety: if someone already marked it, or it no longer has an email, skip
        if ($this->booking->reminder_sent_at !== null || empty($this->booking->email)) {
            return;
        }

        BrevoMailer::send(
            $this->booking->email,
            $this->booking->customer_name,
            new BookingReminderMail($this->booking),
        );

        // Only mark as sent AFTER the email actually went out successfully
        $this->booking->update(['reminder_sent_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        // Optional: log it, notify admin, etc.
        // We deliberately do NOT set reminder_sent_at here
        // so the scheduler can pick it up again later.
        \Log::error("Booking reminder email failed for booking #{$this->booking->id}", [
            'error' => $exception->getMessage(),
        ]);
    }
}