<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\Admin_DashboardController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\CourtController;
use App\Http\Controllers\Admin\ConfigurationController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Guest\GuestBookingController;
use App\Http\Controllers\User\NotificationController;
use App\Http\Controllers\User\User_UserController;
use App\Http\Controllers\User\UserDashboardController;
use App\Http\Controllers\Webhooks\GcashWebhookController;
use App\Http\Controllers\Webhooks\LandbankWebhookController;
use App\Http\Controllers\Admin\EquipmentAvailabilityController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GuestBookingController::class, 'landing'])->name('landing');

Route::get('/book/monthly-stats', [GuestBookingController::class, 'monthlyStats'])->name('guest.book.monthly-stats');
Route::get('/guest/bookings/{booking}/status', [GuestBookingController::class, 'status'])->name('guest.book.status');
Route::get('/guest/bookings/search', [GuestBookingController::class, 'search'])->name('guest.book.search');
Route::post('/guest/bookings/{booking}/cancel', [GuestBookingController::class, 'cancel'])->name('guest.book.cancel');
Route::put('/guest/bookings/{booking}/reference', [GuestBookingController::class, 'updateReference'])->name('guest.book.update-reference');
Route::post('/webhooks/gcash-sms', [GcashWebhookController::class, 'handleSms']);
Route::post('/webhooks/landbank-sms', [LandbankWebhookController::class, 'handleSms']);

// Guest booking (no login required)
Route::get('/guest-book/availability', [GuestBookingController::class, 'availability'])->name('guest.book.availability');
Route::get('/guest-book/equipment-availability', [GuestBookingController::class, 'equipmentAvailability'])->name('guest.book.equipment-availability');
Route::post('/guest-book', [GuestBookingController::class, 'store'])->name('guest.book.store');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.submit');

Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->name('register.submit');

Route::post('/auth/google', [AuthController::class, 'googleAuth'])->middleware('throttle:15,1')->name('auth.google');

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [Admin_DashboardController::class, 'index'])->name('admin.dashboard');

    // Bookings CRUD
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');

    // Courts CRUD
    Route::get('/courts', [CourtController::class, 'index'])->name('courts.index');
    Route::post('/courts', [CourtController::class, 'store'])->name('courts.store');
    Route::put('/courts/{court}', [CourtController::class, 'update'])->name('courts.update');
    Route::delete('/courts/{court}', [CourtController::class, 'destroy'])->name('courts.destroy');

    // Activity Logs
    Route::get('/activity_logs', [ActivityLogController::class, 'index'])->name('activity_logs.index');

    //Announcements
    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('admin.announcements.index');
    Route::get('/announcements/create', [AnnouncementController::class, 'create'])->name('admin.announcements.create');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->name('admin.announcements.store');
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('admin.announcements.destroy');

    // Customers
    Route::get('/customers', [CustomerController::class, 'index'])->name('admin.customers.index');
    Route::get('/customers/data', [CustomerController::class, 'data'])->name('admin.customers.data');

    // Profile
    Route::get('/admin/profile', [AdminProfileController::class, 'profile'])->name('admin.profile');
    Route::put('/admin/profile', [AdminProfileController::class, 'updateProfile'])->name('admin.profile.update');
    Route::delete('/admin/profile', [AdminProfileController::class, 'destroyAccount'])->name('admin.profile.destroy');

    // Reports
    Route::get('/reports', [ReportController::class, 'index'])->name('admin.reports.index');
    Route::get('/reports/data', [ReportController::class, 'data'])->name('admin.reports.data');
    Route::get('/reports/system', [ReportController::class, 'system'])->name('admin.reports.system');

    // Configuration / Schedule
    Route::get('/admin/configuration', [ConfigurationController::class, 'index'])->name('admin.configuration.index');
    Route::put('/admin/configuration/hours', [ConfigurationController::class, 'updateHours'])->name('admin.configuration.hours.update');
    Route::post('/admin/configuration/closures', [ConfigurationController::class, 'storeClosure'])->name('admin.configuration.closures.store');
    Route::delete('/admin/configuration/closures/{closure}', [ConfigurationController::class, 'destroyClosure'])->name('admin.configuration.closures.destroy');

    //Equipment
    Route::get('/admin/equipment/availability', [EquipmentAvailabilityController::class, 'index'])->name('admin.equipment.availability');
    Route::get('/admin/equipment/availability/data', [EquipmentAvailabilityController::class, 'data'])->name('admin.equipment.availability.data');
    Route::post('/equipment', [EquipmentAvailabilityController::class, 'store'])->name('admin.equipment.store');
    Route::put('/equipment/{equipment}', [EquipmentAvailabilityController::class, 'update'])->name('admin.equipment.update');
    Route::delete('/equipment/{equipment}', [EquipmentAvailabilityController::class, 'destroy'])->name('admin.equipment.destroy');

});

Route::middleware('auth')->group(function () {
    Route::get('/my-dashboard', [UserDashboardController::class, 'index'])->name('user.dashboard');

    // Book a court
    Route::get('/book', [User_UserController::class, 'createBooking'])->name('book.index');
    Route::get('/book/availability', [User_UserController::class, 'availability'])->name('book.availability');
    Route::get('/book/equipment-availability', [User_UserController::class, 'equipmentAvailability'])->name('book.equipment-availability');
    Route::get('/book/bookings/{booking}/status', [User_UserController::class, 'bookingStatus'])->name('book.status');
    Route::post('/book', [User_UserController::class, 'storeBooking'])->name('book.store');

    // Booking history
    Route::get('/my-bookings', [User_UserController::class, 'myBookings'])->name('user.bookings.index');
    Route::post('/my-bookings/{booking}/cancel', [User_UserController::class, 'cancelBooking'])->name('user.bookings.cancel');

    // Profile
    Route::get('/profile', [User_UserController::class, 'profile'])->name('user.profile');
    Route::put('/profile', [User_UserController::class, 'updateProfile'])->name('user.profile.update');
    Route::delete('/profile', [User_UserController::class, 'destroyAccount'])->name('user.profile.destroy');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index'])->name('user.notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('user.notifications.unread-count');
    Route::post('/notifications/{notification}/mark-read', [NotificationController::class, 'markRead'])->name('user.notifications.mark-read');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('user.notifications.mark-all-read');
    
});