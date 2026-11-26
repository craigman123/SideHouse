<?php

namespace App\Services;

use App\Models\User;
use App\Support\GuardSessionState;
use App\Support\QrCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class CustomerMfaService
{
    public function beginSetup(Request $request, User $user): void
    {
        $secret = $this->temporarySecret($request, $user);

        if ($secret === null) {
            $secret = (new Google2FA())->generateSecretKey();
            $request->session()->put([
                GuardSessionState::key('mfa_temp_secret') => $secret,
                GuardSessionState::key('mfa_temp_secret_user_id') => $user->user_id,
            ]);
        }
    }

    public function setupDetails(Request $request, User $user): array
    {
        if ($user->role !== 'user' || $user->hasMfaEnabled()) {
            return ['secret' => null, 'qrCodeSvg' => null];
        }

        $secret = $this->temporarySecret($request, $user);
        if ($secret === null) {
            return ['secret' => null, 'qrCodeSvg' => null];
        }

        $google2fa = new Google2FA();
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email ?? $user->username,
            $secret,
        );

        return [
            'secret' => $secret,
            'qrCodeSvg' => QrCodeGenerator::svg($qrCodeUrl),
        ];
    }

    public function temporarySecret(Request $request, User $user): ?string
    {
        if ((int) $request->session()->get(GuardSessionState::key('mfa_temp_secret_user_id')) !== (int) $user->user_id) {
            $request->session()->forget([
                GuardSessionState::key('mfa_temp_secret'),
                GuardSessionState::key('mfa_temp_secret_user_id'),
            ]);

            return null;
        }

        $secret = $request->session()->get(GuardSessionState::key('mfa_temp_secret'));

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function verifyAndConsumeCode(User $user, string $code): array
    {
        if ($user->mfa_secret && (new Google2FA())->verifyKey($user->mfa_secret, $code)) {
            return ['valid' => true, 'recovery_code_used' => false];
        }

        $codes = $user->mfa_recovery_codes;
        if (!is_array($codes)) {
            return ['valid' => false, 'recovery_code_used' => false];
        }

        foreach ($codes as $index => $storedCode) {
            if (!is_string($storedCode)) {
                continue;
            }

            $matches = str_starts_with($storedCode, '$')
                ? Hash::check($code, $storedCode)
                : hash_equals($storedCode, $code);

            if (!$matches) {
                continue;
            }

            unset($codes[$index]);
            $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

            return ['valid' => true, 'recovery_code_used' => true];
        }

        return ['valid' => false, 'recovery_code_used' => false];
    }

    public function generateRecoveryCodes(): array
    {
        $plainCodes = collect(range(1, 8))
            ->map(static fn () => Str::random(10))
            ->values()
            ->all();

        return [
            'plain' => $plainCodes,
            'hashed' => array_map(static fn (string $code): string => Hash::make($code), $plainCodes),
        ];
    }
}
