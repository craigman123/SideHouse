<?php

namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $this->logToggle('maintenance_on', 'Maintenance mode was turned ON.');

        return view('maintenance.maintenance-mode', ['status' => 'on']);
    }

    protected function up()
    {
        Artisan::call('up');

        $this->logToggle('maintenance_off', 'Maintenance mode was turned OFF.');

        return response()
            ->view('maintenance.maintenance-mode', ['status' => 'off'])
            ->withCookie(Cookie::forget(
                'laravel_maintenance',
                config('session.path'),
                config('session.domain'),
            ));
    }

    /**
     * Handle the token submitted from the maintenance page's bypass form.
     *
     * On success this hands the browser the same signed cookie Laravel's
     * own `/{secret}` bypass URL would issue — reusing the framework's
     * tested cookie logic instead of re-implementing it — but without
     * ever putting the secret itself in the address bar, browser
     * history, or referrer headers the way visiting `/{secret}` would.
     *
     * Route: POST /maintenance/bypass (throttled, excluded from the
     * maintenance-mode middleware so it works while the site is down)
     */
    public function bypass(Request $request)
    {
        $request->validate([
            'bypass' => ['required', 'string', 'max:255'],
        ]);

        $secret = (string) env('MAINTENANCE_BYPASS_SECRET');
        $submitted = trim((string) $request->input('bypass'));

        if ($secret === '' || ! hash_equals($secret, $submitted)) {
            $this->logToggle('maintenance_bypass_failed', 'A maintenance bypass attempt used an invalid token.');

            return back()->withErrors(['bypass' => 'That token is not valid.']);
        }

        $this->logToggle('maintenance_bypass_success', 'Maintenance mode was bypassed via the token form.');

        return redirect('/')->withCookie(MaintenanceModeBypassCookie::create($secret));
    }

    /**
     * Record who (or what) flipped maintenance mode, from where, and when.
     *
     * This route is secured by a URL token rather than an authenticated
     * session, so most triggers won't have a logged-in user attached.
     * We still capture the authenticated user if one happens to be
     * present (e.g. an admin who is also logged into the app), and
     * otherwise attribute the action to "System (token-triggered)" so
     * the audit trail is never silently blank.
     */
    protected function logToggle(string $action, string $description): void
    {
        $request = request();
        $ip = $request->ip();
        $user = $request->user();

        ActivityLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'System (token-triggered)',
            'action' => $action,
            'subject_type' => 'Maintenance',
            'subject_id' => null,
            'description' => $description,
            'properties' => $this->resolveLocation($ip),
            'ip_address' => $ip,
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Best-effort IP geolocation. Uses ip-api.com's free, keyless endpoint
     * (HTTP only, ~45 requests/min limit) — swap in a paid/HTTPS provider
     * (ipinfo.io, MaxMind, etc.) if you need higher volume or HTTPS.
     *
     * Never throws: a lookup failure just means the log entry has no
     * location data, it never blocks the maintenance toggle itself.
     */
    protected function resolveLocation(?string $ip): array
    {
        $data = ['ip' => $ip];

        if (! $ip || in_array($ip, ['127.0.0.1', '::1'], true)) {
            $data['location'] = 'local/dev environment';

            return $data;
        }

        try {
            $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
                'fields' => 'status,country,regionName,city,lat,lon',
            ]);

            if ($response->ok() && $response->json('status') === 'success') {
                $data['country'] = $response->json('country');
                $data['region'] = $response->json('regionName');
                $data['city'] = $response->json('city');
                $data['lat'] = $response->json('lat');
                $data['lon'] = $response->json('lon');
            }
        } catch (\Throwable $e) {
            Log::warning('Maintenance IP geolocation lookup failed.', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        return $data;
    }

}