<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\GuardSessionState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccessVersion
{
    public const USER_KEY = 'access_version_user_id';
    public const VERSION_KEY = 'access_version';

    public function handle(Request $request, Closure $next): Response
    {
        $authenticated = $request->user();
        if (! $authenticated) {
            return $next($request);
        }

        $user = User::query()->find($authenticated->user_id);
        if (! $user) {
            return $this->logoutForRevokedAccess($request);
        }

        $sessionUserId = $request->session()->get(GuardSessionState::key(self::USER_KEY));
        $sessionVersion = $request->session()->get(GuardSessionState::key(self::VERSION_KEY));

        // Older customer sessions can remain valid. A privileged account with
        // no version stamp must re-authenticate before it can reach staff/admin
        // capabilities, including a customer session just promoted to staff.
        if ($sessionUserId === null || $sessionVersion === null) {
            if (in_array($user->role, ['owner', 'admin', 'staff'], true)) {
                return $this->logoutForRevokedAccess($request);
            }

            $this->stamp($request, $user);
            return $next($request);
        }

        if ((int) $sessionUserId !== (int) $user->user_id
            || (int) $sessionVersion !== (int) $user->access_version) {
            return $this->logoutForRevokedAccess($request);
        }

        return $next($request);
    }

    public static function stamp(Request $request, User $user): void
    {
        $request->session()->put([
            GuardSessionState::key(self::USER_KEY) => (int) $user->user_id,
            GuardSessionState::key(self::VERSION_KEY) => (int) $user->access_version,
        ]);
    }

    private function logoutForRevokedAccess(Request $request): Response
    {
        $loginRoute = 'login';
        if (config('auth.multi_guard')) {
            $guard = GuardSessionState::currentGuard();
            Auth::guard($guard)->logout();
            GuardSessionState::forgetGuard($request, $guard);
            $request->session()->regenerate();
            if ($guard !== 'customer') {
                $loginRoute = $guard.'.login';
            }
        } else {
            $request->session()->forget('view_as');
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Your access changed. Please sign in again.'], 401);
        }

        return redirect()->route($loginRoute)->withErrors([
            'username' => 'Your access changed. Please sign in again.',
        ]);
    }
}
