<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class BookingCronController extends Controller
{
    public function runReminders()
    {
        Artisan::call('bookings:expire-unconfirmed-qrph');
        Artisan::call('bookings:send-in-app-reminders');
        Artisan::call('bookings:send-email-reminders');
        Artisan::call('logs:prune');
        Artisan::call('webhook:clean');
        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 20,
            '--tries' => 3,
        ]);

        return response('ok');
    }

    public function expireUnconfirmed()
    {
        Artisan::call('bookings:expire-unconfirmed-qrph');

        return response('ok');
    }
}
