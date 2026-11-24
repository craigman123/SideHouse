<?php

return [
    'max_amount' => (int) env('CREDIT_MAX_AMOUNT', 10000),
    'guest_verified_hours' => (int) env('CREDIT_GUEST_VERIFIED_HOURS', 12),
    'qr_minimum_php' => (int) env('CREDIT_QR_MINIMUM_PHP', 1),
    'spend_order' => ['given', 'reserved'],
];
