<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\Admin_DashboardController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\ConfigurationController;
use App\Http\Controllers\Admin\CourtController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\EquipmentAvailabilityController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ViewAsController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\CustomerMfaController;
use App\Http\Controllers\BookingCronController;
use App\Http\Controllers\Guest\GuestBookingController;
use App\Http\Controllers\Guest\GuestBookingSearchController;
use App\Http\Controllers\Guest\PaymongoQrPhController;
use App\Http\Controllers\Guest\PaymentReceiptController;
use App\Http\Controllers\User\FeedbackController;
use App\Http\Controllers\User\LumiController;
use App\Http\Controllers\User\NotificationController;
use App\Http\Controllers\User\User_UserController;
use App\Http\Controllers\User\UserDashboardController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Maintenance\MaintenanceController;
use App\Http\Controllers\Guest\GuestBookingLookupController;
use App\Http\Controllers\Admin\MetricsController;
use App\Http\Controllers\Guest\GuestCreditController;

Route::get('/guest/booking/latest', [GuestBookingLookupController::class, 'latest'])
    ->name('guest.booking.latest')
    ->middleware('throttle:20,1');

Route::post('/guest/credit/nonce', [GuestCreditController::class, 'nonce'])
    ->middleware('throttle:30,1,guest-credit-nonce')
    ->name('guest.credit.nonce');
Route::post('/guest/credit/verify', [GuestCreditController::class, 'verify'])
    ->middleware('throttle:10,1,guest-credit-verify')
    ->name('guest.credit.verify');
Route::get('/guest/credit/state', [GuestCreditController::class, 'state'])
    ->middleware('throttle:30,1,guest-credit-state')
    ->name('guest.credit.state');
Route::post('/guest/credit/switch', [GuestCreditController::class, 'switch'])
    ->middleware('throttle:10,1,guest-credit-switch')
    ->name('guest.credit.switch');

Route::match(['get', 'post'], '/test-error/{code}', function ($code) {
    abort((int) $code);
});

// ====================================== Maintenance Mode ====================================== 
Route::get('/system/{action}/{token}', [MaintenanceController::class, 'toggle'])
    ->name('system.maintenance-toggle')
    ->middleware('throttle:5,1');

Route::post('/maintenance/bypass', [MaintenanceController::class, 'bypass'])
    ->name('maintenance.bypass')
    ->middleware('throttle:5,1');

// THROTTLE NOTE: plain "throttle:N,M" shares ONE counter per authenticated
// user (or IP when unauthenticated) across every route that uses it. The
// third value ("guestbook-store", "qrph-create", ...) gives a route its own
// separate counter.
Route::post('/guest-book', [GuestBookingController::class, 'store'])
    ->middleware('throttle:30,1,guestbook-store')
    ->name('guest.book.store');
Route::post('/guest-book/payment/qrph', [PaymongoQrPhController::class, 'createQr'])
    ->middleware('throttle:40,1,qrph-create')
    ->name('guest.book.payment.qrph');

// No CSRF/auth middleware on the webhook route — PayMongo calls this
// server-to-server, it won't have your session cookie or CSRF token.
// If your VerifyCsrfToken middleware applies globally, add this route's
// URI to the $except array there instead of removing middleware here.
// Own bucket + generous limit so PayMongo retries are never rejected (429).
Route::post('/guest-book/payment/qrph/webhook', [PaymongoQrPhController::class, 'webhook'])
    ->name('guest.book.payment.qrph.webhook')
    ->middleware('throttle:300,1,paymongo-webhook');

Route::get('/cron/expire-unconfirmed', [BookingCronController::class, 'expireUnconfirmed'])
    ->middleware('cron.auth')
    ->middleware('throttle:30,1');

Route::get('/cron/run-reminders', [BookingCronController::class, 'runReminders'])
    ->middleware('cron.auth')
    ->middleware('throttle:30,1');


Route::get('/', [GuestBookingController::class, 'landing'])
    ->middleware('throttle:60,1')
    ->name('landing');

Route::get('/book/monthly-stats', [GuestBookingController::class, 'monthlyStats'])
    ->middleware('throttle:30,1')
    ->name('guest.book.monthly-stats');
