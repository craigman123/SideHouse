<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CustomerMfaService;
use App\Services\PrivilegedMfaService;
use App\Support\ActivityLogger;
use App\Support\GuardSessionState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Str;
use App\Support\QrCodeGenerator;

class MfaController extends Controller
{
    public function setup(Request $request)
    {
        $user = $request->user();
        $privilegedMfa = app(PrivilegedMfaService::class);
        if ($user->requiresMfa()) {
            $user = $privilegedMfa->applyExpiredDisable($user);
        }

        if ($user->hasMfaEnabled() && ! $privilegedMfa->isReenrollmentRequired($user)) {
            return redirect($this->homePath($user));
        }

        $google2fa = new Google2FA();

        $secret = $this->tempSecretFor($user);

        if (!$secret) {
            $secret = $google2fa->generateSecretKey();
            session([
                GuardSessionState::key('mfa_temp_secret') => $secret,
                GuardSessionState::key('mfa_temp_secret_user_id') => $user->user_id,
            ]);
        }

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email ?? $user->username,
            $secret
        );

        $qrCodeSvg = QrCodeGenerator::svg($qrCodeUrl);

        return view('auth.mfa-setup', [
            'qrCodeSvg' => $qrCodeSvg,
            'secret'    => $secret,
            'qrCodeUrl' => $qrCodeUrl,
            'mfaEnableUrl' => route(mfa_route_name('mfa.enable')),
            'customerCancelUrl' => $user->role === 'user'
                && (bool) session(GuardSessionState::key('mfa_setup_voluntary'))
                ? role_route('customer.mfa.setup.cancel')
                : null,
        ]);
    }

    public function initSetup(Request $request)
    {
        $user = $request->user();
        $privilegedMfa = app(PrivilegedMfaService::class);
        if ($user->requiresMfa()) {
            $user = $privilegedMfa->applyExpiredDisable($user);
        }

        if ($user->hasMfaEnabled() && ! $privilegedMfa->isReenrollmentRequired($user)) {
            return response()->json(['message' => 'MFA already enabled'], 400);
        }

        $google2fa = new Google2FA();

        $secret = $this->tempSecretFor($user);

        if (!$secret) {
            $secret = $google2fa->generateSecretKey();
            session([
                GuardSessionState::key('mfa_temp_secret') => $secret,
                GuardSessionState::key('mfa_temp_secret_user_id') => $user->user_id,
            ]);
        }

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email ?? $user->username,
            $secret
        );

        return response()->json([
            'secret'    => $secret,
            'qrCodeSvg' => QrCodeGenerator::svg($qrCodeUrl),
        ]);
    }

    public function enable(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();
        $privilegedMfa = app(PrivilegedMfaService::class);
        if ($user->requiresMfa()) {
            $user = $privilegedMfa->applyExpiredDisable($user);
        }

        if ($user->hasMfaEnabled() && ! $privilegedMfa->isReenrollmentRequired($user)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'MFA already enabled'], 400);
            }
            return redirect($this->homePath($user));
        }

        $secret = $this->tempSecretFor($user);
        if (!$secret) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Session expired. Please try again.'], 400);
            }
            return back()->withErrors(['code' => 'Session expired. Please try again.']);
        }

        $google2fa = new Google2FA();
        if (!$google2fa->verifyKey($secret, $request->code)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invalid authentication code.'], 422);
            }
            return back()->withErrors(['code' => 'Invalid authentication code.']);
        }

        $recoveryCodes = collect(range(1, 8))->map(fn () => Str::random(10))->values()->all();
        $storedRecoveryCodes = array_map(static fn (string $code): string => Hash::make($code), $recoveryCodes);

        if ($user->role === 'user') {
            DB::transaction(function () use ($user, $secret, $storedRecoveryCodes, $google2fa, $request): void {
                $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
                abort_unless($target->role === 'user', 403);
                abort_if($target->hasMfaEnabled(), 409, 'MFA is already enabled.');
                abort_unless($google2fa->verifyKey($secret, $request->code), 422, 'Invalid authentication code.');
                $target->update([
                    'mfa_enabled' => true,
                    'mfa_method' => 'totp',
                    'mfa_secret' => $secret,
                    'mfa_recovery_codes' => $storedRecoveryCodes,
                ]);
            });
        } else {
            abort_unless($user->requiresMfa(), 403);
            DB::transaction(function () use ($user, $secret, $storedRecoveryCodes, $google2fa, $request, $privilegedMfa): void {
                $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
                abort_unless($target->requiresMfa(), 403);
                abort_if(
                    $target->mfa_admin_disabled_until !== null,
                    409,
                    'Sign in again before completing MFA re-enrollment.',
                );
                abort_if(
                    $target->hasMfaEnabled() && ! $privilegedMfa->isReenrollmentRequired($target),
                    409,
                    'MFA is already enabled.',
                );
                $allowedMethods = $target->mfa_pending_allowed_methods ?? $target->mfa_allowed_methods ?? 'totp';
                abort_unless(in_array('totp', match ($allowedMethods) {
                    'both' => ['totp', 'passkey'],
                    'passkey' => ['passkey'],
                    default => ['totp'],
                }, true), 409, 'TOTP is not currently allowed for this account.');
                abort_unless($google2fa->verifyKey($secret, $request->code), 422, 'Invalid authentication code.');

                $target->forceFill([
                    'mfa_enabled' => true,
                    'mfa_method' => 'totp',
                    'mfa_secret' => $secret,
                    'mfa_recovery_codes' => $storedRecoveryCodes,
                    'mfa_reenroll_required' => false,
                    'mfa_admin_disabled_until' => null,
                    'mfa_allowed_methods' => $target->mfa_pending_allowed_methods ?? ($target->mfa_allowed_methods ?? 'totp'),
                    'mfa_pending_allowed_methods' => null,
                ])->save();
            });
            $user->refresh();
        }

        if ($user->role === 'user') {
            ActivityLogger::log(
                'customer.mfa_enabled',
                "{$user->name} enabled customer MFA.",
                actor: $user,
                subject: $user,
            );
        }

        session()->forget([
            GuardSessionState::key('mfa_temp_secret'),
            GuardSessionState::key('mfa_temp_secret_user_id'),
            GuardSessionState::key('mfa_setup_voluntary'),
        ]);
        session([
            GuardSessionState::key('mfa_passed_at') => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id') => $user->user_id,
        ]);
        GuardSessionState::regenerateSession($request);

        if ($request->expectsJson()) {
            return response()->json([
                'message'        => 'MFA enabled successfully',
                'recovery_codes' => $recoveryCodes,
                'redirect'       => $this->homePath($user),
                'csrf_token'     => csrf_token(),
            ]);
        }

        return redirect()
            ->to($this->homePath($user))
            ->with('recovery_codes', $recoveryCodes);
    }

    public function challenge(Request $request, PrivilegedMfaService $privilegedMfa)
    {
        $user = $request->user();
        if ($user?->requiresMfa()) {
            $user = $privilegedMfa->applyExpiredDisable($user);
            if ($privilegedMfa->isReenrollmentRequired($user)) {
                return redirect()->route(mfa_route_name('mfa.setup'));
            }
        }

        return view('auth.mfa-challenge', [
            'mfaVerifyUrl' => $user?->role === 'user'
                ? role_route('customer.mfa.verify')
                : route(mfa_route_name('mfa.verify')),
        ]);
    }

    public function verify(
        Request $request,
        CustomerMfaService $customerMfa,
        PrivilegedMfaService $privilegedMfa
    )
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();
        if ($user->requiresMfa()) {
            $user = $privilegedMfa->applyExpiredDisable($user);
            if ($privilegedMfa->isReenrollmentRequired($user)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'MFA re-enrollment is required.',
                        'redirect' => route(mfa_route_name('mfa.setup'), absolute: false),
                    ], 409);
                }

                return redirect()->route(mfa_route_name('mfa.setup'));
            }
        }

        $google2fa = new Google2FA();

        $secret = $user->mfa_secret;

        if (!$secret) {
            $user->update([
                'mfa_enabled'        => false,
                'mfa_secret'         => null,
                'mfa_recovery_codes' => null,
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message'  => 'Your MFA setup needs to be redone. Please set it up again.',
                    'redirect' => route(mfa_route_name('mfa.setup'), absolute: false),
                ], 409);
            }

            return redirect()->route(mfa_route_name('mfa.setup'))
                ->withErrors(['code' => 'Your MFA setup needs to be redone. Please set it up again.']);
        }

        $recoveryCodeUsed = false;
        if ($user->role === 'user') {
            $verification = DB::transaction(function () use ($customerMfa, $request, $user): array {
                $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
                abort_unless($target->role === 'user', 403);

                return $customerMfa->verifyAndConsumeCode($target, $request->code);
            });
            $valid = $verification['valid'];
            $recoveryCodeUsed = $verification['recovery_code_used'];
            $user->refresh();
        } else {
            $verification = DB::transaction(function () use ($privilegedMfa, $request, $user): array {
                $target = User::query()->whereKey($user->user_id)->lockForUpdate()->firstOrFail();
                abort_unless($target->requiresMfa(), 403);
                abort_unless($target->hasMfaEnabled(), 409, 'Set up MFA before verifying a code.');

                return $privilegedMfa->verifyAndConsumeCode($target, $request->code);
            });
            $valid = $verification['valid'];
            $recoveryCodeUsed = $verification['recovery_code_used'];
            $user->refresh();
        }

        if (!$valid) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invalid authentication code.'], 422);
            }
            return back()->withErrors(['code' => 'Invalid authentication code.']);
        }

        if ($recoveryCodeUsed) {
            $customer = $user->role === 'user';
            ActivityLogger::log(
                $customer ? 'customer.mfa_recovery_code_used' : 'privileged_mfa.recovery_code_used',
                $customer
                    ? "{$user->name} used a customer MFA recovery code."
                    : "{$user->name} used a privileged MFA recovery code.",
                actor: $user,
                subject: $user,
                properties: ['context' => 'login'],
            );
        }

        session([
            GuardSessionState::key('mfa_passed_at') => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id') => $user->user_id,
        ]);
        GuardSessionState::regenerateSession($request);

        if ($request->expectsJson()) {
            return response()->json([
                'message'    => 'Verified',
                'redirect'   => $this->homePath($user),
                'csrf_token' => csrf_token(),
            ]);
        }

        return redirect()->intended($this->homePath($user));
    }

    private function homePath(User $user): string
    {
        return match ($user->role) {
            'owner' => route('owner.dashboard', absolute: false),
            'admin' => route('admin.dashboard', absolute: false),
            'staff' => route('staff.dashboard', absolute: false),
            default => route('user.dashboard', absolute: false),
        };
    }

    /**
     * Returns the pending temp secret only if it belongs to this user.
     */
    private function tempSecretFor($user): ?string
    {
        if ((int) session(GuardSessionState::key('mfa_temp_secret_user_id')) !== (int) $user->user_id) {
            session()->forget([
                GuardSessionState::key('mfa_temp_secret'),
                GuardSessionState::key('mfa_temp_secret_user_id'),
            ]);
            return null;
        }

        return session(GuardSessionState::key('mfa_temp_secret'));
    }
}
