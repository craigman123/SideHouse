<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Adds baseline security headers to every response.
     *
     * CSP is scoped to allow Google Identity Services (accounts.google.com /
     * gstatic.com) since the app's Google sign-in button needs it — tighten
     * further if you add other third-party scripts, or loosen if this
     * breaks something Google's SDK needs that isn't covered here.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HSTS only makes sense once you're confident HTTPS is reliably
        // enforced in front of the app (e.g. Render's edge) — sending it
        // over plain HTTP locally is harmless (browsers ignore it), so
        // it's safe to leave on, but double check your production HTTPS
        // setup before relying on it.
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        $response->headers->set(
            'Content-Security-Policy',
            implode('; ', [
                "default-src 'self'",
                "script-src 'self' https://accounts.google.com https://apis.google.com",
                "frame-src https://accounts.google.com",
                "connect-src 'self' https://accounts.google.com",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "object-src 'none'",
                "base-uri 'self'",
                "frame-ancestors 'none'",
            ])
        );

        return $response;
    }
}