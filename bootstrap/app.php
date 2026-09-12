<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'guest-book/payment/qrph/webhook',
        ]);
        $middleware->preventRequestsDuringMaintenance(except: [
            'cron/*',
            'maintenance/bypass',
        ]);
        $middleware->encryptCookies(except: [
            'laravel_maintenance',
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\PreventBackHistory::class,
        ]);
        $middleware->append(\App\Http\Middleware\LogRequestTraffic::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'cron.auth'   => \App\Http\Middleware\VerifyCronToken::class,
            'admin'       => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'admin.mfa'   => \App\Http\Middleware\EnsureAdminMfa::class,
        ]);

        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                    Request::HEADER_X_FORWARDED_HOST |
                    Request::HEADER_X_FORWARDED_PORT |
                    Request::HEADER_X_FORWARDED_PROTO
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();