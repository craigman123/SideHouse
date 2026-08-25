<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMfa
{
    // How long an MFA verification stays valid, in seconds.
    // Re-verification required after this window.
    protected int $mfaTtl = 60 * 60 * 8; // 8 hours

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return $next($request);
        }

        $passedAt = session('mfa_passed_at');

        if ($passedAt && (now()->timestamp - $passedAt) < $this->mfaTtl) {
            return $next($request);
        }

        // Expired or never verified — clear stale flag and force re-check
        session()->forget('mfa_passed_at');

        if ($user->hasMfaEnabled()) {
            return redirect()->route('mfa.challenge');
        }

        return redirect()->route('mfa.setup');
    }
}