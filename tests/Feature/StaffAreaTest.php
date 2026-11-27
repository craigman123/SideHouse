<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAreaTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions = ['bookings.view', 'customers.view']): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => "staff-area-{$sequence}",
            'name' => 'Staff Person',
            'email' => "staff-area-{$sequence}@example.test",
            'password' => 'current-password-123',
            'role' => 'staff',
            'permissions' => $permissions,
        ]);
    }

    private function signInStaff(User $staff): self
    {
        return $this->actingAs($staff)->withSession([
            'access_version_user_id' => $staff->user_id,
            'access_version' => 1,
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $staff->user_id,
        ]);
    }

    private function bookingForToday(User $staff): Booking
    {
        BusinessSetting::current();
        $court = Court::create([
            'name' => 'Court A', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active',
        ]);

        return Booking::create([
            'user_id' => $staff->user_id,
            'customer_name' => 'Customer Name',
            'contact_number' => '09171234567',
            'court_id' => $court->id,
            'date' => now('Asia/Manila')->toDateString(),
            'start_time' => '09:00', 'end_time' => '10:00',
            'status' => 'paid', 'source' => 'online', 'amount' => 500,
        ]);
    }

    public function test_staff_dashboard_and_profile_use_the_staff_shell_without_admin_assets(): void
    {
        $staff = $this->staff();

        $dashboard = $this->signInStaff($staff)->get('/staff/dashboard')->assertOk();
        $dashboard->assertSee('shared/dashboard-shell.css')->assertSee('shared/dashboard-shell.js')
            ->assertSee('shared/overview.css')->assertSee('staff-overview.css')->assertSee('staff-overview.js');
        $dashboard->assertDontSee('css/admin/')->assertDontSee('js/admin/')->assertDontSee('staff-layout.css');
        $dashboard->assertSee('staffSyncStatus')->assertSee("Today's bookings", false)
            ->assertSee('Open play')->assertDontSee('Needs attention')->assertDontSee('Open play today');

        $profile = $this->signInStaff($staff)->get('/staff/profile')->assertOk();
        $profile->assertSee('shared/profile.css')->assertSee('shared/dashboard-base.css')->assertDontSee('css/admin/');
        $profile->assertSee('staff-profile.css')->assertSee('staff-profile.js')
            ->assertSee('Change password')
            ->assertSee('Coming soon')
            ->assertSee('role="switch"', false)
            ->assertSee('aria-checked="false"', false)
            ->assertSee('aria-disabled="true"', false)
            ->assertSee('id="staffProfileEditButton"', false)
            ->assertSee('id="staffPasswordModal"', false)
            ->assertDontSee('Pending setup')
            ->assertDontSee('Current method')
            ->assertDontSee('Allowed methods')
            ->assertDontSee('TOTP only');
        $this->assertMatchesRegularExpression('/<input[^>]*id="staffName"[^>]*readonly/', $profile->getContent());
        $profile->assertDontSee('Enable MFA')
            ->assertDontSee('Disable MFA');
        $profile->assertSee('id="staffLogoutForm"', false)
            ->assertSee('action="'.route(config('auth.multi_guard') ? 'staff.logout' : 'logout').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('Sign out')
            ->assertDontSee('Delete Account')
            ->assertDontSee('Disable MFA');

        preg_match('/<aside\b[^>]*id="adminSidebar"[^>]*>.*?<\/aside>/s', $dashboard->getContent(), $sidebarMatch);
        $this->assertSame(1, count($sidebarMatch), 'The staff sidebar markup should be present.');
        $this->assertStringNotContainsString('Log out', $sidebarMatch[0]);
        $this->assertStringNotContainsString('logout-form', $sidebarMatch[0]);
        $this->assertStringNotContainsString('dashboard-shell-logout', $sidebarMatch[0]);
        $profile->assertSee('href="'.route('staff.profile').'" class="active"', false);

        $this->signInStaff($staff)->post(route(config('auth.multi_guard') ? 'staff.logout' : 'logout'))
            ->assertRedirect(route(config('auth.multi_guard') ? 'staff.login' : 'login'))
            ->assertSessionHas('success', 'Logged out successfully.');
        $this->assertGuest();
    }

    public function test_admin_sidebar_profile_link_and_shared_shell_remain_unchanged(): void
    {
        $admin = User::create([
            'username' => 'staff-area-admin',
            'name' => 'Admin Person',
            'email' => 'staff-area-admin@example.test',
            'password' => 'current-password-123',
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->withSession([
            'access_version_user_id' => $admin->user_id,
            'access_version' => 1,
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $admin->user_id,
        ])->get('/dashboard')->assertOk();

        $response->assertSee('href="'.route('admin.profile').'"', false)
            ->assertSee('Profile')
            ->assertSee('id="logout-form"', false);
        preg_match('/<aside\b[^>]*id="adminSidebar"[^>]*>.*?<\/aside>/s', $response->getContent(), $sidebarMatch);
        $this->assertSame(1, count($sidebarMatch), 'The admin sidebar markup should be present.');
        $this->assertStringNotContainsString('dashboard-shell-logout', $sidebarMatch[0]);
    }

    public function test_staff_overview_renders_shared_icon_chips_for_each_pane(): void
    {
        $dashboard = $this->signInStaff($this->staff())->get('/staff/dashboard')->assertOk();

        $dashboard->assertSee("Today's bookings<span class=\"icon-chip\" data-icon=\"calendar\"", false)
            ->assertSee('Courts in use<span class="icon-chip" data-icon="gauge"', false)
            ->assertSee('Court status<span class="icon-chip" data-icon="court"', false)
            ->assertSee('Next up<span class="icon-chip" data-icon="calendar"', false)
            ->assertSee('Open play<span class="icon-chip" data-icon="padel"', false)
            ->assertSee('Equipment prep<span class="icon-chip" data-icon="bag"', false);
    }

    public function test_staff_overview_count_and_lists_exclude_cancelled_and_expired_bookings(): void
    {
        $staff = $this->staff();
        $booking = $this->bookingForToday($staff);

        foreach ([['Cancelled Customer', 'cancelled'], ['Expired Customer', 'expired'], ['Pending Customer', 'pending']] as [$name, $status]) {
            Booking::create([
                'user_id' => $staff->user_id,
                'customer_name' => $name,
                'contact_number' => '09171234567',
                'court_id' => $booking->court_id,
                'date' => now('Asia/Manila')->toDateString(),
                'start_time' => '23:00', 'end_time' => '23:30',
                'status' => $status, 'source' => 'walk_in', 'amount' => 250,
            ]);
        }

        $data = $this->signInStaff($staff)->getJson('/staff/overview/data')->assertOk();
        $data->assertJsonPath('booking_count', 2);
        $this->assertSame(['Pending Customer'], array_column($data->json('needs_attention'), 'customer'));
        $listedCustomers = collect($data->json('upcoming'))->pluck('customer')->all();
        $this->assertNotContains('Cancelled Customer', $listedCustomers);
        $this->assertNotContains('Expired Customer', $listedCustomers);
    }

    public function test_staff_overview_prefers_active_open_play_and_uses_calendar_participants(): void
    {
        $now = CarbonImmutable::parse('2030-05-15 12:00:00', 'Asia/Manila');
        \Carbon\Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $staff = $this->staff();
        BusinessSetting::current();
        $court = Court::create([
            'name' => 'Open Play Court', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active',
        ]);
        $active = CourtBlock::create([
            'court_id' => $court->id, 'type' => 'open_play', 'title' => 'Lunch Open Play',
            'block_date' => '2030-05-15', 'start_time' => '11:30', 'end_time' => '12:30',
            'max_players' => 8, 'price_per_player' => 100, 'created_by' => $staff->user_id,
        ]);
        CourtBlock::create([
            'court_id' => $court->id, 'type' => 'open_play', 'title' => 'Afternoon Open Play',
            'block_date' => '2030-05-15', 'start_time' => '13:00', 'end_time' => '14:00',
            'max_players' => 8, 'price_per_player' => 100, 'created_by' => $staff->user_id,
        ]);
        CourtBlock::create([
            'court_id' => $court->id, 'type' => 'open_play', 'title' => 'Tomorrow Open Play',
            'block_date' => '2030-05-16', 'start_time' => '10:00', 'end_time' => '11:00',
            'max_players' => 8, 'price_per_player' => 100, 'created_by' => $staff->user_id,
        ]);

        foreach ([
            ['Player One', '11:30'],
            ['Player Two', '11:35'],
            ['Player Three', '11:40'],
            ['Player Four', '11:45'],
        ] as [$name, $start]) {
            Booking::create([
                'user_id' => null, 'customer_name' => $name, 'contact_number' => '09171234567',
                'court_id' => $court->id, 'date' => '2030-05-15',
                'start_time' => $start, 'end_time' => '12:00',
                'status' => 'paid', 'source' => 'online', 'amount' => 100,
            ]);
        }

        $response = $this->signInStaff($staff)->getJson('/staff/overview/data')->assertOk();
        $response->assertJsonPath('open_play.id', $active->id)
            ->assertJsonPath('open_play.title', 'Lunch Open Play')
            ->assertJsonPath('open_play.court', 'Open Play Court')
            ->assertJsonPath('open_play.date', '2030-05-15')
            ->assertJsonPath('open_play.time', '11:30 AM – 12:30 PM')
            ->assertJsonPath('open_play.status', 'active')
            ->assertJsonPath('open_play.is_today', true)
            ->assertJsonPath('open_play.participant_count', 4)
            ->assertJsonPath('open_play.participants', ['Player One', 'Player Two', 'Player Three']);

        $openPlay = $response->json('open_play');
        $this->assertArrayNotHasKey('amount', $openPlay);
        $this->assertArrayNotHasKey('price_per_player', $openPlay);

        $page = $this->get('/staff/dashboard')->assertOk();
        $page->assertSee('data-bookings-url="'.route('staff.bookings').'"', false)
            ->assertDontSee('Needs attention')
            ->assertDontSee('Open play today');
    }

    public function test_staff_overview_open_play_status_covers_upcoming_soon_and_empty(): void
    {
        $now = CarbonImmutable::parse('2030-05-15 12:00:00', 'Asia/Manila');
        \Carbon\Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $staff = $this->staff();
        BusinessSetting::current();
        $court = Court::create([
            'name' => 'Status Court', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active',
        ]);
        $event = CourtBlock::create([
            'court_id' => $court->id, 'type' => 'open_play', 'title' => 'Status Open Play',
            'block_date' => '2030-05-15', 'start_time' => '13:00', 'end_time' => '14:00',
            'max_players' => 8, 'price_per_player' => 100, 'created_by' => $staff->user_id,
        ]);

        $this->signInStaff($staff)->getJson('/staff/overview/data')->assertOk()
            ->assertJsonPath('open_play.status', 'upcoming')
            ->assertJsonPath('open_play.is_today', true);

        $event->update(['block_date' => '2030-05-16']);
        $this->getJson('/staff/overview/data')->assertOk()
            ->assertJsonPath('open_play.status', 'soon')
            ->assertJsonPath('open_play.is_today', false);

        $event->update(['block_date' => '2030-05-14', 'start_time' => '10:00', 'end_time' => '11:00']);
        $this->getJson('/staff/overview/data')->assertOk()
            ->assertJsonPath('open_play', null);
    }

    public function test_staff_navigation_is_permission_driven_and_contains_only_the_allowed_sections(): void
    {
        $staff = $this->staff(['bookings.view', 'customers.view', 'activity_logs.view']);
        $response = $this->signInStaff($staff)->get('/staff/dashboard')->assertOk();
        $response->assertSee('Overview')->assertSee('Bookings')->assertSee('Customers')->assertSee('Activity Logs');
        $response->assertDontSee('href="http://localhost/courts"')->assertDontSee('href="http://localhost/admin/configuration"')
            ->assertDontSee('href="http://localhost/admin/equipment/availability"')->assertDontSee('href="http://localhost/announcements"')
            ->assertDontSee('href="http://localhost/reports"')->assertDontSee('href="http://localhost/admin/user-access"');
        $response->assertSee('Profile')->assertSee('admin-profile-trigger');
        $response->assertSee('Bookings')->assertDontSee('Add walk-in')->assertDontSee('Add event');

        $withActions = $this->signInStaff($this->staff(['bookings.view', 'bookings.create', 'calendar_events.manage', 'calendar_events.open_play']))
            ->get('/staff/dashboard')->assertOk();
        $withActions->assertDontSee('Add walk-in')->assertSee('Bookings')->assertSee('Add event');

        $withoutLogs = $this->signInStaff($this->staff(['bookings.view', 'customers.view']))->get('/staff/dashboard')->assertOk();
        $withoutLogs->assertDontSee('Activity Logs');
    }

    public function test_staff_cannot_load_admin_pages_or_admin_dashboard_data(): void
    {
        $staff = $this->staff(['bookings.view', 'customers.view', 'activity_logs.view']);
        if (config('auth.multi_guard')) {
            foreach ([
                '/staff/courts', '/staff/configuration', '/staff/equipment/availability',
                '/staff/announcements', '/staff/reports', '/staff/reports/data', '/staff/users',
                '/staff/activity_logs',
            ] as $path) {
                $this->signInStaff($staff)->get($path)->assertNotFound();
            }
            $this->signInStaff($staff)->get('/dashboard')->assertRedirect(route('admin.login'));
        } else {
            foreach ([
                '/dashboard', '/courts', '/admin/configuration', '/admin/equipment/availability',
                '/announcements', '/reports', '/reports/data', '/admin/users', '/admin/profile', '/activity_logs',
            ] as $path) {
                $this->signInStaff($staff)->get($path)->assertForbidden();
            }
        }

    }

    public function test_staff_activity_logs_are_allowlisted_paginated_and_redacted(): void
    {
        $staff = $this->staff(['activity_logs.view']);
        $customer = User::create([
            'username' => 'log-customer', 'name' => 'Customer Name', 'email' => 'customer-log@example.test',
            'password' => 'current-password-123', 'role' => 'user',
        ]);
        $booking = $this->bookingForToday($customer);
        $block = CourtBlock::create([
            'court_id' => $booking->court_id, 'type' => 'maintenance', 'title' => 'Court repair',
            'block_date' => now('Asia/Manila')->toDateString(), 'start_time' => '11:00', 'end_time' => '12:00',
            'created_by' => $staff->user_id,
        ]);

        $allowed = $this->activity('booking.created', 'Booking', (string) $booking->id);
        $this->activity('court_block.created', 'CourtBlock', (string) $block->id);
        $this->activity('calendar.admin_override', 'Booking', (string) $booking->id);
        $excluded = [
            ['user_access.updated', 'User', (string) $staff->user_id],
            ['profile.updated', 'User', (string) $customer->user_id],
            ['announcement.sent', null, null],
            ['schedule.pricing_updated', null, null],
            ['admin.logged_in', null, null],
            ['staff.mfa_recovery_codes_regenerated', 'User', (string) $staff->user_id],
            ['court.created', 'Court', (string) $booking->court_id],
            ['equipment.created', 'Equipment', '1'],
            ['unknown.new_action', 'Booking', (string) $booking->id],
        ];
        foreach ($excluded as [$action, $subjectType, $subjectId]) $this->activity($action, $subjectType, $subjectId);

        $page = $this->signInStaff($staff)->get('/staff/activity-logs')->assertOk();
        $page->assertSee('shared/activity-logs.css')->assertSee('staff-activity-logs.js')->assertDontSee('IP Address');
        $response = $this->getJson('/staff/activity-logs/data')->assertOk();
        $actions = array_column($response->json('data'), 'action');
        $this->assertEqualsCanonicalizing(['booking.created', 'court_block.created', 'calendar.admin_override'], $actions);
        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('ip_address', $row);
            $this->assertArrayNotHasKey('user_agent', $row);
            $this->assertArrayNotHasKey('properties', $row);
        }
        $this->assertSame(3, $response->json('pagination.total'));
    }

    public function test_staff_activity_logs_require_permission_and_admin_and_owner_activity_logs_remain_unfiltered(): void
    {
        $staff = $this->staff(['bookings.view']);
        $this->signInStaff($staff)->get('/staff/activity-logs')->assertForbidden();
        $this->signInStaff($staff)->getJson('/staff/activity-logs/data')->assertForbidden();

        $admin = User::create([
            'username' => 'activity-admin', 'name' => 'Activity Admin', 'email' => 'activity-admin@example.test',
            'password' => 'current-password-123', 'role' => 'admin',
        ]);
        $this->activity('user_access.updated', 'User', (string) $admin->user_id);
        $adminPage = $this->actingAs($admin)->withSession([
            'access_version_user_id' => $admin->user_id, 'access_version' => 1,
            'mfa_passed_at' => now()->timestamp, 'mfa_passed_user_id' => $admin->user_id,
        ])->get('/activity_logs')->assertOk();
        $adminPage->assertSee('IP Address')->assertSee('user_access.updated')->assertSee('User');

        $owner = User::create([
            'username' => 'activity-owner', 'name' => 'Activity Owner', 'email' => 'activity-owner@example.test',
            'password' => 'current-password-123', 'role' => 'owner',
        ]);
        $ownerPage = $this->actingAs($owner)->withSession([
            'access_version_user_id' => $owner->user_id, 'access_version' => 1,
            'mfa_passed_at' => now()->timestamp, 'mfa_passed_user_id' => $owner->user_id,
        ])->get('/owner/activity-logs')->assertOk();
        $ownerPage->assertSee('IP Address')->assertSee('user_access.updated');
    }

    private function activity(string $action, ?string $subjectType, ?string $subjectId): ActivityLog
    {
        return ActivityLog::create([
            'user_name' => 'Log Actor', 'action' => $action, 'subject_type' => $subjectType,
            'subject_id' => $subjectId, 'description' => 'Logged action', 'ip_address' => '192.0.2.10',
            'user_agent' => 'Private browser agent', 'properties' => ['email' => 'other@example.test'],
        ]);
    }

    public function test_staff_bookings_reuse_the_shared_board_assets_and_redact_amounts(): void
    {
        $staff = $this->staff();
        $this->bookingForToday($staff);

        $response = $this->signInStaff($staff)->get('/staff/bookings')->assertOk();
        $response->assertSee('shared/bookings-board.css')->assertSee('shared/bookings-board.js')->assertSee('staff-bookings.js');
        $response->assertSee('court-calendar-skeleton--month')
            ->assertSee('court-calendar-skeleton--week')
            ->assertSee('role="status"', false);
        $this->assertStringNotContainsString('Loading calendar…', $response->getContent());
        $response->assertSee('shared/dashboard-base.css')->assertSee('shared/dashboard-shell.css')->assertDontSee('css/admin/')->assertDontSee('js/admin/');
        $this->assertStringNotContainsString('500.00', $response->getContent());
        $this->assertStringNotContainsString('hourly_rate', $response->getContent());
    }

    public function test_staff_customers_reuse_the_shared_endpoint_but_hide_financial_data_and_admin_assets(): void
    {
        $staff = $this->staff();
        $this->bookingForToday($staff);

        $page = $this->signInStaff($staff)->get('/staff/customers')->assertOk();
        $page->assertSee('shared/customers.css')->assertSee('staff-customers.js');
        $page->assertSee('shared/dashboard-shell.js')->assertDontSee('css/admin/')->assertDontSee('js/admin/');

        $data = $this->getJson(role_route('admin.customers.data'))->assertOk()->json('customers.0');
        $this->assertArrayNotHasKey('total_spent', $data);
        $this->assertArrayNotHasKey('amount', $data['bookings'][0]);
    }

    public function test_staff_customer_spending_requires_its_separate_permission(): void
    {
        $staff = $this->staff(['customers.view', 'customers.view_spending']);
        $this->bookingForToday($staff);

        $page = $this->signInStaff($staff)->get('/staff/customers')->assertOk();
        $page->assertSee('Total Spent');
        $data = $this->getJson(role_route('admin.customers.data'))->assertOk()->json('customers.0');
        $this->assertArrayHasKey('total_spent', $data);
        $this->assertArrayHasKey('amount', $data['bookings'][0]);
    }

    public function test_shared_calendar_endpoints_require_the_matching_staff_permission(): void
    {
        $staff = $this->staff(['bookings.view']);
        $booking = $this->bookingForToday($staff);
        $date = now('Asia/Manila')->toDateString();

        $response = $this->signInStaff($staff)->getJson(role_route('admin.bookings.calendar')."?from={$date}&to={$date}")->assertOk();
        $this->assertArrayNotHasKey('amount', $response->json('bookings.0'));

        $this->patchJson(role_route('admin.bookings.update', ['booking' => $booking->id]), [
            'notes' => 'Forbidden update',
        ])->assertForbidden();
        if (config('auth.multi_guard')) {
            $this->postJson('/staff/bookings', [])->assertMethodNotAllowed();
        } else {
            $this->postJson('/admin/bookings', [])->assertForbidden();
        }
        $this->postJson(role_route('admin.blocks.store'), [])->assertForbidden();
    }

    public function test_staff_pages_require_staff_role_and_bookings_permission(): void
    {
        $withoutView = $this->staff([]);
        $this->signInStaff($withoutView)->get('/staff/bookings')->assertForbidden();
        $this->signInStaff($withoutView)->get('/staff/customers')->assertForbidden();

        $customer = User::create([
            'username' => 'not-staff', 'name' => 'Customer', 'email' => 'not-staff@example.test',
            'password' => 'current-password-123', 'role' => 'user',
        ]);
        $response = $this->signInStaff($customer)->get('/staff/dashboard');
        if (config('auth.multi_guard')) {
            $response->assertRedirect(route('staff.login'));
        } else {
            $response->assertForbidden();
        }
    }

    public function test_staff_overview_hides_booking_data_without_the_booking_permission(): void
    {
        $staff = $this->staff([]);
        $booking = $this->bookingForToday($staff);

        $page = $this->signInStaff($staff)->get('/staff/dashboard')->assertOk();
        $page->assertDontSee('Today\'s bookings')
            ->assertDontSee('Courts in use')
            ->assertDontSee('Next up')
            ->assertDontSee('Needs attention')
            ->assertDontSee('Open play')
            ->assertDontSee('Court status')
            ->assertDontSee($booking->customer_name)
            ->assertDontSee('09:00')
            ->assertDontSee('paid');

        $this->getJson('/staff/overview/data')->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to view booking data.');
    }

    public function test_legacy_walk_in_permission_does_not_allow_staff_to_create_walk_ins(): void
    {
        $staff = $this->staff(['bookings.create', 'bookings.view']);

        if (config('auth.multi_guard')) {
            $this->signInStaff($staff)->postJson('/staff/bookings', [])->assertMethodNotAllowed();
        } else {
            $this->signInStaff($staff)->postJson('/admin/bookings', [])->assertForbidden();
        }
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_open_play_permission_can_open_the_board_without_exposing_booking_data(): void
    {
        $staff = $this->staff(['calendar_events.open_play']);
        $this->bookingForToday($staff);
        $date = now('Asia/Manila')->toDateString();

        $page = $this->signInStaff($staff)->get('/staff/bookings')->assertOk();
        $page->assertSee('calendar_events.open_play')
            ->assertSee('"bookings":[]', false);

        $calendar = $this->getJson(role_route('admin.bookings.calendar')."?from={$date}&to={$date}")->assertOk();
        $this->assertSame([], $calendar->json('bookings'));
        $this->get('/staff/dashboard')->assertOk()->assertSee('Add event');
    }

    public function test_staff_profile_changes_require_the_current_password(): void
    {
        $staff = $this->staff();

        $this->signInStaff($staff)->from('/staff/profile')->put('/staff/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'replacement-password-123',
            'password_confirmation' => 'replacement-password-123',
        ])->assertRedirect('/staff/profile')->assertSessionHasErrors('current_password');

        $profile = $this->get('/staff/profile')->assertOk();
        $profile->assertSee('class="staff-profile-modal-overlay open"', false)
            ->assertSee('aria-hidden="false"', false)
            ->assertSee('aria-invalid="true"', false);

        $this->signInStaff($staff)->put('/staff/profile/password', [
            'current_password' => 'current-password-123',
            'password' => 'replacement-password-123',
            'password_confirmation' => 'replacement-password-123',
        ])->assertRedirect();

        $this->assertTrue(password_verify('replacement-password-123', $staff->fresh()->password));
    }

    public function test_staff_profile_method_selector_reflects_the_saved_method_and_admin_disable_state(): void
    {
        $staff = $this->staff();
        $staff->forceFill([
            'mfa_enabled' => true,
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_recovery_codes' => [],
        ])->save();

        $twoMethods = $staff->forceFill([
            'mfa_allowed_methods' => 'both',
            'mfa_method' => 'totp',
        ])->save();
        $this->assertTrue($twoMethods);

        $this->signInStaff($staff->fresh())->get('/staff/profile')
            ->assertOk()
            ->assertSee('aria-checked="false"', false)
            ->assertSee('aria-disabled="true"', false)
            ->assertSee('id="privilegedRecoveryCode"', false)
            ->assertSee('Coming soon');

        $staff->forceFill([
            'mfa_method' => 'totp',
            'mfa_admin_disabled_until' => now()->addHours(24),
        ])->save();

        $this->signInStaff($staff->fresh())->get('/staff/profile')
            ->assertOk()
            ->assertSee('MFA is turned off by an administrator.')
            ->assertSee('aria-checked="false"', false)
            ->assertSee('aria-disabled="true"', false);
    }
}
