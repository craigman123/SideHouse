<?php

use App\Http\Controllers\Owner\OwnerPageController;
use App\Http\Controllers\Admin\PrivilegedMfaController;
use App\Http\Controllers\Admin\MembershipPlanController;
use App\Http\Controllers\ProfilePasswordController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'owner.only', 'admin.mfa', 'throttle.admin-management:120,1'])
    ->prefix('owner')
    ->name('owner.')
    ->controller(OwnerPageController::class)
    ->group(function () {
        Route::get('/dashboard', 'dashboard')->name('dashboard');
        Route::get('/bookings', 'bookings')->name('bookings');
        Route::get('/courts', 'courts')->name('courts');
        Route::get('/configuration', 'configuration')->name('configuration');
        Route::get('/memberships', [MembershipPlanController::class, 'ownerIndex'])->name('memberships.index');
        Route::post('/memberships', [MembershipPlanController::class, 'ownerStore'])
            ->middleware('throttle:5,1,membership-plan-store')
            ->name('memberships.store');
        Route::get('/profile', 'profile')->name('profile');
        Route::put('/profile', 'updateProfile')->middleware('throttle.admin-management:10,1')->name('profile.update');
        Route::put('/profile/password', [ProfilePasswordController::class, 'update'])
            ->middleware(['throttle.admin-management:10,1', 'throttle:5,1,profile-password:'])
            ->name('profile.password');
        Route::post('/profile/mfa/recovery-codes', [PrivilegedMfaController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:5,1')->name('profile.mfa.recovery-codes');
        Route::get('/equipment', 'equipment')->name('equipment');
        Route::get('/customers', 'customers')->name('customers');
        Route::get('/user-access', 'userAccess')->middleware('can.access:user_access.manage')->name('user-access');
        Route::get('/announcements', 'announcements')->name('announcements');
        Route::get('/reports', 'reports')->name('reports');
        Route::get('/activity-logs', 'activityLogs')->name('activity-logs');
    });
