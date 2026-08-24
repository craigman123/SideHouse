<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return $next($request);
        }

        // Already passed MFA this session
        if (session('mfa_passed') === true) {
            return $next($request);
        }

        // Admin has MFA enabled → must verify
        if ($user->hasMfaEnabled()) {
            return redirect()->route('mfa.challenge');
        }

        // Admin has NOT set up MFA yet → force setup
        return redirect()->route('mfa.setup');
    }
}
