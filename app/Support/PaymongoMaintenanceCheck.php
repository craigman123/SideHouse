<?php

namespace App\Support;

use App\Models\PaymongoMaintenanceWindow;

class PaymongoMaintenanceCheck
{
    /**
     * Is QRPh/wallet payment currently unavailable due to PayMongo maintenance?
     */
    public static function isQrPaymentBlocked(): bool
    {
        return PaymongoMaintenanceWindow::isCurrentlyActive();
    }
}