<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtClosure;
use App\Models\SpecificDateTimeClosure;
use App\Models\User;
use App\Services\CourtConflictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourtConflictServiceTest extends TestCase
{
    use RefreshDatabase;

    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();

        BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8,
            'close_hour' => 7,
            'step_minutes' => 30,
            'min_duration_hours' => 1,
            'max_duration_hours' => 10,
            'closed_weekdays' => [],
        ]);

        $this->court = Court::create([
            'name' => 'Court 1',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 500,
            'status' => 'active',
        ]);
    }

    public function test_post_block_returns_422_with_the_conflicting_booking(): void
    {
        $admin = User::create([
            'username' => 'admin',
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
        $booking = $this->createBooking('2030-05-15', '10:00:00', '11:00:00');

        $response = $this->actingAs($admin)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $admin->user_id,
            'access_version_user_id' => $admin->user_id,
            'access_version' => 1,
        ])->postJson('/admin/blocks', [
            'court_id' => $this->court->id,
            'type' => 'maintenance',
            'title' => 'Repair work',
            'block_date' => '2030-05-15',
            'start_time' => '10:30',
            'end_time' => '11:30',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('conflict.type', 'booking')
            ->assertJsonPath('conflict.id', $booking->id);
        $this->assertDatabaseCount('court_blocks', 0);
    }

    public function test_overnight_block_conflicts_with_a_request_after_midnight(): void
    {
        $admin = User::create([
            'username' => 'admin-overnight',
            'name' => 'Admin',
            'email' => 'admin-overnight@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
        CourtBlock::create([
            'court_id' => $this->court->id,
            'type' => 'closed',
            'title' => 'Overnight closure',
            'block_date' => '2030-05-15',
            'start_time' => '22:00',
            'end_time' => '02:00',
            'created_by' => $admin->user_id,
        ]);

        $conflict = app(CourtConflictService::class)->findConflict(
            $this->court->id,
            '2030-05-16',
            '01:00',
            '03:00',
        );

        $this->assertSame('closed', $conflict['type']);
        $this->assertSame('Overnight closure', $conflict['title']);
    }

    public function test_specific_date_closing_cutoff_is_reported_as_the_conflict(): void
    {
        $closure = SpecificDateTimeClosure::create([
            'date' => '2030-05-15',
            'time_closing' => '10:30',
        ]);

        $conflict = app(CourtConflictService::class)->findConflict(
            $this->court->id,
            '2030-05-15',
            '10:00',
            '11:00',
        );

        $this->assertSame('specific_date_closure', $conflict['type']);
        $this->assertSame($closure->id, $conflict['id']);
    }

    public function test_full_day_court_closure_is_a_conflict(): void
    {
        $closure = CourtClosure::create([
            'court_id' => $this->court->id,
            'date' => '2030-05-15',
            'reason' => 'Private event',
        ]);

        $conflict = app(CourtConflictService::class)->findConflict(
            $this->court->id,
            '2030-05-15',
            '10:00',
            '11:00',
        );

        $this->assertSame('court_closure', $conflict['type']);
        $this->assertSame($closure->id, $conflict['id']);
    }

    public function test_conflict_check_can_ignore_the_booking_being_moved(): void
    {
        $booking = $this->createBooking('2030-05-15', '10:00:00', '11:00:00');

        $service = app(CourtConflictService::class);
        $this->assertSame('booking', $service->findConflict(
            $this->court->id,
            '2030-05-15',
            '10:00',
            '11:00',
        )['type']);

        $this->assertNull($service->findConflict(
            $this->court->id,
            '2030-05-15',
            '10:00',
            '11:00',
            $booking->id,
        ));
    }

    private function createBooking(string $date, string $start, string $end): Booking
    {
        $booking = Booking::create([
            'customer_name' => 'Test Player',
            'court_id' => $this->court->id,
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'amount' => 500,
            'status' => 'paid',
        ]);

        BookingSlot::create([
            'booking_id' => $booking->id,
            'court_id' => $this->court->id,
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'price' => 500,
        ]);

        return $booking;
    }
}
