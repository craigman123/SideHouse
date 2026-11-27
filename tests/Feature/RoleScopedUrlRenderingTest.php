<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class RoleScopedUrlRenderingTest extends TestCase
{
    use RefreshDatabase;
    use UsesMultiGuardEnvironment;

    private const ALLOWED_DIRECT_ADMIN_ROUTES = [
        'admin.announcements.store',
        'admin.announcements.destroy',
        'admin.configuration.hours.update',
        'admin.configuration.pricing.update',
        'admin.configuration.specific-date-closure.store',
        'admin.configuration.specific-date-closure.destroy',
        'admin.configuration.specific-date-closure.update',
        'admin.configuration.availability',
        'admin.configuration.manual-booking.store',
        'admin.configuration.closures.store',
        'admin.configuration.closures.destroy',
        'admin.configuration.closures.update',
        'admin.equipment.availability.data',
        'admin.equipment.store',
        'admin.profile.destroy',
        'admin.reports.system',
    ];

    protected function setUp(): void
    {
        $this->setMultiGuardEnvironment(true);
        parent::setUp();
        $this->assertTrue((bool) config('auth.multi_guard'));
    }

    protected function tearDown(): void
    {
        Auth::shouldUse('web');
        parent::tearDown();
        $this->restoreMultiGuardEnvironment();
    }

    public function test_blade_and_public_javascript_do_not_contain_unscoped_shared_urls(): void
    {
        $definitions = require base_path('routes/shared_admin.php');
        $sharedNames = array_fill_keys(array_column($definitions, 'name'), true);
        $sharedPaths = [];
        foreach ($definitions as $definition) {
            foreach ($definition['roles'] as $role) {
                $legacyUri = $definition['legacy_uri'];
                $uri = $role === 'admin' && str_starts_with($legacyUri, 'admin/')
                    ? $legacyUri
                    : $role.'/'.$legacyUri;
                $path = preg_replace('/\{[^}]+\}/', '', $uri);
                $sharedPaths[] = '/'.rtrim($path, '/');
            }
        }

        $files = array_merge(
            File::allFiles(resource_path('views')),
            File::allFiles(public_path('js')),
        );
        $issues = [];

        foreach ($files as $file) {
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            foreach ($lines as $index => $line) {
                preg_match_all('/\b(route|role_route)\(\s*[\'"]([^\'"]+)[\'"]/', $line, $calls, PREG_SET_ORDER);
                foreach ($calls as $call) {
                    if ($call[1] !== 'route' || ! str_starts_with($call[2], 'admin.')) {
                        continue;
                    }

                    if (isset($sharedNames[$call[2]]) || ! in_array($call[2], self::ALLOWED_DIRECT_ADMIN_ROUTES, true)) {
                        $issues[] = $file->getRelativePathname().':'.($index + 1).' direct route(\''.$call[2].'\')';
                    }
                }

                foreach ($sharedPaths as $path) {
                    if ($path !== '/' && str_contains($line, $path)) {
                        $issues[] = $file->getRelativePathname().':'.($index + 1).' hard-coded shared path ['.$path.']';
                    }
                }
            }
        }

        $this->assertSame([], $issues, implode(PHP_EOL, $issues));
    }

    private function assertCalendarLoadingAndFeedbackMarkup(\Illuminate\Testing\TestResponse $response, string $activeView): void
    {
        $response->assertSee('id="courtBoardRegion"', false)
            ->assertSee('id="courtBoardSkeleton"', false)
            ->assertSee('id="courtMonthGrid"', false)
            ->assertSee('id="courtWeekGrid"', false)
            ->assertSee('data-calendar-loader="board"', false)
            ->assertSee('data-calendar-loader="month"', false)
            ->assertSee('data-calendar-loader="week"', false)
            ->assertSee('data-calendar-skeleton="board"', false)
            ->assertSee('data-calendar-skeleton="month"', false)
            ->assertSee('data-calendar-skeleton="week"', false)
            ->assertSee('court-calendar-skeleton--month', false)
            ->assertSee('court-calendar-skeleton--week', false)
            ->assertSee('bookings-board-watchdog.js', false)
            ->assertSee('No bookings on this day.', false)
            ->assertSee('No bookings this month.', false)
            ->assertSee('No bookings this week.', false);

        foreach (['board', 'month', 'week'] as $view) {
            $response->assertSee('data-calendar-empty-state="'.$view.'"', false)
                ->assertSee('data-calendar-error-state="'.$view.'"', false)
                ->assertSee('data-calendar-retry="'.$view.'"', false)
                ->assertSee('data-calendar-loading-label="'.$view.'"', false)
                ->assertSee('data-calendar-loading-status="'.$view.'"', false);
        }

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<div\b(?=[^>]*data-calendar-loader="'.$activeView.'")(?=[^>]*aria-busy="true")[^>]*>/',
            $html,
            "The active {$activeView} calendar must be announced as busy."
        );
        $this->assertStringContainsString('Loading bookings...', $html);
        $this->assertStringContainsString('role="status" aria-live="polite" aria-atomic="true">Loading bookings...</span>', $html);
        foreach (['board', 'month', 'week'] as $view) {
            $this->assertDoesNotMatchRegularExpression(
                '/<div\b(?=[^>]*\bdata-calendar-skeleton="'.$view.'")(?=[^>]*\shidden(?:\s|=|>))[^>]*>/',
                $html,
                "The server-rendered {$view} skeleton must not be hidden."
            );
        }
    }

    private function assertSharedProfileContent(\Illuminate\Testing\TestResponse $response, string $layout): void
    {
        $response->assertViewIs('user.profile.profile')
            ->assertViewHas('profileLayout', $layout)
            ->assertSee('Edit Profile')
            ->assertSee('Preferences')
            ->assertSee('Security');
    }

    public function test_role_pages_render_role_scoped_logout_api_and_navigation_urls(): void
    {
        $admin = $this->user('admin');
        $adminPage = $this->signInAs($admin, 'admin')->get('/bookings?view=month')->assertOk();
        $this->assertCalendarLoadingAndFeedbackMarkup($adminPage, 'month');
        $adminPage->assertSee('action="'.route('admin.logout').'"', false)
            ->assertSee('data-calendar-url="'.route('admin.bookings.calendar').'"', false)
            ->assertSee('data-walk-in-url="'.route('admin.bookings.walk-in.store').'"', false)
            ->assertSee('data-block-store-url="'.route('admin.blocks.store').'"', false)
            ->assertSee('data-list-url="'.route('admin.specific-closures.index').'"', false)
            ->assertSee('href="'.route('admin.user-access.page').'"', false);

        $owner = $this->user('owner');
        $ownerPage = $this->signInAs($owner, 'owner')->get('/owner/bookings?view=week')->assertOk();
        $this->assertCalendarLoadingAndFeedbackMarkup($ownerPage, 'week');
        $ownerPage->assertSee('action="'.route('owner.logout').'"', false)
            ->assertSee('data-calendar-url="'.route('owner.bookings.calendar').'"', false)
            ->assertSee('data-walk-in-url="'.route('owner.bookings.walk-in.store').'"', false)
            ->assertSee('data-block-store-url="'.route('owner.blocks.store').'"', false)
            ->assertSee('data-list-url="'.route('owner.specific-closures.index').'"', false)
            ->assertSee('href="'.route('owner.user-access').'"', false);

        $staff = $this->user('staff', ['bookings.view', 'customers.view', 'calendar_events.manage', 'bookings.create']);
        $staffPage = $this->signInAs($staff, 'staff')->get('/staff/bookings?view=board')->assertOk();
        $this->assertCalendarLoadingAndFeedbackMarkup($staffPage, 'board');
        $staffPage->assertSee('action="'.route('staff.logout').'"', false)
            ->assertSee('data-calendar-url="'.route('staff.bookings.calendar').'"', false)
            ->assertSee('data-walk-in-url=""', false)
            ->assertSee('data-block-store-url="'.route('staff.blocks.store').'"', false)
            ->assertSee('href="'.route('staff.bookings').'"', false)
            ->assertDontSee('data-specific-closures', false);

        $adminCustomers = $this->signInAs($admin, 'admin')->get('/customers')->assertOk();
        $adminCustomers->assertSee('data-customers-url="'.route('admin.customers.data').'"', false)
            ->assertSee('data-credit-lookup-url="'.route('admin.customer-credits.lookup').'"', false)
            ->assertSee('data-credit-store-url="'.route('admin.customer-credits.store').'"', false);
        $adminReports = $this->signInAs($admin, 'admin')->get('/reports')->assertOk();
        $adminReports->assertSee('data-reports-url="'.route('admin.reports.data').'"', false);
        $adminAccess = $this->signInAs($admin, 'admin')->get('/admin/user-access')->assertOk();
        $adminAccess->assertSee('data-list-url="'.route('admin.users.index').'"', false)
            ->assertSee('data-update-url-template="'.route('admin.users.update', ['user' => '__USER__']).'"', false)
            ->assertSee('data-mfa-disable-url-template="'.route('admin.users.mfa.disable', ['user' => '__USER__']).'"', false)
            ->assertSee('data-mfa-owner-approve-url-template="'.route('admin.users.mfa.owner-approve', ['user' => '__USER__', 'requestId' => '__REQUEST__']).'"', false);
        $admin->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'ADMINSECRET'])->save();
        $adminProfile = $this->signInAs($admin, 'admin')->get('/admin/profile')->assertOk();
        $this->assertSharedProfileContent($adminProfile, 'layouts.app');
        $adminProfile->assertViewIs('user.profile.profile')
            ->assertSee('data-profile-editable="true"', false)
            ->assertSee('action="'.route('admin.profile.password').'"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('action="'.route('admin.profile.mfa.recovery-codes').'"', false);

        $ownerCustomers = $this->signInAs($owner, 'owner')->get('/owner/customers')->assertOk();
        $ownerCustomers->assertSee('data-customers-url="'.route('owner.customers.data').'"', false)
            ->assertSee('data-credit-lookup-url="'.route('owner.customer-credits.lookup').'"', false)
            ->assertSee('data-credit-store-url="'.route('owner.customer-credits.store').'"', false);
        $ownerReports = $this->signInAs($owner, 'owner')->get('/owner/reports')->assertOk();
        $ownerReports->assertSee('data-reports-url="'.route('owner.reports.data').'"', false);
        $ownerAccess = $this->signInAs($owner, 'owner')->get('/owner/user-access')->assertOk();
        $ownerAccess->assertSee('data-list-url="'.route('owner.users.index').'"', false)
            ->assertSee('data-update-url-template="'.route('owner.users.update', ['user' => '__USER__']).'"', false);
        $owner->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'OWNERSECRET'])->save();
        $ownerProfile = $this->signInAs($owner, 'owner')->get('/owner/profile')->assertOk();
        $this->assertSharedProfileContent($ownerProfile, 'owner.layouts.app');
        $ownerProfile->assertViewIs('user.profile.profile')
            ->assertSee('data-profile-editable="true"', false)
            ->assertSee('action="'.route('owner.profile.password').'"', false)
            ->assertSee('action="'.route('owner.profile.mfa.recovery-codes').'"', false);

        $staffCustomers = $this->signInAs($staff, 'staff')->get('/staff/customers')->assertOk();
        $staffCustomers->assertSee('data-customers-url="'.route('staff.customers.data').'"', false)
            ->assertSee('data-credit-lookup-url=""', false)
            ->assertSee('data-credit-store-url=""', false);
        $staff->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'STAFFSECRET'])->save();
        $staffProfile = $this->signInAs($staff, 'staff')->get('/staff/profile')->assertOk();
        $this->assertSharedProfileContent($staffProfile, 'staff.layouts.app');
        $staffProfile->assertViewIs('user.profile.profile')
            ->assertSee('id="staffProfileForm"', false)
            ->assertSee('id="staffEmail"', false)
            ->assertSee('action="'.route('staff.profile.password').'"', false)
            ->assertDontSee('id="username"', false)
            ->assertSee('action="'.route('staff.profile.mfa.recovery-codes').'"', false);

        $customer = $this->user('user');
        $customerPage = $this->signInAs($customer, 'customer')->get('/profile')->assertOk();
        $this->assertSharedProfileContent($customerPage, 'layouts.user');
        $customerPage->assertViewIs('user.profile.profile')
            ->assertSee('data-profile-editable="true"', false)
            ->assertSee('action="'.route('user.profile.password').'"', false)
            ->assertSee('Member', false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('href="'.route('user.dashboard').'"', false)
            ->assertSee('href="'.route('user.profile').'" class="active"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_view_as_stays_on_the_admin_guard_and_forbidden_home_links_follow_the_selected_guard(): void
    {
        $admin = $this->user('admin');
        $this->signInAs($admin, 'admin')->post(route('view-as.switch'), ['role' => 'staff'])
            ->assertRedirect(route('staff.dashboard', absolute: false));

        foreach ([
            'admin' => ['admin', 'admin.dashboard', 'Go to admin dashboard'],
            'owner' => ['owner', 'owner.dashboard', 'Go to owner dashboard'],
            'staff' => ['staff', 'staff.dashboard', 'Go to staff dashboard'],
            'customer' => ['user', 'user.dashboard', 'Go to my dashboard'],
        ] as $guard => [$role, $homeRoute, $label]) {
            $user = $this->user($role);
            $this->signInAs($user, $guard);
            $html = view('errors.403')->render();
            $this->assertStringContainsString('href="'.route($homeRoute).'"', $html);
            $this->assertStringContainsString($label, $html);
        }

        Auth::shouldUse('web');
        $guestError = view('errors.403')->render();
        $this->assertStringContainsString('href="'.route('landing').'"', $guestError);
    }

    public function test_role_route_helpers_resolve_logout_and_home_for_each_guard(): void
    {
        foreach ([
            'admin' => ['admin.logout', 'admin.dashboard'],
            'owner' => ['owner.logout', 'owner.dashboard'],
            'staff' => ['staff.logout', 'staff.dashboard'],
            'customer' => ['logout', 'user.dashboard'],
        ] as $guard => [$logoutRoute, $homeRoute]) {
            Auth::shouldUse($guard);
            $this->assertSame(route($logoutRoute), role_route('logout'), "{$guard} logout route");
            $this->assertSame(route($homeRoute), role_home_url(), "{$guard} home route");
        }
    }

    private function signInAs(User $user, string $guard): self
    {
        $session = [
            'access_version_user_id_'.$guard => $user->user_id,
            'access_version_'.$guard => $user->access_version,
        ];
        if ($guard !== 'customer') {
            $session['mfa_passed_at_'.$guard] = now()->timestamp;
            $session['mfa_passed_user_id_'.$guard] = $user->user_id;
        }

        return $this->actingAs($user, $guard)->withSession($session);
    }

    private function user(string $role, ?array $permissions = null): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => "role-url-{$role}-{$sequence}",
            'name' => ucfirst($role).' URL Test',
            'email' => "role-url-{$role}-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'permissions' => $permissions,
            'access_version' => 1,
        ]);
    }
}
