<?php

namespace App\Observers;

use App\Mail\PaymentReceiptMail;
use App\Models\PaymentReference;
use Illuminate\Support\Facades\Mail;

/**
 * Fires the payment-receipt email the moment a PaymentReference becomes
 * confirmed, whether that happens via PaymongoQrPhController's webhook
 * or a manual/counter payment recorded straight into the paid state.
 * Hooking this on the model rather than in the webhook controller means
 * we can't forget to send it, and it fires exactly once per payment
 * either way.
 */
class PaymentReferenceObserver
{
    /**
     * Covers the (currently unused but supported) case of a
     * PaymentReference being created already-confirmed, e.g. a manual/
     * counter payment recorded straight into the paid state.
     */

    public bool $afterCommit = true;
    public function created(PaymentReference $paymentReference): void
    {
        if ($paymentReference->confirmed_at !== null) {
            $this->sendReceipt($paymentReference);
        }
    }

    /**
     * The normal path: webhook confirms payment sometime after the
     * PaymentReference row already exists, so confirmed_at flips from
     * null to a timestamp on an update.
     */
    public function updated(PaymentReference $paymentReference): void
    {
        if ($paymentReference->wasChanged('confirmed_at') && $paymentReference->confirmed_at !== null) {
            $this->sendReceipt($paymentReference);
        }
    }

    private function sendReceipt(PaymentReference $paymentReference): void
    {
        // bookings.email is set by both the guest flow (from the Google-
        // verified email) and the user flow ($user->email stored at booking
        // time), so this lookup works for both. If it is somehow missing,
        // log a warning and bail rather than crashing the observer.
        $recipient = $paymentReference->bookings()->value('email');

        if (! $recipient) {
            \Log::warning('Receipt mail skipped: no booking email found', [
                'payment_reference_id' => $paymentReference->id,
            ]);
            return;
        }

        Mail::to($recipient)->send(new PaymentReceiptMail($paymentReference));
    }
}