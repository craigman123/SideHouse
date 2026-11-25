<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GuardSessionState;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Str;
use App\Support\QrCodeGenerator;

class MfaController extends Controller
{
    public function setup(Request $request)
    {
        $user = $request->user();

        if ($user->hasMfaEnabled()) {
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
        ]);
    }

    public function initSetup(Request $request)
    {
        $user = $request->user();

        if ($user->hasMfaEnabled()) {
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

        if ($user->hasMfaEnabled()) {
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

        $user->update([
            'mfa_enabled'        => true,
            'mfa_secret'         => $secret,
            'mfa_recovery_codes' => $recoveryCodes,
        ]);

        session()->forget([
            GuardSessionState::key('mfa_temp_secret'),
            GuardSessionState::key('mfa_temp_secret_user_id'),
        ]);
        session([
            GuardSessionState::key('mfa_passed_at') => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id') => $user->user_id,
        ]);
        session()->regenerate();

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

    public function challenge()
    {
        return view('auth.mfa-challenge');
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();
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
                    'redirect' => route('mfa.setup', absolute: false),
                ], 409);
            }

            return redirect()->route('mfa.setup')
                ->withErrors(['code' => 'Your MFA setup needs to be redone. Please set it up again.']);
        }

        $valid = $google2fa->verifyKey($secret, $request->code);

        if (!$valid && is_array($user->mfa_recovery_codes)) {
            if (in_array($request->code, $user->mfa_recovery_codes)) {
                $valid = true;
                $user->update([
                    'mfa_recovery_codes' => array_values(array_diff($user->mfa_recovery_codes, [$request->code])),
                ]);
            }
        }

        if (!$valid) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invalid authentication code.'], 422);
            }
            return back()->withErrors(['code' => 'Invalid authentication code.']);
        }

        session([
            GuardSessionState::key('mfa_passed_at') => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id') => $user->user_id,
        ]);
        session()->regenerate();

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
