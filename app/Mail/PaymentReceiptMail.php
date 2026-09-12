<?php

namespace App\Mail;

use App\Models\PaymentReference;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Sent once, right when a PaymentReference's confirmed_at first gets set
 * (see App\Observers\PaymentReferenceObserver). Carries every Booking
 * linked to that payment, not just one date, so a guest who booked
 * several dates in one checkout gets a single email covering all of them.
 *
 * Sends synchronously for now. Once a queue worker is actually running in
 * production, implement ShouldQueue here so a slow mail server can't hold
 * up the webhook request that triggers this.
 */
class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public PaymentReference $paymentReference;
    public Collection $bookings;

    public function __construct(PaymentReference $paymentReference)
    {
        $this->paymentReference = $paymentReference;
        $this->bookings = $paymentReference->bookings()
            ->with('court')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();
    }

    public function build()
    {
        return $this->subject('Your Payment Receipt')
            ->view('emails.mail-payment-reciept')
            ->with([
                'paymentReference' => $this->paymentReference,
                'bookings'         => $this->bookings,
            ]);
    }
}
