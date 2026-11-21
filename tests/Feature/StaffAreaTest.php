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
        $dashboard->assertSee('staff-layout.css')->assertSee('staff-overview.css')->assertSee('staff-overview.js');
        $dashboard->assertDontSee('admin-shell.css')->assertDontSee('admin-shell.js')->assertDontSee('admin-overview.css');
        $dashboard->assertSee('staffSyncStatus')->assertSee("Today's bookings", false);

        $profile = $this->signInStaff($staff)->get('/staff/profile')->assertOk();
        $profile->assertSee('staff-profile.css')->assertDontSee('admin-profile.css');
        $profile->assertSee('Change password')->assertSee('Pending setup');
    }

    public function test_staff_navigation_is_permission_driven_and_contains_only_the_allowed_sections(): void
    {
        $staff = $this->staff(['bookings.view', 'customers.view', 'activity_logs.view']);
        $response = $this->signInStaff($staff)->get('/staff/dashboard')->assertOk();
        $response->assertSee('Overview')->assertSee('Bookings')->assertSee('Customers')->assertSee('Activity Logs');
        $response->assertDontSee('Courts')->assertDontSee('Configuration')->assertDontSee('Equipment')
            ->assertDontSee('Announcements')->assertDontSee('Reports')->assertDontSee('User access');
        $response->assertSee('Profile')->assertSee('staff-user-block');

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
        $page->assertSee('staff-activity-logs.css')->assertSee('staff-activity-logs.js')->assertDontSee('IP Address');
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
        $response->assertSee('admin-bookings-board.css')->assertSee('admin-bookings-board.js')->assertSee('staff-bookings.js');
        $response->assertDontSee('admin-shell.css')->assertDontSee('admin-shell.js')->assertDontSee('admin-base.css');
        $this->assertStringNotContainsString('500.00', $response->getContent());
        $this->assertStringNotContainsString('hourly_rate', $response->getContent());
    }

    public function test_staff_customers_reuse_the_shared_endpoint_but_hide_financial_data_and_admin_assets(): void
    {
        $staff = $this->staff();
        $this->bookingForToday($staff);

        $page = $this->signInStaff($staff)->get('/staff/customers')->assertOk();
        $page->assertSee('staff-customers.css')->assertSee('staff-customers.js');
        $page->assertDontSee('admin-customers.css')->assertDontSee('admin-customers.js')->assertDontSee('admin-shell.js');

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

        $this->assertTrue(password_verify('replacement-password-123', $staff->fresh()->password));
    }
}
