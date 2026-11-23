<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookingsCalendarEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $mfa = true): User
    {
        $admin = User::create([
            'username' => 'calendar-endpoint-admin', 'name' => 'Calendar Admin',
            'email' => 'calendar-endpoint@example.test', 'password' => 'password', 'role' => 'admin',
        ]);
        if ($mfa) {
            $this->actingAs($admin)->withSession([
                'mfa_passed_at' => now()->timestamp,
                'mfa_passed_user_id' => $admin->user_id,
                'access_version_user_id' => $admin->user_id,
                'access_version' => 1,
            ]);
        } else {
            $this->actingAs($admin)->withSession([
                'access_version_user_id' => $admin->user_id,
                'access_version' => 1,
            ]);
        }
        return $admin;
    }

    public function test_endpoint_returns_same_calendar_shape_as_page_and_includes_past_items(): void
    {
        BusinessSetting::query()->firstOrFail();
        $admin = $this->admin();
        $court = Court::create(['name' => 'Court A', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active']);
        $booking = Booking::create([
            'customer_name' => 'Past Customer', 'contact_number' => '09171234567', 'court_id' => $court->id,
            'date' => '2020-05-10', 'start_time' => '09:00', 'end_time' => '10:00', 'status' => 'paid',
            'source' => 'online', 'amount' => 500,
        ]);
        $block = CourtBlock::create([
            'court_id' => $court->id, 'type' => 'open_play', 'title' => 'Open play', 'block_date' => '2020-05-10',
            'start_time' => '11:00', 'end_time' => '12:00', 'created_by' => $admin->user_id,
            'max_players' => 8, 'price_per_player' => 125,
        ]);
        $participant = Booking::create([
            'customer_name' => 'Open Play Participant', 'contact_number' => '09170000000', 'court_id' => $court->id,
            'date' => '2020-05-10', 'start_time' => '11:00', 'end_time' => '12:00', 'status' => 'pending',
            'source' => 'online', 'amount' => 125,
        ]);
        $participant->slots()->create([
            'court_id' => $court->id, 'date' => '2020-05-10', 'start_time' => '11:00',
            'end_time' => '12:00', 'price' => 125,
        ]);
        $this->assertDatabaseHas('booking_slots', ['booking_id' => $participant->id, 'start_time' => '11:00']);

        $response = $this->getJson('/admin/bookings/calendar?from=2020-05-10&to=2020-05-10')->assertOk();
        $response->assertJsonStructure([
            'bookings' => [['id', 'updated_at', 'customer', 'contact_number', 'email', 'notes', 'court_id', 'court', 'date', 'start', 'end', 'status', 'source', 'amount', 'slots', 'equipment']],
            'blocks' => [['id', 'updated_at', 'court_id', 'type', 'title', 'notes', 'block_date', 'start_time', 'end_time', 'max_players', 'price_per_player', 'participant_count']],
            'closures', 'closingCutoffs', 'courts', 'businessSettings', 'server_time',
        ])->assertJsonPath('bookings.0.id', $booking->id)->assertJsonPath('blocks.0.id', $block->id)
            ->assertJsonPath('blocks.0.max_players', 8)->assertJsonPath('blocks.0.price_per_player', '125.00')
            ->assertJsonPath('blocks.0.participant_count', 1);
        $this->assertStringContainsString('+08:00', $response->json('server_time'));
        $this->assertSame(now('Asia/Manila')->toDateString(), $response->json('businessSettings.today'));

        $this->getJson('/guest-book/availability?court_id='.$court->id.'&date=2020-05-10')
            ->assertOk()->assertJsonPath('open_play.0.max_players', 8)
            ->assertJsonPath('open_play.0.price_per_player', 125)->assertJsonPath('open_play.0.joined', 1);
    }

    public function test_endpoint_rejects_a_101_day_range_and_invalid_dates(): void
    {
        $this->admin();
        $this->getJson('/admin/bookings/calendar?from=2026-01-01&to=2026-04-11')->assertUnprocessable();
        $this->getJson('/admin/bookings/calendar?from=not-a-date&to=2026-01-02')->assertUnprocessable();
        $this->getJson('/admin/bookings/calendar?from=2026-01-03&to=2026-01-02')->assertUnprocessable();
    }

    public function test_endpoint_requires_admin_authentication_and_mfa(): void
    {
        $this->getJson('/admin/bookings/calendar?from=2020-01-01&to=2020-01-02')->assertUnauthorized();
        $this->admin(false);
        $this->get('/admin/bookings/calendar?from=2020-01-01&to=2020-01-02')->assertRedirect(route('mfa.setup'));
    }

    public function test_unchanged_calendar_range_returns_not_modified(): void
    {
        $this->admin();
        $url = '/admin/bookings/calendar?from=2020-01-01&to=2020-01-31';
        $etag = $this->getJson($url)->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->withHeaders(['If-None-Match' => $etag])->get($url)->assertStatus(304)
            ->assertHeader('X-Venue-Date', now('Asia/Manila')->toDateString())
            ->assertHeader('X-Venue-Time');
    }
}
