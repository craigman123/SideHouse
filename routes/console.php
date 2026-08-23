<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('bookings:expire-unconfirmed-qrph')->everyMinute();


// In-app notification for logged-in users whose paid booking is starting soon
Schedule::command('bookings:send-in-app-reminders')->everyFiveMinutes();
 
// Email for guests whose paid booking is starting soon
Schedule::command('bookings:send-email-reminders')->everyFiveMinutes();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
