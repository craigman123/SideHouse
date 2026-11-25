<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LumiBookingDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    private Court $court;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8,
            'close_hour' => 22,
            'step_minutes' => 60,
            'min_duration_hours' => 1,
            'max_duration_hours' => 8,
            'closed_weekdays' => [],
        ]);
        $this->court = Court::create([
            'name' => 'Deep Link Court',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 500,
            'status' => 'active',
        ]);
        $this->customer = User::create([
            'username' => 'lumi-deep-link-user',
            'name' => 'Deep Link User',
            'email' => 'lumi-deep-link-user@example.test',
            'password' => 'password',
            'role' => 'user',
            'access_version' => 1,
        ]);
        $this->actingAs($this->customer)->withSession([
            'access_version_user_id' => $this->customer->user_id,
            'access_version' => 1,
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $this->customer->user_id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_valid_booking_deep_link_is_passed_to_the_picker(): void
    {
        $this->get('/book?'.http_build_query([
            'date' => '2026-11-28',
            'time' => '14:00',
            'court' => $this->court->id,
        ]))->assertOk()
            ->assertSee('data-deep-link-date="2026-11-28"', false)
            ->assertSee('data-deep-link-time="14:00"', false)
            ->assertSee('data-deep-link-court="'.$this->court->id.'"', false);
    }

    public function test_invalid_past_and_unknown_deep_link_values_are_safely_ignored(): void
    {
        $this->get('/book?date=2026-02-31&time=14:00&court='.$this->court->id)->assertOk()
            ->assertSee('data-deep-link-date=""', false)
            ->assertSee('data-deep-link-time=""', false);

        $this->get('/book?date=2026-11-19&time=14:00&court='.$this->court->id)->assertOk()
            ->assertSee('data-deep-link-date=""', false)
            ->assertSee('data-deep-link-time=""', false);

        $this->get('/book?date=2026-11-28&time=14:00&court=999999')->assertOk()
            ->assertSee('data-deep-link-date=""', false)
            ->assertSee('data-deep-link-time=""', false)
            ->assertSee('data-deep-link-court=""', false);

        $this->get('/book?date=2026-11-20&time=09:00&court='.$this->court->id)->assertOk()
            ->assertSee('data-deep-link-date="2026-11-20"', false)
            ->assertSee('data-deep-link-time=""', false);
    }
}
