<?php

namespace App\Support;

/**
 * Normalizes a payment reference number so it can be compared regardless
 * of how it was typed or formatted — "294-087-757", "Ref# 294087757",
 * and "294 087 757" all normalize to the same value. Kept as a string
 * (not cast to int) so a leading zero in a real reference number is
 * never silently dropped.
 *
 * Shared by GcashWebhookController, LandbankWebhookController, and
 * GuestBookingController::store() so the guest-entered reference and the
 * SMS-parsed one are always compared the exact same way, in either
 * direction (webhook matching a booking, or store() retroactively
 * claiming a parked UnmatchedPayment).
 */
class PaymentReference
{
    public static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
