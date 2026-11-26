<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CustomerMfaService;
use App\Support\ActivityLogger;
use App\Support\GuardSessionState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerMfaController extends Controller
{
    public function setup(Request $request, CustomerMfaService $mfa): RedirectResponse
    {
        $user = $this->customer($request);
        abort_if($user->hasMfaEnabled(), 409, 'MFA is already enabled.');

        $request->session()->put(GuardSessionState::key('mfa_setup_voluntary'), true);
        $mfa->beginSetup($request, $user);

        return redirect()->to(role_route('customer.mfa.setup'));
    }

    public function cancelSetup(Request $request): RedirectResponse
    {
        $this->customer($request);
        abort_unless((bool) $request->session()->get(GuardSessionState::key('mfa_setup_voluntary')), 403);

        $request->session()->forget([
            GuardSessionState::key('mfa_temp_secret'),
            GuardSessionState::key('mfa_temp_secret_user_id'),
            GuardSessionState::key('mfa_setup_voluntary'),
        ]);

        return redirect()->route('user.profile')->with('success', 'MFA setup cancelled.');
    }

    public function enable(Request $request, CustomerMfaService $mfa): RedirectResponse
    {
        $user = $this->customer($request);
        $validated = $request->validate([
            'mfa_code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);
        $secret = $mfa->temporarySecret($request, $user);

        if ($secret === null) {
            return back()->withErrors(['mfa_code' => 'Your setup expired. Please start again.']);
        }

        $codes = $mfa->generateRecoveryCodes();
        $enabled = DB::transaction(function () use ($mfa, $request, $user, $validated, $secret, $codes): bool {
            $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($target->role === 'user', 403);

            if ($target->hasMfaEnabled()) {
                abort(409, 'MFA is already enabled.');
            }

            if (!(new \PragmaRX\Google2FA\Google2FA())->verifyKey($secret, $validated['mfa_code'])) {
                return false;
            }

            $target->forceFill([
                'mfa_enabled' => true,
                'mfa_method' => 'totp',
                'mfa_secret' => $secret,
                'mfa_recovery_codes' => $codes['hashed'],
            ])->save();

            return true;
        });

        if (!$enabled) {
            return back()->withErrors(['mfa_code' => 'Invalid authentication code.']);
        }

        $request->session()->forget([
            GuardSessionState::key('mfa_temp_secret'),
            GuardSessionState::key('mfa_temp_secret_user_id'),
        ]);
        $request->session()->put([
            GuardSessionState::key('mfa_passed_at') => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id') => $user->user_id,
        ]);

        ActivityLogger::log(
            'customer.mfa_enabled',
            "{$user->name} enabled customer MFA.",
            actor: $user,
            subject: $user,
        );

        return redirect()->route('user.profile')
            ->with('success', 'Authenticator app enabled.')
            ->with('customer_mfa_recovery_codes', $codes['plain']);
    }

    public function disable(Request $request, CustomerMfaService $mfa): RedirectResponse
    {
        $user = $this->customer($request);
        $validated = $request->validate(['mfa_code' => ['required', 'string']]);

        $result = DB::transaction(function () use ($mfa, $user, $validated): array {
            $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($target->role === 'user', 403);

            if (!$target->hasMfaEnabled()) {
                return ['valid' => false, 'recovery_code_used' => false];
            }

            $verification = $mfa->verifyAndConsumeCode($target, $validated['mfa_code']);
            if (!$verification['valid']) {
                return $verification;
            }

            $target->forceFill([
                'mfa_enabled' => false,
                'mfa_method' => null,
                'mfa_secret' => null,
                'mfa_recovery_codes' => null,
            ])->save();

            return $verification;
        });

        if (!$result['valid']) {
            return back()->withErrors(['mfa_code' => 'Invalid authentication or recovery code.']);
        }

        if ($result['recovery_code_used']) {
            $this->logRecoveryCodeUse($user, 'disable');
        }

        $request->session()->forget([
            GuardSessionState::key('mfa_passed_at'),
            GuardSessionState::key('mfa_passed_user_id'),
        ]);

        ActivityLogger::log(
            'customer.mfa_disabled',
            "{$user->name} disabled customer MFA.",
            actor: $user,
            subject: $user,
        );

        return redirect()->route('user.profile')->with('success', 'Authenticator app disabled.');
    }

    public function regenerateRecoveryCodes(Request $request, CustomerMfaService $mfa): RedirectResponse
    {
        $user = $this->customer($request);
        $validated = $request->validate(['mfa_code' => ['required', 'string']]);
        $codes = $mfa->generateRecoveryCodes();

        $result = DB::transaction(function () use ($mfa, $user, $validated, $codes): array {
            $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($target->role === 'user', 403);

            if (!$target->hasMfaEnabled()) {
                return ['valid' => false, 'recovery_code_used' => false];
            }

            $verification = $mfa->verifyAndConsumeCode($target, $validated['mfa_code']);
            if (!$verification['valid']) {
                return $verification;
            }

            $target->forceFill(['mfa_recovery_codes' => $codes['hashed']])->save();

            return $verification;
        });

        if (!$result['valid']) {
            return back()->withErrors(['mfa_code' => 'Invalid authentication or recovery code.']);
        }

        if ($result['recovery_code_used']) {
            $this->logRecoveryCodeUse($user, 'regenerate');
        }

        ActivityLogger::log(
            'customer.mfa_recovery_codes_regenerated',
            "{$user->name} regenerated customer MFA recovery codes.",
            actor: $user,
            subject: $user,
        );

        return redirect()->route('user.profile')
            ->with('success', 'Recovery codes regenerated.')
            ->with('customer_mfa_recovery_codes', $codes['plain']);
    }

    private function customer(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->role === 'user', 403);
        abort_if($request->exists('user_id') || $request->exists('target_user_id'), 403);

        if (config('auth.multi_guard')) {
            abort_unless(GuardSessionState::currentGuard() === 'customer', 403);
        }

        return $user;
    }

    private function logRecoveryCodeUse(User $user, string $context): void
    {
        ActivityLogger::log(
            'customer.mfa_recovery_code_used',
            "{$user->name} used a customer MFA recovery code.",
            actor: $user,
            subject: $user,
            properties: ['context' => $context],
        );
    }
}
