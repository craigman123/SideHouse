<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\ActivityLog;
use App\Models\User;
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
        $dashboard->assertSee('staffSyncStatus')->assertSee("Today's bookings", false);

        $profile = $this->signInStaff($staff)->get('/staff/profile')->assertOk();
        $profile->assertSee('shared/profile.css')->assertSee('shared/dashboard-base.css')->assertDontSee('css/admin/');
        $profile->assertSee('Change password')->assertSee('Pending setup');
        $profile->assertSee('id="staffLogoutForm"', false)
            ->assertSee('action="'.route('logout').'"', false)
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

        $this->signInStaff($staff)->post(route('logout'))
            ->assertRedirect(route('login'))
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
        foreach ([
            '/dashboard', '/courts', '/admin/configuration', '/admin/equipment/availability',
            '/announcements', '/reports', '/reports/data', '/admin/users', '/admin/profile', '/activity_logs',
        ] as $path) {
            $this->signInStaff($staff)->get($path)->assertForbidden();
        }

        $this->signInStaff($staff)->get('/dashboard')->assertForbidden()->assertDontSee('Total Income')->assertDontSee('Today\'s Income');
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

        $data = $this->getJson('/customers/data')->assertOk()->json('customers.0');
        $this->assertArrayNotHasKey('total_spent', $data);
        $this->assertArrayNotHasKey('amount', $data['bookings'][0]);
    }

    public function test_staff_customer_spending_requires_its_separate_permission(): void
    {
        $staff = $this->staff(['customers.view', 'customers.view_spending']);
        $this->bookingForToday($staff);

        $page = $this->signInStaff($staff)->get('/staff/customers')->assertOk();
        $page->assertSee('Total Spent');
        $data = $this->getJson('/customers/data')->assertOk()->json('customers.0');
        $this->assertArrayHasKey('total_spent', $data);
        $this->assertArrayHasKey('amount', $data['bookings'][0]);
    }

    public function test_shared_calendar_endpoints_require_the_matching_staff_permission(): void
    {
        $staff = $this->staff(['bookings.view']);
        $booking = $this->bookingForToday($staff);
        $date = now('Asia/Manila')->toDateString();

        $response = $this->signInStaff($staff)->getJson("/admin/bookings/calendar?from={$date}&to={$date}")->assertOk();
        $this->assertArrayNotHasKey('amount', $response->json('bookings.0'));

        $this->patchJson("/admin/bookings/{$booking->id}", [
            'notes' => 'Forbidden update',
        ])->assertForbidden();
        $this->postJson('/admin/bookings', [])->assertForbidden();
        $this->postJson('/admin/blocks', [])->assertForbidden();
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
        $this->signInStaff($customer)->get('/staff/dashboard')->assertForbidden();
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
            ->assertDontSee('Open play today')
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

        $this->signInStaff($staff)->postJson('/admin/bookings', [])->assertForbidden();
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

        $calendar = $this->getJson("/admin/bookings/calendar?from={$date}&to={$date}")->assertOk();
        $this->assertSame([], $calendar->json('bookings'));
        $this->get('/staff/dashboard')->assertOk()->assertSee('Add event');
    }

    public function test_staff_profile_changes_require_the_current_password(): void
    {
        $staff = $this->staff();

        $this->signInStaff($staff)->put('/staff/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'replacement-password-123',
            'password_confirmation' => 'replacement-password-123',
        ])->assertSessionHasErrors('current_password');

        $this->signInStaff($staff)->put('/staff/profile/password', [
            'current_password' => 'current-password-123',
            'password' => 'replacement-password-123',
            'password_confirmation' => 'replacement-password-123',
        ])->assertRedirect();

        $this->assertTrue(app(\App\Support\PasswordHasher::class)->check($staff->fresh(), 'replacement-password-123'));
    }
}
