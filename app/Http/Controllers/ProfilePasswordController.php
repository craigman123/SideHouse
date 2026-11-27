<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\GuardSessionState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProfilePasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $authenticatedUser = $request->user();
        abort_unless($authenticatedUser instanceof User, 401);

        $viewAsKey = GuardSessionState::key('view_as', 'admin');
        abort_if(
            $authenticatedUser->role === 'admin' && $request->session()->has($viewAsKey),
            403,
            'Password changes are unavailable while switched access is active.'
        );

        $passwordGuard = config('auth.multi_guard') ? GuardSessionState::currentGuard() : 'web';
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:'.$passwordGuard],
            'password' => ['required', 'string', 'min:12', 'confirmed', 'different:current_password'],
        ]);

        $user = User::query()->findOrFail($authenticatedUser->getKey());
        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $event = $user->role === 'staff' ? 'staff.password_changed' : 'profile.password_changed';
        ActivityLogger::log($event, "{$user->name} changed their password.", subject: $user);

        return back()->with('success', 'Password updated.');
    }
}
