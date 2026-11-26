<?php

namespace App\Providers;

use App\Models\PaymentReference;
use App\Observers\PaymentReferenceObserver;
use App\Services\UserAccessService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use App\Models\CreditWallet;
use App\Models\User;
use App\Policies\PrivilegedMfaPolicy;
use App\Support\GuardSessionState;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;


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
        Gate::policy(User::class, PrivilegedMfaPolicy::class);
        Paginator::defaultView('vendor.pagination.pagination-custom');
        PaymentReference::observe(PaymentReferenceObserver::class);

        // 5 attempts per minute, keyed by submitted username + IP so an
        // attacker can't dodge the limit just by rotating usernames, and
        // a single IP can't be used to lock out every account at once.
        RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            $lane = config('auth.multi_guard')
                ? (string) ($request->route()?->defaults['_role_guard'] ?? '')
                : '';
            $key = Str::lower((string) $request->input('username')).'|'.$request->ip();
            if (config('auth.multi_guard')) {
                $key .= '|'.$lane;
            }

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

        $calendarLimitResponse = static function (Request $request, array $headers) {
            $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));
            $headers['Retry-After'] = (string) $retryAfter;

            return response()->json([
                'message' => "Too many changes. Try again in {$retryAfter} seconds.",
            ], 429, $headers);
        };
        $calendarLimit = static function (Request $request, int $adminLimit, int $staffLimit) use ($calendarLimitResponse) {
            $user = $request->user();
            $identity = $user
                ? 'user:'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip();
            $role = $user ? app(UserAccessService::class)->effectiveRole($user, $request) : null;
            $isStaff = $role === 'staff';
            $isAdminManagement = in_array($role, ['admin', 'owner'], true);

            return Limit::perMinute($isStaff ? $staffLimit : $adminLimit * ($isAdminManagement ? 5 : 1))
                ->by($isAdminManagement ? 'admin-mgmt:'.$identity : $identity)
                ->response($calendarLimitResponse);
        };

        RateLimiter::for('admin-calendar-quote', static function (Request $request) use ($calendarLimit) {
            return $request->boolean('dry_run')
                ? $calendarLimit($request, 120, 60)
                : Limit::none();
        });
        RateLimiter::for('admin-calendar-save', static function (Request $request) use ($calendarLimit) {
            return $request->boolean('dry_run')
                ? Limit::none()
                : $calendarLimit($request, 120, 60);
        });
        RateLimiter::for('admin-calendar-delete', static fn (Request $request) => $calendarLimit($request, 60, 30));
        RateLimiter::for('admin-closures', static fn (Request $request) => $calendarLimit($request, 120, 120));
        RateLimiter::for('admin-calendar-read', static fn (Request $request) => $calendarLimit($request, 120, 60));

        $clearMfaSession = static function (string $guard): void {
            $guard = config('auth.multi_guard') ? $guard : null;
            session()->forget(array_map(
                static fn (string $key): string => GuardSessionState::key($key, $guard),
                ['mfa_passed_at', 'mfa_passed_user_id', 'mfa_temp_secret', 'mfa_temp_secret_user_id', 'mfa_setup_voluntary'],
            ));
        };

        Event::listen(function (Login $event) use ($clearMfaSession) {
            $clearMfaSession($event->guard);
        });

        Event::listen(function (Logout $event) use ($clearMfaSession) {
            $clearMfaSession($event->guard);
        });

        // Share the authenticated user's credit wallet balance with the
        // user layout (topbar badge) AND every user/* child view that uses
        // $layoutCreditBalance directly (e.g. user-dashboard). The closure
        // is extracted so both composers share one definition. Always
        // returns a full array — never null — so the view never throws
        // "undefined variable $layoutCreditBalance".
        $creditComposer = function ($view) {
            $wallet = null;
            if (auth()->check()) {
                $wallet = CreditWallet::where('user_id', auth()->id())->first();
            }
            $view->with('layoutCreditBalance', [
                'given'    => $wallet ? (int) $wallet->given_balance    : 0,
                'reserved' => $wallet ? (int) $wallet->reserved_balance : 0,
                'total'    => $wallet ? (int) $wallet->given_balance + (int) $wallet->reserved_balance : 0,
            ]);
        };

        View::composer('layouts.user', $creditComposer);
        View::composer('user.*', $creditComposer);
    }
}
