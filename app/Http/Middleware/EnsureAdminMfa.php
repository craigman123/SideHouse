<?php

namespace App\Http\Middleware;

use App\Support\GuardSessionState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMfa
{
    protected int $mfaTtl = 60 * 60 * 8; // 8 hours

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->requiresMfa()) {
            return $next($request);
        }

        $passedAt = $request->session()->get(GuardSessionState::key('mfa_passed_at'));
        $passedUserId = $request->session()->get(GuardSessionState::key('mfa_passed_user_id'));

        if (
            $passedAt
            && (int) $passedUserId === (int) $user->user_id
            && (now()->timestamp - $passedAt) < $this->mfaTtl
        ) {
            return $next($request);
        }

        $request->session()->forget([
            GuardSessionState::key('mfa_passed_at'),
            GuardSessionState::key('mfa_passed_user_id'),
        ]);

        if ($user->hasMfaEnabled()) {
            return redirect()->route(mfa_route_name('mfa.challenge'));
        }

        return redirect()->route(mfa_route_name('mfa.setup'));
    }
}
