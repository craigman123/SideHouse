<?php

namespace App\Support;

/**
 * Normalizes a payment reference number so it can be compared regardless
 * of how it was typed or formatted — "294-087-757", "Ref# 294087757",
 * and "294 087 757" all normalize to the same value. Kept as a string
 * (not cast to int) so a leading zero in a real reference number is
 * never silently dropped.
 *
 * Intended to be shared by PaymongoQrPhController and
 * GuestBookingController::store() so the guest-entered reference and the
 * webhook-reported one are always compared the exact same way — but as
 * of this writing nothing in the codebase actually calls normalize()
 * yet; see the cleanup notes for this file.
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