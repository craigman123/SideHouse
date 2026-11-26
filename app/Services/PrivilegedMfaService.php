<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class PrivilegedMfaService
{
    private const PRIVILEGED_ROLES = ['admin', 'owner', 'staff'];

    public function afterSuccessfulLogin(User $user): void
    {
        if (! in_array($user->role, self::PRIVILEGED_ROLES, true)) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            if (! in_array($target->role, self::PRIVILEGED_ROLES, true) || $target->mfa_admin_disabled_until === null) {
                return;
            }

            $target->forceFill([
                'mfa_admin_disabled_until' => null,
                'mfa_reenroll_required' => true,
            ])->save();
        });
    }

    public function applyExpiredDisable(User $user): User
    {
        if (
            ! in_array($user->role, self::PRIVILEGED_ROLES, true)
            || $user->mfa_admin_disabled_until === null
            || $user->mfa_admin_disabled_until->isFuture()
        ) {
            return $user;
        }

        return DB::transaction(function () use ($user): User {
            $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            if (
                $target->mfa_admin_disabled_until !== null
                && $target->mfa_admin_disabled_until->lessThanOrEqualTo(now())
            ) {
                $target->forceFill([
                    'mfa_admin_disabled_until' => null,
                    'mfa_reenroll_required' => true,
                ])->save();
            }

            return $target;
        });
    }

    public function isReenrollmentRequired(User $user): bool
    {
        return $user->mfa_reenroll_required
            || ($user->mfa_admin_disabled_until !== null && $user->mfa_admin_disabled_until->lessThanOrEqualTo(now()));
    }

    public function state(User $user): array
    {
        if ($user->mfa_admin_disabled_until !== null && $user->mfa_admin_disabled_until->isFuture()) {
            $seconds = now()->diffInSeconds($user->mfa_admin_disabled_until);
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            return [
                'key' => 'disabled',
                'label' => sprintf('Disabled (%dh %02dm left)', $hours, $minutes),
                'disabled_until' => $user->mfa_admin_disabled_until->toIso8601String(),
            ];
        }

        if ($this->isReenrollmentRequired($user)) {
            return [
                'key' => 'reenrollment_required',
                'label' => 'Re-enrollment required',
                'disabled_until' => null,
            ];
        }

        if ($user->hasMfaEnabled()) {
            return [
                'key' => 'enabled',
                'label' => 'Enabled',
                'disabled_until' => null,
            ];
        }

        return [
            'key' => 'pending_setup',
            'label' => 'Pending setup',
            'disabled_until' => null,
        ];
    }

    public function allowedMethods(User $user): array
    {
        return match ($user->mfa_allowed_methods) {
            'both' => ['totp', 'passkey'],
            'passkey' => ['passkey'],
            default => ['totp'],
        };
    }

    public function allowedMethodsLabel(User $user): string
    {
        return match ($user->mfa_allowed_methods) {
            'both' => 'TOTP and passkey',
            'passkey' => 'Passkey only',
            default => 'TOTP only',
        };
    }

    public function verifyFreshTotp(User $actor, string $code): int
    {
        abort_unless(
            $actor->hasMfaEnabled()
                && ($actor->mfa_method ?? 'totp') === 'totp'
                && in_array('totp', $this->allowedMethods($actor), true),
            409,
            'An active TOTP method is required for this action.',
        );

        $step = (new Google2FA())->verifyKeyNewer(
            $actor->mfa_secret,
            $code,
            $actor->mfa_last_admin_action_step === null
                ? 0
                : (int) $actor->mfa_last_admin_action_step,
        );

        abort_unless($step !== false, 422, 'Enter a fresh current authenticator code.');

        return (int) $step;
    }

    public function verifyAndConsumeCode(User $user, string $code): array
    {
        if ($user->mfa_secret && (new Google2FA())->verifyKey($user->mfa_secret, $code)) {
            return ['valid' => true, 'recovery_code_used' => false];
        }

        $codes = $user->mfa_recovery_codes;
        if (! is_array($codes)) {
            return ['valid' => false, 'recovery_code_used' => false];
        }

        foreach ($codes as $index => $storedCode) {
            if (! is_string($storedCode)) {
                continue;
            }

            $matches = str_starts_with($storedCode, '$')
                ? Hash::check($code, $storedCode)
                : hash_equals($storedCode, $code);

            if (! $matches) {
                continue;
            }

            unset($codes[$index]);
            $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

            return ['valid' => true, 'recovery_code_used' => true];
        }

        return ['valid' => false, 'recovery_code_used' => false];
    }
}
