<?php

namespace App\Providers;

use App\Models\PaymentReference;
use App\Observers\PaymentReferenceObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */

    public function boot(): void
    {   
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Paginator::defaultView('vendor.pagination.pagination-custom');
        PaymentReference::observe(PaymentReferenceObserver::class);

        // 5 attempts per minute, keyed by submitted username + IP so an
        // attacker can't dodge the limit just by rotating usernames, and
        // a single IP can't be used to lock out every account at once.
        RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            $key = Str::lower((string) $request->input('username')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key)->response(function (\Illuminate\Http\Request $request, array $headers) {
                return response()->json([
                    'message' => 'Too many login attempts. Please try again in a minute.',
                ], 429, $headers);
            });
        });

        Mail::extend('brevo', function () {
            $factory = new BrevoTransportFactory();
            return $factory->create(Dsn::fromString(config('services.brevo.dsn')));
        });

        RateLimiter::for('register', function (\Illuminate\Http\Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        Event::listen(function (Login $event) {
            session()->forget(['mfa_passed_at', 'mfa_passed_user_id', 'mfa_temp_secret', 'mfa_temp_secret_user_id']);
        });

        Event::listen(function (Logout $event) {
            session()->forget(['mfa_passed_at', 'mfa_passed_user_id', 'mfa_temp_secret', 'mfa_temp_secret_user_id']);
        });
    }
}