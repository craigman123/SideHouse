<?php

namespace App\Support;

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
        'view_as',
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
}
