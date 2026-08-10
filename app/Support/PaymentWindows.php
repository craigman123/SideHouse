<?php

namespace App\Support;

/**
 * Single source of truth for every SMS-payment-matching time window.
 *
 * These used to be four separate magic numbers scattered across four
 * files (GuestBookingController, both webhook controllers, and implied
 * by the two expire-* commands), kept in sync "manually" per a comment
 * that just said so. This class is that sync point instead.
 *
 * They have to stay ordered this way or a real payment can fall through
 * a gap where nothing is willing to claim it:
 *
 *   matchWindowMinutes($method) >= claimWindowMinutes($method) >= BOOKING_EXPIRY_MINUTES
 *
 * - BOOKING_EXPIRY_MINUTES: how long a pending booking holds its slot
 *   before ExpireUnconfirmedGcashBookings / ExpireUnconfirmedLandbankBookings
 *   cancels it.
 * - matchWindowMinutes($method): how far back the matching webhook
 *   controller looks for a pending booking to match an incoming SMS
 *   against (keyed off the booking's created_at). This is the widest
 *   window because it has to cover the slowest realistic case: guest
 *   pays immediately, then takes a while to finish typing their name/
 *   contact/reference and hit "Confirm Booking".
 * - claimWindowMinutes($method): how far back GuestBookingController::
 *   store() looks for a parked UnmatchedPayment to claim retroactively
 *   when a *new* booking is created (keyed off the payment's created_at).
 *   Set equal to the matching method's match window on purpose — if this
 *   were shorter, a payment old enough that the webhook would still
 *   happily match it against a future booking could nonetheless miss
 *   being claimed here, leaving the guest stuck pending for a payment
 *   that already landed.
 *
 * Changing any of these? Keep the relationship above intact, and check
 * both expire-* commands still make sense against the new BOOKING_
 * EXPIRY_MINUTES (they read it indirectly, via the `expires_at` column
 * GuestBookingController::store() sets at booking time).
 */
final class PaymentWindows
{
    public const BOOKING_EXPIRY_MINUTES = 15;

    private const MATCH_WINDOW_MINUTES = [
        'gcash'    => 40,
        'landbank' => 40,
    ];

    public static function matchWindowMinutes(string $paymentMethod): int
    {
        return self::MATCH_WINDOW_MINUTES[$paymentMethod]
            ?? throw new \InvalidArgumentException("Unknown payment method: {$paymentMethod}");
    }

    public static function claimWindowMinutes(string $paymentMethod): int
    {
        // Deliberately identical to the match window — see class
        // docblock for why they must stay equal.
        return self::matchWindowMinutes($paymentMethod);
    }
}