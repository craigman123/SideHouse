<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\Admin_DashboardController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\ConfigurationController;
use App\Http\Controllers\Admin\CourtController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\EquipmentAvailabilityController;
use App\Http\Controllers\Admin\UserAccessController;
use App\Services\DashboardOverviewData;
use App\Support\GuardSessionState;
use Illuminate\View\View;
use Illuminate\Http\Request;

class OwnerPageController
{
    public function dashboard(DashboardOverviewData $overview): View
    {
        return view('owner.dashboard', $overview->forOwner() + ['ownerView' => true]);
    }

    public function bookings(): View
    {
        return $this->page(BookingController::class, 'owner.bookings');
    }

    public function courts(): View
    {
        return $this->page(CourtController::class, 'owner.courts');
    }

    public function configuration(): View
    {
        return $this->page(ConfigurationController::class, 'owner.configuration');
    }

    public function profile(Request $request): View
    {
        $user = $request->user();

        return view('user.profile.profile', [
            'profileLayout' => 'owner.layouts.app',
            'profileTitle' => 'My Profile | Owner | Side House',
            'profileStylesheets' => [
                'css/owner/owner-base.css',
                'css/user/profile.css',
            ],
            'profileScripts' => [],
            'profileSecurityView' => 'partials.management.privileged-mfa',
            'profileUpdateAction' => route('owner.profile.update'),
            'profilePasswordAction' => route('owner.profile.password'),
            'canChangePassword' => ! (
                $user->role === 'admin'
                && $request->session()->has(GuardSessionState::key('view_as', 'admin'))
            ),
            'profilePageId' => 'profilePage',
            'canEditEmail' => true,
            'canEditUsername' => true,
            'showUsername' => true,
            'showMembership' => false,
            'showDelete' => true,
            'deleteAccountAction' => route('admin.profile.destroy'),
            'deleteZoneDescription' => "Permanently delete your admin account. This can't be undone.",
            'deleteModalTitle' => 'Delete your account?',
            'deleteModalDescription' => "This permanently deletes your admin account and can't be undone. You will lose access to the admin dashboard and all its data. If you're the only admin, make sure someone else has access first.",
            'deleteCancelLabel' => 'Cancel',
            'deleteSubmitLabel' => 'Delete Account',
            'logoutDescription' => "You'll need to sign in again to access the owner dashboard.",
            'user' => $user,
            'userName' => $user->name,
        ]);
    }

    public function updateProfile(Request $request)
    {
        return app(AdminProfileController::class)->updateProfile($request);
    }

    public function equipment(): View
    {
        return $this->page(EquipmentAvailabilityController::class, 'owner.equipment');
    }

    public function customers(): View
    {
        return $this->page(CustomerController::class, 'owner.customers');
    }

    public function userAccess(): View
    {
        return $this->page(UserAccessController::class, 'owner.user-access', 'page');
    }

    public function announcements(): View
    {
        return $this->page(AnnouncementController::class, 'owner.announcements');
    }

    public function reports(): View
    {
        return view('owner.reports', ['ownerView' => true]);
    }

    public function activityLogs(): View
    {
        return $this->page(ActivityLogController::class, 'owner.activity-logs');
    }

    private function page(string $controller, string $view, string $method = 'index'): View
    {
        $source = app()->call([app($controller), $method]);
        $data = $source->getData();
        $data['ownerView'] = true;

        return view($view, $data);
    }
}
