<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Rules\CurrentPassword;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        return view('staff.profile', [
            'user' => $request->user(),
            'mfaEnabled' => $request->user()->hasMfaEnabled(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
        ]);

        $user = $request->user();
        $oldName = $user->name;
        $user->update($validated);
        ActivityLogger::log('staff.profile_updated', "{$oldName} updated their staff profile.", subject: $user);

        return back()->with('success', 'Profile updated.');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', new CurrentPassword($request->user())],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $validated['password'], 'remember_token' => Str::random(60)])->save();
        ActivityLogger::log('staff.password_changed', "{$user->name} changed their password.", subject: $user);

        return back()->with('success', 'Password updated.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', new CurrentPassword($request->user())]]);
        $user = $request->user();
        abort_unless($user->hasMfaEnabled(), 409, 'Set up MFA before regenerating recovery codes.');

        $codes = collect(range(1, 8))->map(fn () => Str::random(10))->values()->all();
        $user->forceFill(['mfa_recovery_codes' => $codes])->save();
        ActivityLogger::log('staff.mfa_recovery_codes_regenerated', "{$user->name} regenerated MFA recovery codes.", subject: $user);

        return back()->with('recovery_codes', $codes);
    }
}
