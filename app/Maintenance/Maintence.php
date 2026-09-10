<?php

// Add this to routes/web.php — outside any auth middleware, since the
// site being "down" means normal auth/session flow may not work.
//
// Usage:
//   Turn maintenance ON:  https://yourdomain.com/system/down/{MAINTENANCE_CONTROL_TOKEN}
//   Turn maintenance OFF: https://yourdomain.com/system/up/{MAINTENANCE_CONTROL_TOKEN}
//   Bypass while down:    https://yourdomain.com/{MAINTENANCE_BYPASS_SECRET}
//
// Both tokens live in Render's Environment tab — never hardcode them here.

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/system/{action}/{token}', function (string $action, string $token) {
    $controlToken = (string) env('MAINTENANCE_CONTROL_TOKEN');

    // hash_equals prevents timing attacks on the token comparison.
    if ($controlToken === '' || ! hash_equals($controlToken, $token)) {
        abort(404);
    }

    if ($action === 'down') {
        Artisan::call('down', [
            '--secret' => env('MAINTENANCE_BYPASS_SECRET'),
            '--render' => 'errors::503',
        ]);

        return 'Maintenance mode is now ON.';
    }

    if ($action === 'up') {
        Artisan::call('up');

        return 'Maintenance mode is now OFF.';
    }

    abort(404);
})->name('system.maintenance-toggle');
