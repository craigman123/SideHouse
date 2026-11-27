<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Support\ActivityLogger;
use App\Support\GuardSessionState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('user.profile.profile', [
            'profileLayout' => 'staff.layouts.app',
            'profileTitle' => 'Staff Profile | Side House',
            'profileStylesheets' => [
                'css/shared/dashboard-base.css',
                'css/staff/staff-profile.css',
                'css/user/profile.css',
            ],
            'profileScripts' => ['js/staff/staff-profile.js'],
            'profileSecurityView' => 'partials.profile.security.staff',
            'profileUpdateAction' => route('staff.profile.update'),
            'profilePasswordAction' => route('staff.profile.password'),
            'canChangePassword' => ! (
                $user->role === 'admin'
                && $request->session()->has(GuardSessionState::key('view_as', 'admin'))
            ),
            'profilePageId' => 'staffProfilePage',
            'profileDomIds' => [
                'form' => 'staffProfileForm',
                'name' => 'staffName',
                'email' => 'staffEmail',
                'phone' => 'staffPhone',
                'edit' => 'staffProfileEditButton',
                'cancel' => 'staffProfileCancelButton',
                'save' => 'staffProfileSaveButton',
                'logoutForm' => 'staffLogoutForm',
                'logoutButton' => 'staffLogoutButton',
                'logoutModal' => 'staffLogoutModal',
                'logoutTitle' => 'staffLogoutModalTitle',
                'logoutClose' => 'staffLogoutModalClose',
                'logoutDescription' => 'staffLogoutModalDescription',
                'logoutCancel' => 'staffLogoutModalCancel',
                'logoutConfirm' => 'staffLogoutConfirm',
            ],
            'logoutModalInitiallyHidden' => true,
            'canEditEmail' => false,
            'canEditUsername' => false,
            'showUsername' => false,
            'showMembership' => false,
            'showDelete' => false,
            'user' => $user,
            'userName' => $user->name,
            'logoutDescription' => "You'll need to sign in again to access the staff dashboard.",
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
        return app(\App\Http\Controllers\ProfilePasswordController::class)->update($request);
    }

}
