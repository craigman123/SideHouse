<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

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

        RateLimiter::for('register', function (\Illuminate\Http\Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}