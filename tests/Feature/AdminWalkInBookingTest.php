<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWalkInBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();
        BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8, 'close_hour' => 7, 'step_minutes' => 30,
            'min_duration_hours' => 1, 'max_duration_hours' => 10, 'closed_weekdays' => [],
        ]);
        BusinessSetting::current()->update(['closed_weekdays' => []]);
        $this->admin = User::create([
            'username' => 'walkin-admin', 'name' => 'Walk-in Admin',
            'email' => 'walkin-admin@example.test', 'password' => 'password', 'role' => 'admin',
        ]);
        $this->court = Court::create([
            'name' => 'Court 1', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active',
        ]);
        $this->actingAs($this->admin)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_create_a_priced_walk_in_booking(): void
    {
        $response = $this->postJson('/admin/bookings', $this->payload());

        $response->assertCreated()->assertJsonPath('booking.source', 'walk_in');
        $booking = Booking::with('slots')->firstOrFail();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('cash', $booking->payment_method);
        $this->assertSame('1000.00', $booking->amount);
        $this->assertCount(4, $booking->slots);
        $this->assertDatabaseCount('payment_reference', 0);
    }

    public function test_conflicting_walk_in_is_rejected_with_conflict_details(): void
    {
        CourtBlock::create([
            'court_id' => $this->court->id, 'type' => 'maintenance', 'title' => 'Repair',
            'block_date' => '2030-05-15', 'start_time' => '09:00', 'end_time' => '10:00',
            'created_by' => $this->admin->user_id,
        ]);

        $this->postJson('/admin/bookings', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('conflict.type', 'maintenance')
            ->assertJsonPath('conflict.title', 'Repair');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_overnight_walk_in_slots_keep_their_own_dates(): void
    {
        $this->postJson('/admin/bookings', $this->payload(['date' => '2030-05-15', 'start_time' => '23:00', 'end_time' => '01:00']))
            ->assertCreated();

        $booking = Booking::with('slots')->firstOrFail();
        $this->assertSame(['2030-05-15', '2030-05-15', '2030-05-16', '2030-05-16'], $booking->slots->pluck('date')->map->toDateString()->all());
    }

    public function test_walk_in_requires_customer_name_and_contact_number(): void
    {
        $this->postJson('/admin/bookings', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_name', 'contact_number', 'court_id', 'date', 'start_time', 'end_time']);
    }

    public function test_past_walk_in_date_is_rejected_but_a_walk_in_already_in_progress_today_is_allowed(): void
    {
        $this->freezeVenueTime('2030-05-15 10:00:00');
        $this->postJson('/admin/bookings', $this->payload(['date' => '2030-05-14']))
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->postJson('/admin/bookings', $this->payload([
            'date' => '2030-05-15', 'start_time' => '09:00', 'end_time' => '11:00',
        ]))->assertCreated();
    }

    public function test_walk_in_date_uses_manila_day_after_utc_has_crossed_midnight(): void
    {
        $this->freezeVenueTime('2026-11-22 15:30:00', 'UTC'); // Nov 22, 23:30 in Manila
        $this->postJson('/admin/bookings', $this->payload([
            'date' => '2026-11-22', 'start_time' => '23:30', 'end_time' => '01:30',
        ]))->assertCreated();

        $this->postJson('/admin/bookings', $this->payload(['date' => '2026-11-21']))
            ->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function freezeVenueTime(string $value, string $timezone = 'Asia/Manila'): void
    {
        $instant = CarbonImmutable::parse($value, $timezone);
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
        $this->actingAs($this->admin)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $this->admin->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Walk-in Player', 'contact_number' => '09171234567',
            'court_id' => $this->court->id, 'date' => '2030-05-15',
            'start_time' => '09:00', 'end_time' => '11:00', 'payment_method' => 'cash',
        ], $overrides);
    }
}
