<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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
            'mfa_passed_user_id' => $this->admin->user_id,
            'access_version_user_id' => $this->admin->user_id,
            'access_version' => 1,
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

    public function test_drag_price_quote_is_read_only_and_returns_new_amount(): void
    {
        $booking = $this->booking();
        $this->patchJson("/admin/bookings/{$booking->id}", [
            'date' => '2030-05-15', 'start_time' => '09:00', 'end_time' => '12:00',
            'dry_run' => true, 'expected_updated_at' => $booking->updated_at->format('Y-m-d\\TH:i:s.uP'),
        ])->assertOk()->assertJsonPath('quote.amount_changed', true)->assertJsonPath('quote.new_amount', 1500);

        $this->assertSame('11:00', substr((string) $booking->fresh()->end_time, 0, 5));
        $this->assertSame('1000.00', $booking->fresh()->amount);
        $this->assertSame(2, $booking->slots()->where('is_active', true)->count());
    }

    public function test_adjust_minutes_extends_or_shortens_and_preserves_inactive_slot_prices(): void
    {
        $booking = $this->booking(['status' => 'confirmed']);
        $this->patchJson("/admin/bookings/{$booking->id}", ['adjust_minutes' => 30, 'acknowledge_price_change' => true])
            ->assertOk()->assertJsonPath('booking.end', '11:30')->assertJsonPath('booking.amount', 1250);
        $this->assertSame(3, $booking->fresh()->slots()->where('is_active', true)->count());

        $this->patchJson("/admin/bookings/{$booking->id}", ['adjust_minutes' => -30, 'acknowledge_price_change' => true])
            ->assertOk()->assertJsonPath('booking.end', '11:00')->assertJsonPath('booking.amount', 1000);
        $this->assertSame(1, $booking->fresh()->slots()->where('is_active', false)->count());
        $this->assertSame(3, $booking->fresh()->slots()->count());
    }

    public function test_duration_cannot_be_shortened_below_configured_minimum(): void
    {
        $booking = $this->booking();
        $this->patchJson("/admin/bookings/{$booking->id}", ['adjust_minutes' => -90])
            ->assertUnprocessable();
        $this->assertSame('11:00', substr((string) $booking->fresh()->end_time, 0, 5));
    }

    public function test_overnight_duration_adjustment_keeps_booking_envelope_and_slot_dates_aligned(): void
    {
        $booking = $this->booking(['date' => '2030-05-15', 'start_time' => '23:00', 'end_time' => '01:00', 'amount' => 1000]);
        $this->patchJson("/admin/bookings/{$booking->id}", ['adjust_minutes' => -60, 'acknowledge_price_change' => true])
            ->assertOk()->assertJsonPath('booking.start', '23:00')->assertJsonPath('booking.end', '00:00');
        $updated = $booking->fresh();
        $this->assertSame('2030-05-15', $updated->date->toDateString());
        $this->assertSame(1, $updated->slots()->where('is_active', true)->count());
        $this->assertSame('2030-05-15', $updated->slots()->where('is_active', true)->first()->date->toDateString());
    }

    public function test_paid_duration_price_change_requires_ack_and_shortening_logs_refund_review(): void
    {
        $booking = $this->booking(['status' => 'paid']);
        $this->patchJson("/admin/bookings/{$booking->id}", ['adjust_minutes' => -60])
            ->assertUnprocessable()->assertJsonPath('old_amount', 1000)->assertJsonPath('new_amount', 500);
        $this->assertSame('11:00', substr((string) $booking->fresh()->end_time, 0, 5));

        $this->patchJson("/admin/bookings/{$booking->id}", ['adjust_minutes' => -60, 'acknowledge_price_change' => true])
            ->assertOk()->assertJsonPath('booking.end', '10:00')->assertJsonPath('booking.amount', 500);
        $this->assertDatabaseHas('activity_logs', ['action' => 'booking.admin_refund_review', 'subject_id' => $booking->id]);
        $this->assertSame(2, $booking->fresh()->slots()->count());
        $this->assertSame(1, $booking->fresh()->slots()->where('is_active', false)->count());
    }

    public function test_extension_may_overlap_booking_and_records_the_overlap_for_review(): void
    {
        $booking = $this->booking(['status' => 'confirmed']);
        $other = Booking::create([
            'customer_name' => 'Adjacent Player', 'contact_number' => '09170000000',
            'court_id' => $this->court->id, 'date' => '2030-05-15', 'start_time' => '10:30',
            'end_time' => '11:30', 'amount' => 500, 'status' => 'confirmed', 'source' => 'guest',
        ]);
        $other->slots()->create([
            'court_id' => $this->court->id, 'date' => '2030-05-15', 'start_time' => '10:30',
            'end_time' => '11:30', 'price' => 500,
        ]);
        $this->patchJson("/admin/bookings/{$booking->id}", ['adjust_minutes' => 30, 'acknowledge_price_change' => true])
            ->assertOk()->assertJsonPath('booking.end', '11:30');
        $this->assertDatabaseHas('activity_logs', ['action' => 'booking.admin_extension_overlap', 'subject_id' => $booking->id]);
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

    public function test_browser_cannot_tamper_with_booking_amount(): void
    {
        $booking = $this->booking(['status' => 'paid']);

        $this->patchJson("/admin/bookings/{$booking->id}", ['amount' => 1200])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Booking amounts must use the calculated price confirmation.');
        $this->assertSame('1000.00', $booking->fresh()->amount);
    }

    public function test_new_calculated_price_requires_current_amount_and_rules_version(): void
    {
        $settings = BusinessSetting::current();
        $settings->update([
            'peak_start_hour' => 17, 'peak_end_hour' => 19,
            'peak_adjustment_type' => 'percent', 'peak_adjustment_value' => 20,
        ]);
        $booking = $this->booking(['status' => 'confirmed']);
        $pricing = app(\App\Services\BookingPricingService::class);
        $rules = $pricing->clientRules();

        $this->patchJson("/admin/bookings/{$booking->id}", [
            'start_time' => '17:00', 'end_time' => '19:00', 'price_decision' => 'new',
            'expected_amount' => 1200, 'price_rules_version' => $rules['version'], 'acknowledge_price_change' => true,
        ])->assertOk()->assertJsonPath('booking.amount', 1200);

        $this->assertSame('1200.00', $booking->fresh()->amount);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'booking.admin_updated',
            'subject_id' => $booking->id,
        ]);
    }

    public function test_keep_price_leaves_amount_unchanged_when_moving(): void
    {
        $settings = BusinessSetting::current();
        $settings->update(['peak_start_hour' => 17, 'peak_end_hour' => 19, 'peak_adjustment_type' => 'percent', 'peak_adjustment_value' => 20]);
        $booking = $this->booking();
        $this->patchJson("/admin/bookings/{$booking->id}", [
            'start_time' => '17:00', 'end_time' => '19:00', 'price_decision' => 'keep',
        ])->assertOk()->assertJsonPath('booking.amount', 1000);
        $this->assertSame('1000.00', $booking->fresh()->amount);
    }

    public function test_new_price_rejects_stale_expected_amount_without_saving(): void
    {
        BusinessSetting::current()->update(['peak_start_hour' => 17, 'peak_end_hour' => 19, 'peak_adjustment_type' => 'percent', 'peak_adjustment_value' => 20]);
        $booking = $this->booking();
        $rules = app(\App\Services\BookingPricingService::class)->clientRules();
        $this->patchJson("/admin/bookings/{$booking->id}", [
            'start_time' => '17:00', 'end_time' => '19:00', 'price_decision' => 'new',
            'expected_amount' => 999, 'price_rules_version' => $rules['version'], 'acknowledge_price_change' => true,
        ])->assertStatus(409)->assertJsonPath('quote.new_amount', 1200);
        $this->assertSame('09:00', substr((string) $booking->fresh()->start_time, 0, 5));
        $this->assertSame('1000.00', $booking->fresh()->amount);
    }

    public function test_new_price_rejects_changed_rules_hash_and_returns_fresh_amount(): void
    {
        $settings = BusinessSetting::current();
        $settings->update(['peak_start_hour' => 17, 'peak_end_hour' => 19, 'peak_adjustment_type' => 'percent', 'peak_adjustment_value' => 20]);
        $oldRules = app(\App\Services\BookingPricingService::class)->clientRules();
        $settings->update(['peak_adjustment_value' => 30]);
        $booking = $this->booking();
        $this->patchJson("/admin/bookings/{$booking->id}", [
            'start_time' => '17:00', 'end_time' => '19:00', 'price_decision' => 'new',
            'expected_amount' => 1300, 'price_rules_version' => $oldRules['version'], 'acknowledge_price_change' => true,
        ])->assertStatus(409)->assertJsonPath('quote.new_amount', 1300);
        $this->assertSame('1000.00', $booking->fresh()->amount);
    }

    public function test_qr_paid_booking_rejects_new_price_decision(): void
    {
        $booking = $this->booking(['payment_method' => 'qrph']);
        $rules = app(\App\Services\BookingPricingService::class)->clientRules();
        $this->patchJson("/admin/bookings/{$booking->id}", [
            'start_time' => '10:00', 'end_time' => '12:00', 'price_decision' => 'new',
            'expected_amount' => 1000, 'price_rules_version' => $rules['version'], 'acknowledge_price_change' => true,
        ])->assertForbidden()->assertJsonPath('message', 'QR-paid booking amounts cannot be changed.');
        $this->assertSame('09:00', substr((string) $booking->fresh()->start_time, 0, 5));
    }

    public function test_staff_with_booking_edit_permission_cannot_choose_a_new_price(): void
    {
        $staff = User::create([
            'username' => 'calendar-edit-staff', 'name' => 'Calendar Edit Staff',
            'email' => 'calendar-edit-staff@example.test', 'password' => 'password', 'role' => 'staff',
            'permissions' => ['bookings.view', 'bookings.edit'],
        ]);
        $booking = $this->booking();
        $rules = app(\App\Services\BookingPricingService::class)->clientRules();
        $this->actingAs($staff)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $staff->user_id,
            'access_version_user_id' => $staff->user_id,
            'access_version' => 1,
        ])->patchJson("/admin/bookings/{$booking->id}", [
            'start_time' => '17:00', 'end_time' => '19:00', 'price_decision' => 'new',
            'expected_amount' => 1200, 'price_rules_version' => $rules['version'], 'acknowledge_price_change' => true,
        ])->assertForbidden()->assertJsonPath('message', 'You do not have permission to change booking prices.');
        $this->assertSame('1000.00', $booking->fresh()->amount);
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

    public function test_booking_on_past_date_allows_contact_notes_but_rejects_schedule_edits(): void
    {
        $this->freezeVenueTime('2030-05-16 12:00:00');
        $past = $this->booking(['date' => '2030-05-15']);
        $this->patchJson("/admin/bookings/{$past->id}", [
            'contact_number' => '09179998888', 'notes' => 'Updated note',
        ])->assertOk()->assertJsonPath('booking.contact_number', '09179998888');

        $this->patchJson("/admin/bookings/{$past->id}", ['date' => '2030-05-17'])
            ->assertUnprocessable()->assertJsonPath('message', 'Past bookings cannot have their date, time, court, or amount changed.');
        $this->patchJson("/admin/bookings/{$past->id}", ['start_time' => '10:00'])
            ->assertUnprocessable()->assertJsonPath('message', 'Past bookings cannot have their date, time, court, or amount changed.');
    }

    public function test_booking_cannot_be_moved_to_yesterday(): void
    {
        $this->freezeVenueTime('2030-05-15 12:00:00');
        $booking = $this->booking();
        $this->patchJson("/admin/bookings/{$booking->id}", ['date' => '2030-05-14'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_block_create_and_open_play_use_manila_today_and_reject_past_dates(): void
    {
        $this->freezeVenueTime('2030-05-15 23:30:00');
        $payload = [
            'court_id' => $this->court->id, 'type' => 'open_play', 'title' => 'Night Open Play',
            'block_date' => '2030-05-15', 'start_time' => '23:30', 'end_time' => '01:30',
            'max_players' => 8, 'price_per_player' => 125,
        ];
        $this->postJson('/admin/blocks', $payload)->assertCreated()
            ->assertJsonPath('block.max_players', 8)->assertJsonPath('block.price_per_player', '125.00');
        $this->postJson('/admin/blocks', [...$payload, 'max_players' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('max_players');
        $this->postJson('/admin/blocks', [...$payload, 'price_per_player' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('price_per_player');
        $this->postJson('/admin/blocks', [...$payload, 'block_date' => '2030-05-14'])
            ->assertUnprocessable()->assertJsonValidationErrors('block_date');
    }

    public function test_block_date_boundary_uses_manila_when_utc_is_already_next_day(): void
    {
        $this->freezeVenueTime('2026-11-22 15:30:00', 'UTC');
        $payload = [
            'court_id' => $this->court->id, 'type' => 'closed', 'title' => 'Late closure',
            'block_date' => '2026-11-22', 'start_time' => '23:30', 'end_time' => '01:30',
        ];
        $this->postJson('/admin/blocks', $payload)->assertCreated();
        $this->postJson('/admin/blocks', [...$payload, 'block_date' => '2026-11-21'])
            ->assertUnprocessable()->assertJsonValidationErrors('block_date');
    }

    public function test_new_calendar_events_cannot_start_before_the_manila_clock_today(): void
    {
        $this->freezeVenueTime('2030-05-15 12:00:00');
        $this->postJson('/admin/blocks', [
            'court_id' => $this->court->id, 'type' => 'maintenance', 'title' => 'Past start',
            'block_date' => '2030-05-15', 'start_time' => '11:30', 'end_time' => '13:30',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_time');

        $booking = $this->booking(['start_time' => '13:00', 'end_time' => '15:00']);
        $this->patchJson("/admin/bookings/{$booking->id}", [
            'date' => '2030-05-15', 'start_time' => '11:30', 'end_time' => '13:30',
        ])->assertUnprocessable();
    }

    public function test_past_block_allows_notes_but_rejects_court_or_time_changes(): void
    {
        $this->freezeVenueTime('2030-05-16 12:00:00');
        $block = CourtBlock::create([
            'court_id' => $this->court->id, 'type' => 'maintenance', 'title' => 'Old repair',
            'block_date' => '2030-05-15', 'start_time' => '09:00', 'end_time' => '10:00',
            'created_by' => $this->admin->user_id,
        ]);
        $this->patchJson("/admin/blocks/{$block->id}", ['notes' => 'Keep this note'])
            ->assertOk();
        $this->patchJson("/admin/blocks/{$block->id}", ['start_time' => '09:30'])
            ->assertUnprocessable();
        $this->patchJson("/admin/blocks/{$block->id}", ['court_id' => null])
            ->assertUnprocessable();
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
            'mfa_passed_user_id' => $this->admin->user_id,
            'access_version_user_id' => $this->admin->user_id,
            'access_version' => 1,
        ]);
    }

    private function booking(array $overrides = []): Booking
    {
        $booking = Booking::create(array_merge([
            'customer_name' => 'Player One', 'contact_number' => '09171234567',
            'court_id' => $this->court->id, 'date' => '2030-05-15',
            'start_time' => '09:00', 'end_time' => '11:00', 'amount' => 1000,
            'status' => 'paid', 'source' => 'guest',
        ], $overrides));
        $bookingDate = $overrides['date'] ?? '2030-05-15';
        $cursor = \Carbon\CarbonImmutable::parse($bookingDate.' '.($overrides['start_time'] ?? '09:00'));
        $end = \Carbon\CarbonImmutable::parse($bookingDate.' '.($overrides['end_time'] ?? '11:00'));
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
