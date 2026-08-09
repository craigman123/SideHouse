<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\PreventBackHistory::class,
        ]);
        $middleware->trustProxies(at: '*');

        // These are hit by an external SMS-forwarding app, not a browser —
        // there's no session/CSRF token for it to send. The webhook is
        // still protected by its own ?token= secret check inside each
        // controller (see GcashWebhookController / LandbankWebhookController).
        $middleware->validateCsrfTokens(except: [
            'webhooks/gcash-sms',
            'webhooks/landbank-sms',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();