<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GuardSessionState
{
    private const SESSION_KEYS = [
        'access_version_user_id',
        'access_version',
        'mfa_passed_at',
        'mfa_passed_user_id',
        'mfa_temp_secret',
        'mfa_temp_secret_user_id',
        'mfa_setup_voluntary',
        'view_as',
        'lumi_login_popover_pending',
        'lumi_login_popover_shown',
        'lumi_suggestions_seen',
    ];

    public static function currentGuard(): string
    {
        return Auth::getDefaultDriver();
    }

    public static function key(string $key, ?string $guard = null): string
    {
        if (! config('auth.multi_guard')) {
            return $key;
        }

        return $key.'_'.($guard ?? self::currentGuard());
    }

    public static function forgetGuard(Request $request, string $guard): void
    {
        $keys = config('auth.multi_guard')
            ? array_map(static fn (string $key): string => $key.'_'.$guard, self::SESSION_KEYS)
            : self::SESSION_KEYS;

        $request->session()->forget($keys);
    }

    public static function regenerateSession(Request $request): void
    {
        self::runPreservingToken($request, fn () => $request->session()->regenerate());
    }

    public static function runPreservingToken(Request $request, Closure $callback): mixed
    {
        if (! config('auth.multi_guard')) {
            return $callback();
        }

        $token = $request->session()->token();
        $result = $callback();

        if (is_string($token)) {
            $request->session()->put('_token', $token);
        }

        return $result;
    }
}
