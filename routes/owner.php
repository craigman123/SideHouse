<?php

use App\Http\Controllers\Owner\OwnerPageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'owner.only', 'admin.mfa', 'throttle:120,1'])
    ->prefix('owner')
    ->name('owner.')
    ->controller(OwnerPageController::class)
    ->group(function () {
        Route::get('/dashboard', 'dashboard')->name('dashboard');
        Route::get('/bookings', 'bookings')->name('bookings');
        Route::get('/courts', 'courts')->name('courts');
        Route::get('/configuration', 'configuration')->name('configuration');
        Route::get('/profile', 'profile')->name('profile');
        Route::put('/profile', 'updateProfile')->middleware('throttle:10,1')->name('profile.update');
        Route::get('/equipment', 'equipment')->name('equipment');
        Route::get('/customers', 'customers')->name('customers');
        Route::get('/user-access', 'userAccess')->middleware('can.access:user_access.manage')->name('user-access');
        Route::get('/announcements', 'announcements')->name('announcements');
        Route::get('/reports', 'reports')->name('reports');
        Route::get('/activity-logs', 'activityLogs')->name('activity-logs');
    });
