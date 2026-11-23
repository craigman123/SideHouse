<?php

use App\Http\Controllers\Staff\BookingsController;
use App\Http\Controllers\Staff\CustomersController;
use App\Http\Controllers\Staff\ActivityLogsController;
use App\Http\Controllers\Staff\OverviewController;
use App\Http\Controllers\Staff\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'staff', 'admin.mfa', 'throttle:120,1'])
    ->prefix('staff')
    ->name('staff.')
    ->group(function () {
        Route::get('/dashboard', [OverviewController::class, 'index'])->name('dashboard');
        Route::get('/overview/data', [OverviewController::class, 'data'])->name('overview.data');

        Route::get('/bookings', [BookingsController::class, 'index'])
            ->middleware('can.access:bookings.view,calendar_events.open_play')
            ->name('bookings');
        Route::get('/customers', [CustomersController::class, 'index'])
            ->middleware('can.access:customers.view')
            ->name('customers');
        Route::get('/activity-logs', [ActivityLogsController::class, 'index'])
            ->middleware(['can.access:activity_logs.view', 'throttle:60,1'])
            ->name('activity-logs');
        Route::get('/activity-logs/data', [ActivityLogsController::class, 'data'])
            ->middleware(['can.access:activity_logs.view', 'throttle:60,1'])
            ->name('activity-logs.data');

        Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
        Route::put('/profile', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'changePassword'])->middleware('throttle:10,1')->name('profile.password');
        Route::post('/profile/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:5,1')->name('profile.recovery-codes');
    });
