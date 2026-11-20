<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCalendarEditTest extends TestCase
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
            'username' => 'calendar-edit-admin', 'name' => 'Calendar Edit Admin',
            'email' => 'calendar-edit-admin@example.test', 'password' => 'password', 'role' => 'admin',
        ]);
        $this->court = Court::create([
            'name' => 'Edit Court', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active',
        ]);
        $this->actingAs($this->admin)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $this->admin->id,
        ]);
    }

    public function test_editing_contact_details_does_not_rewrite_slots_or_price(): void
    {
        $booking = $this->booking();
        $slotIds = $booking->slots()->pluck('id')->all();
        $slotPrices = $booking->slots()->pluck('price')->all();

        $this->patchJson("/admin/bookings/{$booking->id}", [
            'contact_number' => '09179998888', 'notes' => 'Call on arrival',
        ])->assertOk()->assertJsonPath('booking.contact_number', '09179998888');

        $this->assertSame($slotIds, $booking->fresh()->slots()->pluck('id')->all());
        $this->assertSame($slotPrices, $booking->fresh()->slots()->pluck('price')->all());
        $this->assertSame('1000.00', $booking->fresh()->amount);
    }

    public function test_editing_time_rewrites_slots(): void
    {
        $booking = $this->booking();

        $this->patchJson("/admin/bookings/{$booking->id}", [
            'start_time' => '10:00', 'end_time' => '12:00',
        ])->assertOk()->assertJsonPath('booking.start', '10:00');

        $this->assertTrue(BookingSlot::query()->where('booking_id', $booking->id)->whereDate('date', '2030-05-15')->where('start_time', '10:00:00')->exists());
        $this->assertFalse(BookingSlot::query()->where('booking_id', $booking->id)->where('start_time', '09:00:00')->exists());
    }

    public function test_edit_that_conflicts_is_rejected(): void
    {
        $booking = $this->booking();
        $this->booking(['customer_name' => 'Other Player', 'start_time' => '12:00', 'end_time' => '13:00']);

        $this->patchJson("/admin/bookings/{$booking->id}", [
            'start_time' => '12:00', 'end_time' => '14:00',
        ])->assertUnprocessable()->assertJsonPath('conflict.type', 'booking');
        $this->assertSame('09:00', substr((string) $booking->fresh()->start_time, 0, 5));
    }

    public function test_cancelled_booking_cannot_be_edited(): void
    {
        $booking = $this->booking(['status' => 'cancelled']);

        $this->patchJson("/admin/bookings/{$booking->id}", ['contact_number' => '09179998888'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cancelled or expired bookings cannot be edited.');
        $this->assertSame('09171234567', $booking->fresh()->contact_number);
    }

    public function test_paid_booking_amount_change_requires_acknowledgement(): void
    {
        $booking = $this->booking(['status' => 'paid']);

        $this->patchJson("/admin/bookings/{$booking->id}", ['amount' => 1200])
            ->assertUnprocessable()
            ->assertJsonPath('old_amount', 1000)
            ->assertJsonPath('new_amount', 1200);
        $this->assertSame('1000.00', $booking->fresh()->amount);
    }

    public function test_confirmed_booking_amount_change_with_acknowledgement_is_logged(): void
    {
        $booking = $this->booking(['status' => 'confirmed']);

        $this->patchJson("/admin/bookings/{$booking->id}", [
            'amount' => 1200, 'acknowledge_price_change' => true,
        ])->assertOk()->assertJsonPath('booking.amount', 1200);

        $this->assertSame('1200.00', $booking->fresh()->amount);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'booking.admin_updated',
            'subject_id' => $booking->id,
        ]);
    }

    public function test_editing_a_block_into_a_booking_conflict_is_rejected(): void
    {
        $booking = $this->booking();
        $block = CourtBlock::create([
            'court_id' => $this->court->id, 'type' => 'maintenance', 'title' => 'Repair',
            'block_date' => '2030-05-15', 'start_time' => '14:00', 'end_time' => '15:00',
            'created_by' => $this->admin->user_id,
        ]);

        $this->patchJson("/admin/blocks/{$block->id}", [
            'block_date' => '2030-05-15', 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertUnprocessable()->assertJsonPath('conflict.type', 'booking');
        $this->assertSame('14:00', substr((string) $block->fresh()->start_time, 0, 5));
    }

    private function booking(array $overrides = []): Booking
    {
        $booking = Booking::create(array_merge([
            'customer_name' => 'Player One', 'contact_number' => '09171234567',
            'court_id' => $this->court->id, 'date' => '2030-05-15',
            'start_time' => '09:00', 'end_time' => '11:00', 'amount' => 1000,
            'status' => 'paid', 'source' => 'guest',
        ], $overrides));
        $cursor = \Carbon\CarbonImmutable::parse('2030-05-15 '.($overrides['start_time'] ?? '09:00'));
        $end = \Carbon\CarbonImmutable::parse('2030-05-15 '.($overrides['end_time'] ?? '11:00'));
        if ($end->lessThanOrEqualTo($cursor)) $end = $end->addDay();
        while ($cursor->lessThan($end)) {
            $booking->slots()->create([
                'court_id' => $this->court->id,
                'date' => $cursor->toDateString(),
                'start_time' => $cursor->format('H:i:s'),
                'end_time' => $cursor->addHour()->format('H:i:s'),
                'price' => 500,
            ]);
            $cursor = $cursor->addHour();
        }
        return $booking;
    }
}
