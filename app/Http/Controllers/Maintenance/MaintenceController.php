<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;

class MaintenanceController extends Controller
{
    /**
     * Toggle the application's maintenance mode on or off.
     *
     * Route: GET /system/{action}/{token}
     */
    public function toggle(Request $request, string $action, string $token)
    {
        $controlToken = (string) env('MAINTENANCE_CONTROL_TOKEN');

        // hash_equals prevents timing attacks on the token comparison.
        if ($controlToken === '' || ! hash_equals($controlToken, $token)) {
            abort(404);
        }

        return match ($action) {
            'down' => $this->down(),
            'up' => $this->up(),
            default => abort(404),
        };
    }

    protected function down()
    {
        Artisan::call('down', [
            '--secret' => env('MAINTENANCE_BYPASS_SECRET'),
            '--render' => 'errors::503',
        ]);

        return 'Maintenance mode is now ON.';
    }

    protected function up()
    {
        Artisan::call('up');

        return response('Maintenance mode is now OFF.')
            ->withCookie(Cookie::forget(
                'laravel_maintenance',
                config('session.path'),
                config('session.domain'),
            ));
    }
}