<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\CourtController;
use App\Http\Controllers\Admin\Admin_DashboardController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\UserDashboardController;
use App\Http\Controllers\User\User_UserController;

Route::get('/', function () {
    return view('landing');
})->name('landing');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');

Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->name('register.submit');

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [Admin_DashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');

    // Courts CRUD
    Route::get('/courts', [CourtController::class, 'index'])->name('courts.index');
    Route::post('/courts', [CourtController::class, 'store'])->name('courts.store');
    Route::put('/courts/{court}', [CourtController::class, 'update'])->name('courts.update');
    Route::delete('/courts/{court}', [CourtController::class, 'destroy'])->name('courts.destroy');

    // Activity log audit trail
    Route::get('/activity_logs', [ActivityLogController::class, 'index'])->name('activity_logs.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/my-dashboard', [UserDashboardController::class, 'index'])->name('user.dashboard');

    // Book a court
    Route::get('/book', [User_UserController::class, 'createBooking'])->name('book.index');
    Route::get('/book/availability', [User_UserController::class, 'availability'])->name('book.availability');
    Route::post('/book', [User_UserController::class, 'storeBooking'])->name('book.store');

    // Booking history
    Route::get('/my-bookings', [User_UserController::class, 'myBookings'])->name('user.bookings.index');
    Route::post('/my-bookings/{booking}/cancel', [User_UserController::class, 'cancelBooking'])->name('user.bookings.cancel');

    // Profile
    Route::get('/profile', [User_UserController::class, 'profile'])->name('user.profile');
    Route::put('/profile', [User_UserController::class, 'updateProfile'])->name('user.profile.update');
    Route::delete('/profile', [User_UserController::class, 'destroyAccount'])->name('user.profile.destroy');
});