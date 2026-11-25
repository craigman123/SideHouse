<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    private const GUARD_SCOPED_SESSION_KEYS = [
        'access_version_user_id',
        'access_version',
        'mfa_passed_at',
        'mfa_passed_user_id',
        'mfa_temp_secret',
        'mfa_temp_secret_user_id',
        'view_as',
        'lumi_login_popover_pending',
        'lumi_login_popover_shown',
        'lumi_suggestions_seen',
    ];

    public function actingAs(UserContract $user, $guard = null)
    {
        if (config('auth.multi_guard') && $guard === null && $user instanceof User) {
            $guard = $user->role === 'user' ? 'customer' : $user->role;
        }

        if (config('auth.multi_guard')) {
            $selectedGuard = $guard ?? config('auth.defaults.guard', 'web');
            foreach (['web', 'admin', 'owner', 'staff', 'customer'] as $candidateGuard) {
                if ($candidateGuard !== $selectedGuard) {
                    Auth::guard($candidateGuard)->logout();
                }
            }
        }

        return parent::actingAs($user, $guard);
    }

    public function withSession(array $data)
    {
        if (config('auth.multi_guard')) {
            $guard = Auth::getDefaultDriver();
            if ($guard === 'web') {
                $guard = 'customer';
            }

            foreach (self::GUARD_SCOPED_SESSION_KEYS as $key) {
                if (array_key_exists($key, $data)) {
                    $data[$key.'_'.$guard] = $data[$key];
                    unset($data[$key]);
                }
            }
        }

        return parent::withSession($data);
    }
}