// status/waiting are polled repeatedly by the frontend while a payment is
// pending, so the limit here is generous — and they get their own buckets
// so polling can't use up other routes' limits.
Route::get('/guest/bookings/{booking}/status', [GuestBookingController::class, 'status'])
    ->middleware('throttle:180,1,guest-status')
    ->name('guest.book.status');
// Full-page "waiting for payment" step — replaces the old modal so a
// refresh, closed tab, or accidental back/forward doesn't cancel the
// booking. See GuestBookingController::waiting()'s docblock.
Route::get('/guest/bookings/{booking}/waiting', [GuestBookingController::class, 'waiting'])
    ->middleware('throttle:60,1,guest-waiting')
    ->name('guest.book.waiting');
// The HTML receipt page a guest lands on once their payment is confirmed.
// Same poll_token-or-owner gate as everything else guest-facing — see
// PaymentReceiptController::show()'s docblock.
Route::get('/guest/bookings/{booking}/receipt', [PaymentReceiptController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('guest.book.receipt');
// Email + OTP booking lookup — no phone number, no direct search anymore.
// request-code sends an email every hit, so it gets the tightest limit
// in the file; verify-code is guessable (OTP) so it's tight too.
Route::post('/guest/bookings/search/request-code', [GuestBookingSearchController::class, 'requestCode'])
    ->middleware(app()->environment('local') ? 'throttle:60,1' : 'throttle:5,1')
    ->name('guest.book.search.request-code');
Route::post('/guest/bookings/search/verify-code', [GuestBookingSearchController::class, 'verifyCode'])
    ->middleware(app()->environment('local') ? 'throttle:60,1' : 'throttle:5,1')
    ->name('guest.book.search.verify-code');
    
// cancel-all typically triggers a confirmation email, so keep it
// tighter than a plain read route. (The single-date `cancel()` route was
// removed as dead code — the waiting page always calls cancel-all, even
// for a single-date checkout.)
Route::post('/guest/bookings/{booking}/cancel-all', [GuestBookingController::class, 'cancelAll'])
    ->middleware('throttle:10,1')
    ->name('guest.book.cancel-all');

// Guest booking (no login required)
Route::get('/guest-book/availability', [GuestBookingController::class, 'availability'])
    ->middleware('throttle:60,1')
    ->name('guest.book.availability');
Route::get('/guest-book/equipment-availability', [GuestBookingController::class, 'equipmentAvailability'])
    ->middleware('throttle:60,1')
    ->name('guest.book.equipment-availability');
// Full-page "guest info + payment method" step — replaces the old
// "Almost Done" modal so a refresh or stray backdrop click can't lose
// the guest's date/time/equipment picks. See
// GuestBookingController::paymentPage()'s docblock.
Route::get('/guest-book/payment', [GuestBookingController::class, 'paymentPage'])
    ->middleware('throttle:30,1')
    ->name('guest.book.payment');
// NOTE: the unthrottled duplicate of this POST route (which used to sit
// here) has been removed — it was silently overriding the throttled
// version registered above, defeating the rate limit entirely. Do not
// re-add a bare Route::post('/guest-book', ...) here.

if (! config('auth.multi_guard')) {
    Route::get('/login', [AuthController::class, 'showLogin'])
        ->middleware('throttle:30,1')
        ->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.submit');

    Route::get('/register', [AuthController::class, 'showRegister'])
        ->middleware('throttle:30,1')
        ->name('register');
    // Keep registration under its named limiter.
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register')
        ->name('register.submit');

    Route::post('/auth/google', [AuthController::class, 'googleAuth'])
        ->middleware('throttle:15,1')
        ->name('auth.google');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('throttle:10,1,logout')
        ->name('logout');
} else {
    $authLanes = [
        'customer' => ['prefix' => '', 'name' => ''],
        'admin' => ['prefix' => 'admin', 'name' => 'admin.'],
        'owner' => ['prefix' => 'owner', 'name' => 'owner.'],
        'staff' => ['prefix' => 'staff', 'name' => 'staff.'],
    ];

    $registerLaneRoute = static function (
        string $method,
        string $uri,
        mixed $action,
        array $middleware,
        string $name,
        string $guard,
    ): void {
        $route = Route::match([$method], $uri, $action);
        $route->middleware($middleware)->name($name)->defaults('_role_guard', $guard);
    };

    foreach ($authLanes as $guard => $lane) {
        $path = static fn (string $uri): string => trim($lane['prefix'].'/'.$uri, '/');
        $name = static fn (string $suffix): string => $lane['name'].$suffix;

        if ($guard === 'customer') {
            $registerLaneRoute('GET', $path('login'), [AuthController::class, 'showLogin'], ['throttle:30,1'], $name('login'), $guard);
            $registerLaneRoute('POST', $path('login'), [AuthController::class, 'login'], ['throttle:login'], $name('login.submit'), $guard);
            $registerLaneRoute('POST', $path('auth/google'), [AuthController::class, 'googleAuth'], ['throttle:15,1'], $name('auth.google'), $guard);
            $registerLaneRoute('GET', 'register', [AuthController::class, 'showRegister'], ['throttle:30,1'], 'register', $guard);
            $registerLaneRoute('POST', 'register', [AuthController::class, 'register'], ['throttle:register'], 'register.submit', $guard);
            $registerLaneRoute('POST', $path('mfa/setup/cancel'), [CustomerMfaController::class, 'cancelSetup'], ['auth', 'throttle:5,1'], $name('mfa.setup.cancel'), $guard);
        } else {
            $registerLaneRoute(
                'GET',
                $path('login'),
                static fn () => redirect()->route('login'),
                ['throttle:30,1'],
                $name('login'),
                $guard,
            );
        }

        $registerLaneRoute('POST', $path('logout'), [AuthController::class, 'logout'], ['throttle:10,1,logout'], $name('logout'), $guard);

        foreach ([
            ['GET', 'mfa/setup', [MfaController::class, 'setup'], ['auth', 'throttle:20,1'], 'mfa.setup'],
            ['POST', 'mfa/setup/init', [MfaController::class, 'initSetup'], ['auth', 'throttle:5,1'], 'mfa.setup.init'],
            ['POST', 'mfa/enable', [MfaController::class, 'enable'], ['auth', 'throttle:5,1'], 'mfa.enable'],
            ['GET', 'mfa/challenge', [MfaController::class, 'challenge'], ['auth', 'throttle:20,1'], 'mfa.challenge'],
            ['POST', 'mfa/verify', [MfaController::class, 'verify'], ['auth', 'throttle:5,1'], 'mfa.verify'],
        ] as [$method, $uri, $action, $middleware, $suffix]) {
            $registerLaneRoute($method, $path($uri), $action, $middleware, $name($suffix), $guard);
        }
    }
}

Route::middleware(['auth', 'real-admin.view-as', 'admin.mfa', 'throttle:20,1'])->group(function () {
    Route::post('/switch-access', [ViewAsController::class, 'switch'])->name('view-as.switch');
    Route::post('/switch-access/reset', [ViewAsController::class, 'reset'])->name('view-as.reset');
});


// =================================== MFA ROUTES =====================================
if (! config('auth.multi_guard')) {
    Route::middleware('auth')->group(function () {
        Route::get('/mfa/setup', [MfaController::class, 'setup'])
            ->middleware('throttle:20,1')
            ->name('mfa.setup');
        Route::post('/mfa/setup/init', [MfaController::class, 'initSetup'])
            ->middleware('throttle:5,1')
            ->name('mfa.setup.init');
        Route::post('/mfa/enable', [MfaController::class, 'enable'])
            ->middleware('throttle:5,1')
            ->name('mfa.enable');
        Route::get('/mfa/challenge', [MfaController::class, 'challenge'])
            ->middleware('throttle:20,1')
            ->name('mfa.challenge');
        Route::post('/mfa/verify', [MfaController::class, 'verify'])
            ->middleware('throttle:5,1')
            ->name('mfa.verify');
        Route::post('/mfa/setup/cancel', [CustomerMfaController::class, 'cancelSetup'])
            ->middleware('throttle:5,1')
            ->name('mfa.setup.cancel');
    });
}

// These controller actions are shared; the route table preserves the legacy
// middleware in flag-off mode and scopes copies when multi-guard is enabled.
$sharedAdminRoutes = require __DIR__.'/shared_admin.php';
$registerSharedAdminRoute = static function (array $definition, string $uri, string $name, ?string $guard = null): void {
    $route = match ($definition['method']) {
        'GET' => Route::get($uri, $definition['action']),
        'POST' => Route::post($uri, $definition['action']),
        'PATCH' => Route::patch($uri, $definition['action']),
        'DELETE' => Route::delete($uri, $definition['action']),
    };

    $route->middleware($definition['middleware'])->name($name);
    if ($guard !== null) {
        $route->defaults('_role_guard', $guard);
    }
};

if (config('auth.multi_guard')) {
    foreach (['admin', 'owner', 'staff'] as $roleGuard) {
        foreach ($sharedAdminRoutes as $definition) {
            if (! in_array($roleGuard, $definition['roles'], true)) {
                continue;
            }

            $nameSuffix = substr($definition['name'], strlen('admin.'));
            $legacyUri = $definition['legacy_uri'];
            $roleUri = $roleGuard === 'admin' && str_starts_with($legacyUri, 'admin/')
                ? $legacyUri
                : $roleGuard.'/'.$legacyUri;
            $registerSharedAdminRoute($definition, $roleUri, $roleGuard.'.'.$nameSuffix, $roleGuard);
        }
    }
} else {
    foreach ($sharedAdminRoutes as $definition) {
        $registerSharedAdminRoute($definition, $definition['legacy_uri'], $definition['name']);
    }
}

Route::middleware(['auth', 'admin.mfa', 'real-admin', 'throttle.admin-management:30,1'])
    ->get('/admin/widgets/metrics', [MetricsController::class, 'index'])
    ->name('admin.widgets.metrics');

// =================================== ADMIN ROUTES =====================================
Route::middleware(['auth', 'admin', 'admin.mfa', 'throttle.admin-management:120,1'])->group(function () {
    Route::get('/dashboard', [Admin_DashboardController::class, 'index'])->middleware('owner.page.redirect')->name('admin.dashboard');
    Route::get('/admin/dashboard', [Admin_DashboardController::class, 'index'])->name('admin.dashboard.view-as');

    // Bookings CRUD
    Route::get('/bookings', [BookingController::class, 'index'])->middleware('owner.page.redirect')->name('bookings.index');

    // Courts CRUD
    Route::get('/courts', [CourtController::class, 'index'])->middleware('owner.page.redirect')->name('courts.index');
    Route::post('/courts', [CourtController::class, 'store'])->name('courts.store');
    Route::put('/courts/{court}', [CourtController::class, 'update'])->name('courts.update');
    Route::delete('/courts/{court}', [CourtController::class, 'destroy'])->name('courts.destroy');

    // Activity Logs
    Route::get('/activity_logs', [ActivityLogController::class, 'index'])->middleware('owner.page.redirect')->name('activity_logs.index');

    //Announcements
    Route::get('/announcements', [AnnouncementController::class, 'index'])->middleware('owner.page.redirect')->name('admin.announcements.index');
    Route::get('/announcements/create', [AnnouncementController::class, 'create'])->middleware('owner.page.redirect')->name('admin.announcements.create');
    // If store() mass-emails users/customers, this is your biggest single
    // SMTP-spike risk in the admin area — kept tight on purpose.
    Route::post('/announcements', [AnnouncementController::class, 'store'])
        ->middleware('throttle.admin-management:5,1')
        ->name('admin.announcements.store');
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('admin.announcements.destroy');

    // Customers
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('owner.page.redirect')->name('admin.customers.index');
    // Profile
    // NOTE: PUT/DELETE /profile used to be duplicated here as well as in
    // the USER ROUTES group below, using the same route names
    // ('user.profile.update' / 'user.profile.destroy'). That duplication
    // is a bug: Laravel doesn't allow two routes with the same name, and
    // because this admin group is registered first and includes the
    // 'admin' middleware (which aborts 403 for any non-admin), it would
    // have blocked every ordinary customer from updating or deleting
    // their own profile. The single source of truth for these two routes
    // now lives in the USER ROUTES group further down, with 'admin.mfa'
    // added there so admins still get the MFA check (see Finding #2).
    Route::get('/admin/profile', [AdminProfileController::class, 'profile'])->middleware('owner.page.redirect')->name('admin.profile');
    Route::put('/admin/profile', [AdminProfileController::class, 'update'])->name('admin.profile.update');
    Route::delete('/admin/profile', [AdminProfileController::class, 'destroyAccount'])->name('admin.profile.destroy');

    // Reports
    Route::get('/reports', [ReportController::class, 'index'])->middleware('owner.page.redirect')->name('admin.reports.index');
    Route::get('/reports/system', [ReportController::class, 'system'])->middleware('can.access:reports.system')->name('admin.reports.system');

    // Widgets
    Route::get('/admin/widgets', fn () => view('admin.widgets.index'))
        ->middleware('owner.page.redirect')
        ->name('admin.widgets.index');

    // Configuration / Schedule
    Route::get('/admin/configuration', [ConfigurationController::class, 'index'])->middleware('owner.page.redirect')->name('admin.configuration.index');
    Route::put('/admin/configuration/hours', [ConfigurationController::class, 'updateHours'])->name('admin.configuration.hours.update');
    Route::put('/admin/configuration/pricing', [ConfigurationController::class, 'updatePricing'])->name('admin.configuration.pricing.update');
    Route::post('/admin/configuration/closures', [ConfigurationController::class, 'storeClosure'])->name('admin.configuration.closures.store');
    Route::delete('/admin/configuration/closures/{closure}', [ConfigurationController::class, 'destroyClosure'])->name('admin.configuration.closures.destroy');
    Route::post('configuration/manual-booking', [ConfigurationController::class, 'storeManualBooking'])->name('admin.configuration.manual-booking.store');
    Route::get('configuration/availability', [ConfigurationController::class, 'availability'])->name('admin.configuration.availability');
    Route::put('/admin/configuration/closures/{closure}', [ConfigurationController::class, 'updateClosure'])->name('admin.configuration.closures.update');
    Route::delete('configuration/specific-date-closure/{closure}', [ConfigurationController::class, 'destroySpecificDateClosure'])->name('admin.configuration.specific-date-closure.destroy');
    Route::post('configuration/specific-date-closure', [ConfigurationController::class, 'storeSpecificDateClosure'])->name('admin.configuration.specific-date-closure.store');
    Route::put('configuration/specific-date-closure/{closure}', [ConfigurationController::class, 'updateSpecificDateClosure'])->name('admin.configuration.specific-date-closure.update');

    //Equipment
    Route::get('/admin/equipment/availability', [EquipmentAvailabilityController::class, 'index'])->middleware('owner.page.redirect')->name('admin.equipment.availability');
    Route::get('/admin/equipment/availability/data', [EquipmentAvailabilityController::class, 'data'])->name('admin.equipment.availability.data');
    Route::post('/equipment', [EquipmentAvailabilityController::class, 'store'])->name('admin.equipment.store');
    Route::put('/equipment/{equipment}', [EquipmentAvailabilityController::class, 'update'])->name('admin.equipment.update');
    Route::delete('/equipment/{equipment}', [EquipmentAvailabilityController::class, 'destroy'])->name('admin.equipment.destroy');

});

// =====================================   USER ROUTES    =====================================
// Same pattern as the admin group: a generous baseline for everything,
// tighter overrides on anything that writes data or sends email.
Route::middleware(['auth', 'admin.mfa', 'customer.only', 'customer.mfa', 'throttle:120,1'])->group(function () {
    Route::get('/my-dashboard', [UserDashboardController::class, 'index'])->name('user.dashboard');
    Route::get('/user/dashboard', [UserDashboardController::class, 'index'])->name('user.dashboard.view-as');
    Route::get('/lumi', [LumiController::class, 'show'])->name('user.lumi');
    Route::get('/lumi/suggestions', [LumiController::class, 'suggestions'])
        ->withoutMiddleware('throttle:120,1')
        ->middleware('throttle:30,1,lumi-suggestions')
        ->name('user.lumi.suggestions');
    Route::post('/lumi/dismiss', [LumiController::class, 'dismiss'])->name('user.lumi.dismiss');
    Route::get('/whats-new', [LumiController::class, 'whatsNew'])->name('user.whats-new');

    // Book a court
    Route::get('/book', [User_UserController::class, 'createBooking'])->name('book.index');
    Route::get('/book/availability', [User_UserController::class, 'availability'])->name('book.availability');
    Route::get('/book/equipment-availability', [User_UserController::class, 'equipmentAvailability'])->name('book.equipment-availability');
    // Polled while a payment is pending, so this gets a higher limit
    // than the group baseline rather than a tighter one.
    Route::get('/book/bookings/{booking}/status', [User_UserController::class, 'bookingStatus'])
        ->middleware('throttle:120,1')
        ->name('book.status');
    // Full-page "waiting for payment" step — see
    // User_UserController::waitingForPayment()'s docblock.
    Route::get('/book/bookings/{booking}/waiting', [User_UserController::class, 'waitingForPayment'])->name('book.waiting');
    // Receipt page for a fully-paid user booking. Ownership-gated —
    // see User_UserController::receiptForUser()'s docblock.
    Route::get('/book/bookings/{booking}/receipt', [User_UserController::class, 'receiptForUser'])->name('book.receipt');
    Route::post('/book', [User_UserController::class, 'storeBooking'])
        ->middleware('throttle:20,1')
        ->name('book.store');

    // Booking history
    Route::get('/my-bookings', [User_UserController::class, 'myBookings'])->name('user.bookings.index');
    // Cancellation typically fires a confirmation email — tighter than
    // the group baseline.
    Route::post('/my-bookings/{booking}/cancel', [User_UserController::class, 'cancelBooking'])
        ->middleware('throttle:10,1')
        ->name('user.bookings.cancel');

    // Profile
    // 'admin.mfa' is added to the write routes here (see Finding #2 in the
    // audit): the middleware is a no-op for non-admin users, so ordinary
    // customers are unaffected, but an admin hitting these same shared
    // routes now has to have passed MFA first, closing the bypass that
    // let an admin update/delete their own account via the generic
    // /profile endpoints without ever going through /admin/profile.
    Route::get('/profile', [User_UserController::class, 'profile'])->name('user.profile');
    Route::post('/profile/mfa/setup', [CustomerMfaController::class, 'setup'])
        ->middleware('throttle:5,1')
        ->name('user.profile.mfa.setup');
    Route::post('/profile/mfa/enable', [CustomerMfaController::class, 'enable'])
        ->middleware('throttle:5,1')
        ->name('user.profile.mfa.enable');
    Route::post('/profile/mfa/disable', [CustomerMfaController::class, 'disable'])
        ->middleware('throttle:5,1')
        ->name('user.profile.mfa.disable');
    Route::post('/profile/mfa/recovery-codes', [CustomerMfaController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:5,1')
        ->name('user.profile.mfa.recovery-codes');
    Route::put('/profile', [User_UserController::class, 'updateProfile'])
        ->middleware(['throttle:10,1', 'admin.mfa'])
        ->name('user.profile.update');
    Route::delete('/profile', [User_UserController::class, 'destroyAccount'])
        ->middleware(['throttle:5,1', 'admin.mfa'])
        ->name('user.profile.destroy');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index'])->name('user.notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('user.notifications.unread-count');
    Route::post('/notifications/{notification}/mark-read', [NotificationController::class, 'markRead'])->name('user.notifications.mark-read');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('user.notifications.mark-all-read');

    // Feedback
    Route::get('/feedback', [FeedbackController::class, 'index'])->name('user.feedback.index');
    Route::post('/feedback', [FeedbackController::class, 'store'])->name('user.feedback.store');
    Route::put('/feedback/{feedback}', [FeedbackController::class, 'update'])->name('user.feedback.update');
    Route::delete('/feedback/{feedback}', [FeedbackController::class, 'destroy'])->name('user.feedback.destroy');
    
});

require __DIR__.'/staff.php';
require __DIR__.'/owner.php';