<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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
            return redirect()->route('admin.dashboard');
        }

        $google2fa = new Google2FA();

        // Reuse existing secret if the user is still in the middle of setup
        $secret = session('mfa_temp_secret');

        if (!$secret) {
            $secret = $google2fa->generateSecretKey();
            session(['mfa_temp_secret' => $secret]);
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

    // Used by the modal
    public function initSetup(Request $request)
    {
        $user = $request->user();

        if ($user->hasMfaEnabled()) {
            return response()->json(['message' => 'MFA already enabled'], 400);
        }

        $google2fa = new Google2FA();

        // Reuse existing secret if available
        $secret = session('mfa_temp_secret');

        if (!$secret) {
            $secret = $google2fa->generateSecretKey();
            session(['mfa_temp_secret' => $secret]);
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

        $secret = session('mfa_temp_secret');
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

        $request->user()->update([
            'mfa_enabled'        => true,
            'mfa_secret'         => encrypt($secret),
            'mfa_recovery_codes' => $recoveryCodes,
        ]);

        session()->forget('mfa_temp_secret');
        session(['mfa_passed_at' => now()->timestamp]);
        session()->regenerate(); // rotate session ID after successful MFA

        // in enable(), success branch:
        if ($request->expectsJson()) {
            return response()->json([
                'message'        => 'MFA enabled successfully',
                'recovery_codes' => $recoveryCodes,
                'redirect'       => route('admin.dashboard'),
                'csrf_token'     => csrf_token(),
            ]);
        }

        return redirect()
            ->route('admin.dashboard')
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
        $secret = decrypt($user->mfa_secret);

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

        session(['mfa_passed_at' => now()->timestamp]);
        session()->regenerate(); // rotate session ID after successful MFA

        // in verify(), success branch:
        if ($request->expectsJson()) {
            return response()->json([
                'message'    => 'Verified',
                'redirect'   => route('admin.dashboard'),
                'csrf_token' => csrf_token(),
            ]);
        }

        return redirect()->intended(route('admin.dashboard'));
    }
}