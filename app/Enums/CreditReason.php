<?php

namespace App\Enums;

enum CreditReason: string
{
    case AdminGrant = 'admin_grant';
    case BookingPayment = 'booking_payment';
    case BookingCancellation = 'booking_cancellation';
    case Refund = 'refund';
    case BookingPaymentRelease = 'booking_payment_release';
}
