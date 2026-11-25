<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\GuardSessionState;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AdminProfileController extends Controller
{
    public function profile()
    {
        return view('admin.profile.admin-profile', [
            'user' => auth()->user(),
        ]);
    }

    /**
     * Update the signed-in admin's own profile fields. Same rules as the
     * user-facing version — deliberately doesn't accept 'role' from input,
     * so this can never be used to self-promote or demote.
     */
    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255',
                Rule::unique('users', 'username')->ignore($user->user_id, 'user_id'),
            ],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
        ]);

        $originalName = $user->name;

        $user->update($validated);

        ActivityLogger::log(
            'profile.updated',
            "{$originalName} updated their profile.",
            subject: $user,
            properties: ['changed_fields' => array_keys($user->getChanges())],
        );

        $profileRoute = config('auth.multi_guard')
            ? match (GuardSessionState::currentGuard()) {
                'owner' => 'owner.profile',
                'staff' => 'staff.profile',
                default => 'admin.profile',
            }
            : 'admin.profile';

        return redirect()
            ->route($profileRoute)
            ->with('success', 'Profile updated.');
    }

    /**
     * Permanently delete the signed-in admin's own account. Same typed-
     * confirmation check as the user-facing version — this is the real
     * security gate, not just the client-side button disable.
     */
    public function destroyAccount(Request $request)
    {
        abort_unless(auth()->user()?->role === 'user', 403, 'Privileged accounts cannot delete themselves.');

        $request->validate([
            'confirmation' => ['required', 'string'],
        ]);

        if ($request->input('confirmation') !== 'DELETE MY ACCOUNT') {
            return back()->withErrors([
                'confirmation' => 'Type "DELETE MY ACCOUNT" exactly to confirm.',
            ]);
        }

        $user = auth()->user();

        ActivityLogger::log(
            'account.deleted',
            "{$user->name} deleted their own account.",
            actor: $user,
            subject: $user,
        );

        $multiGuard = config('auth.multi_guard');
        $guard = GuardSessionState::currentGuard();
        if ($multiGuard) {
            Auth::guard($guard)->logout();
            GuardSessionState::forgetGuard($request, $guard);
        } else {
            $request->session()->forget(GuardSessionState::key('view_as'));
            auth()->logout();
        }

        $user->delete();

        if ($multiGuard) {
            $request->session()->regenerate();
            $loginRoute = $guard === 'customer' ? 'login' : $guard.'.login';

            return redirect()->route($loginRoute);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
