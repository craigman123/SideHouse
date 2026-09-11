<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The "here's your 4-digit code" email for the guest booking lookup
 * modal. Kept unqueued (no ShouldQueue) so requestCode() still sends
 * synchronously, same as the old Mail::raw() call — if you later add
 * ShouldQueue here, make sure QUEUE_CONNECTION isn't "sync" or the
 * email will silently sit in the jobs table instead of sending.
 */
class GuestBookingOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $ttlMinutes,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Your Booking Lookup Code')
            ->view('emails.guest-booking-otp')
            ->with([
                'code' => $this->code,
                'ttlMinutes' => $this->ttlMinutes,
            ]);
    }
}
