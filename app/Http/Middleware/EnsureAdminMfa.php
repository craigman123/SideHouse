<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMfa
{
    protected int $mfaTtl = 60 * 60 * 8; // 8 hours

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return $next($request);
        }

        $passedAt = session('mfa_passed_at');
        $passedUserId = session('mfa_passed_user_id');

        if (
            $passedAt
            && $passedUserId === $user->id
            && (now()->timestamp - $passedAt) < $this->mfaTtl
        ) {
            return $next($request);
        }

        session()->forget(['mfa_passed_at', 'mfa_passed_user_id']);

        if ($user->hasMfaEnabled()) {
            return redirect()->route('mfa.challenge');
        }

        return redirect()->route('mfa.setup');
    }
}