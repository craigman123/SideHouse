<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCalendarRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Booking $booking;
    private string $loginUsername = 'calendar-limit-admin';

    protected function setUp(): void
    {
        parent::setUp();

        BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8, 'close_hour' => 7, 'step_minutes' => 30,
            'min_duration_hours' => 1, 'max_duration_hours' => 10, 'closed_weekdays' => [],
        ]);
        BusinessSetting::current()->update(['closed_weekdays' => []]);
        $this->admin = User::create([
            'username' => $this->loginUsername, 'name' => 'Calendar Limit Admin',
            'email' => 'calendar-limit-admin@example.test', 'password' => 'password', 'role' => 'admin',
        ]);
        $court = Court::create([
            'name' => 'Rate Limit Court', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active',
        ]);
        $this->booking = Booking::create([
            'customer_name' => 'Rate Limit Customer', 'contact_number' => '09171234567',
            'court_id' => $court->id, 'date' => '2030-05-15', 'start_time' => '09:00',
            'end_time' => '10:00', 'amount' => 500, 'status' => 'confirmed', 'source' => 'guest',
        ]);
        $this->booking->slots()->create([
            'court_id' => $court->id, 'date' => '2030-05-15',
            'start_time' => '09:00', 'end_time' => '10:00', 'price' => 500,
        ]);

        $this->clearRateLimits();
    }

    protected function tearDown(): void
    {
        $this->clearRateLimits();
        parent::tearDown();
    }

    public function test_booking_quotes_do_not_consume_the_save_bucket(): void
    {
        $this->authenticateAdmin();
        $quote = [
            'date' => '2030-05-15', 'start_time' => '10:00', 'end_time' => '11:00',
            'dry_run' => true,
            'expected_updated_at' => $this->booking->updated_at->format('Y-m-d\\TH:i:s.uP'),
        ];

        for ($attempt = 0; $attempt < 600; $attempt++) {
            $this->patchJson("/admin/bookings/{$this->booking->id}", $quote)->assertOk();
        }

        $this->patchJson("/admin/bookings/{$this->booking->id}", [
            'notes' => 'Saved after a full quote bucket',
        ])->assertOk()->assertJsonPath('booking.notes', 'Saved after a full quote bucket');
    }

    public function test_calendar_reads_do_not_consume_the_save_bucket(): void
    {
        $this->authenticateAdmin();

        for ($attempt = 0; $attempt < 600; $attempt++) {
            $this->getJson('/admin/bookings/calendar?from=2030-05-15&to=2030-05-21')->assertOk();
        }

        $this->patchJson("/admin/bookings/{$this->booking->id}", [
            'notes' => 'Saved after a full read bucket',
        ])->assertOk()->assertJsonPath('booking.notes', 'Saved after a full read bucket');
    }

    public function test_staff_uses_the_lower_booking_save_limit(): void
    {
        $staff = User::create([
            'username' => 'calendar-limit-staff', 'name' => 'Calendar Limit Staff',
            'email' => 'calendar-limit-staff@example.test', 'password' => 'password', 'role' => 'staff',
        ]);
        $identity = 'user:'.$staff->getAuthIdentifier();
        RateLimiter::clear(md5('admin-calendar-save'.$identity));

        $this->actingAs($staff)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $staff->user_id,
            'access_version_user_id' => $staff->user_id,
            'access_version' => 1,
        ]);

        try {
            for ($attempt = 0; $attempt < 60; $attempt++) {
                $this->patchJson("/admin/bookings/{$this->booking->id}", [
                    'date' => 'not-a-date',
                ])->assertUnprocessable();
            }

            $this->patchJson("/admin/bookings/{$this->booking->id}", [
                'date' => 'not-a-date',
            ])->assertTooManyRequests()->assertHeader('Retry-After');
        } finally {
            RateLimiter::clear(md5('admin-calendar-save'.$identity));
        }
    }

    public function test_admin_booking_save_bucket_allows_600_requests_then_returns_retry_after(): void
    {
        $this->authenticateAdmin();

        for ($attempt = 0; $attempt < 600; $attempt++) {
            $this->patchJson("/admin/bookings/{$this->booking->id}", [
                'date' => 'not-a-date',
            ])->assertUnprocessable();
        }

        $response = $this->patchJson("/admin/bookings/{$this->booking->id}", [
            'date' => 'not-a-date',
        ]);

        $response->assertTooManyRequests()->assertHeader('Retry-After');
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertSame("Too many changes. Try again in {$retryAfter} seconds.", $response->json('message'));
    }

    public function test_stacked_management_limits_are_scaled_and_do_not_share_guest_counters(): void
    {
        $this->authenticateAdmin();

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $this->postJson('/admin/blocks', [])->assertUnprocessable();
        }

        $this->postJson('/admin/blocks', [])->assertTooManyRequests();

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->getJson('/guest/booking/latest?email=missing@example.test')->assertOk();
        }

        $this->getJson('/guest/booking/latest?email=missing@example.test')->assertTooManyRequests();
    }

    public function test_owner_gets_five_times_the_admin_management_calendar_read_limit(): void
    {
        $owner = User::create([
            'username' => 'calendar-limit-owner', 'name' => 'Calendar Limit Owner',
            'email' => 'calendar-limit-owner@example.test', 'password' => 'password', 'role' => 'owner',
        ]);
        $identity = 'admin-mgmt:user:'.$owner->getAuthIdentifier();
        RateLimiter::clear(md5('admin-calendar-read'.$identity));

        $this->actingAs($owner)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $owner->user_id,
            'access_version_user_id' => $owner->user_id,
            'access_version' => 1,
        ]);

        try {
            for ($attempt = 0; $attempt < 600; $attempt++) {
                $this->getJson('/admin/bookings/calendar?from=2030-05-15&to=2030-05-21')->assertOk();
            }

            $this->getJson('/admin/bookings/calendar?from=2030-05-15&to=2030-05-21')
                ->assertTooManyRequests();
        } finally {
            RateLimiter::clear(md5('admin-calendar-read'.$identity));
        }
    }

    public function test_customer_keeps_the_existing_guest_route_limit(): void
    {
        $customer = User::create([
            'username' => 'calendar-limit-customer', 'name' => 'Calendar Limit Customer',
            'email' => 'calendar-limit-customer@example.test', 'password' => 'password', 'role' => 'user',
        ]);
        RateLimiter::clear(sha1((string) $customer->getAuthIdentifier()));
        $this->actingAs($customer);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->getJson('/guest/booking/latest?email=missing@example.test')->assertOk();
        }

        $this->getJson('/guest/booking/latest?email=missing@example.test')->assertTooManyRequests();
    }

    public function test_password_confirmed_user_access_route_keeps_its_existing_stacked_limit(): void
    {
        $this->authenticateAdmin();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/admin/users', [])->assertUnprocessable();
        }

        $this->postJson('/admin/users', [])->assertTooManyRequests();
    }

    public function test_login_limiter_remains_five_attempts_per_minute(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', [
                'username' => $this->loginUsername,
                'password' => 'incorrect-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/login', [
            'username' => $this->loginUsername,
            'password' => 'incorrect-password',
        ])->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many login attempts. Please try again in a minute.')
            ->assertHeader('Retry-After');
    }

    public function test_register_limiter_remains_five_attempts_per_minute(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/register', [])->assertUnprocessable();
        }

        $this->postJson('/register', [])->assertTooManyRequests();
    }

    private function authenticateAdmin(): void
    {
        $this->actingAs($this->admin)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $this->admin->user_id,
            'access_version_user_id' => $this->admin->user_id,
            'access_version' => 1,
        ]);
    }

    private function clearRateLimits(): void
    {
        $identity = 'user:'.$this->admin->getAuthIdentifier();
        foreach ([
            'admin-calendar-quote',
            'admin-calendar-save',
            'admin-calendar-delete',
            'admin-closures',
            'admin-calendar-read',
        ] as $limiter) {
            RateLimiter::clear(md5($limiter.$identity));
            RateLimiter::clear(md5($limiter.'admin-mgmt:'.$identity));
        }
        RateLimiter::clear(sha1((string) $this->admin->getAuthIdentifier()));
        RateLimiter::clear(sha1('admin-mgmt:'.sha1((string) $this->admin->getAuthIdentifier())));
        RateLimiter::clear(md5('login'.Str::lower($this->loginUsername).'|127.0.0.1'));
        RateLimiter::clear(md5('register127.0.0.1'));
    }
}
