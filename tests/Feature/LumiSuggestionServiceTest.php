<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\LumiSuggestionDismissal;
use App\Models\User;
use App\Services\LumiSuggestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LumiSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        config([
            'lumi.minimum_bookings' => 2,
            'lumi.weekend_heavy_ratio' => 0.6,
            'lumi.lookback_days' => 90,
            'lumi.max_suggestions' => 3,
            'lumi.dismissal_cooldown_days' => 7,
            'lumi.availability_search_days' => 30,
            'lumi.cache_seconds' => 0,
            'lumi.timezone' => 'Asia/Manila',
        ]);
        BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8,
            'close_hour' => 22,
            'step_minutes' => 60,
            'min_duration_hours' => 1,
            'max_duration_hours' => 8,
            'closed_weekdays' => [],
        ])->update([
            'open_hour' => 8,
            'close_hour' => 22,
            'step_minutes' => 60,
            'min_duration_hours' => 1,
            'max_duration_hours' => 8,
            'closed_weekdays' => [],
        ]);
        $this->court = Court::create([
            'name' => 'Lumi Court',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 500,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_new_customer_gets_starter_suggestion_without_behavior_rules(): void
    {
        $suggestions = app(LumiSuggestionService::class)->forCustomer($this->customer());

        $this->assertSame(['first_booking'], array_column($suggestions, 'type'));
        $this->assertSame('2026-11-20', $suggestions[0]['details']['date']);
        $this->assertSame('11:00', $suggestions[0]['details']['time']);
    }

    public function test_same_day_double_booking_suggests_another_free_time_on_that_date(): void
    {
        $customer = $this->customer();
        $this->booking($customer, '2026-11-22', '09:00', '10:00');
        $this->booking($customer, '2026-11-22', '11:00', '12:00');

        $suggestions = app(LumiSuggestionService::class)->forCustomer($customer);
        $sameDay = collect($suggestions)->firstWhere('type', 'free_slot_same_day');

        $this->assertNotNull($sameDay);
        $this->assertSame('2026-11-22', $sameDay['details']['date']);
        $this->assertSame('08:00', $sameDay['details']['time']);
    }

    public function test_weekend_heavy_customer_gets_a_weekday_vacancy(): void
    {
        $customer = $this->customer();
        $this->booking($customer, '2026-11-14', '09:00', '10:00');
        $this->booking($customer, '2026-11-15', '09:00', '10:00');

        $suggestion = collect(app(LumiSuggestionService::class)->forCustomer($customer))
            ->firstWhere('type', 'weekday_vacancy');

        $this->assertNotNull($suggestion);
        $this->assertSame('2026-11-20', $suggestion['details']['date']);
        $this->assertSame('11:00', $suggestion['details']['time']);
    }

    public function test_same_free_slot_is_not_repeated_as_a_weekday_suggestion(): void
    {
        $customer = $this->customer();
        $this->booking($customer, '2026-11-14', '09:00', '10:00');
        $this->booking($customer, '2026-11-15', '09:00', '10:00');
        $this->booking($customer, '2026-11-23', '14:00', '15:00');
        $this->booking($customer, '2026-11-23', '16:00', '17:00');

        $suggestions = app(LumiSuggestionService::class)->forCustomer($customer);
        $sameSlotSuggestions = collect($suggestions)->filter(fn (array $suggestion) =>
            $suggestion['details']['date'] === '2026-11-23'
            && $suggestion['details']['time'] === '08:00'
            && $suggestion['details']['court_id'] === $this->court->id
        );

        $this->assertCount(1, $sameSlotSuggestions);
        $this->assertSame('free_slot_same_day', $sameSlotSuggestions->first()['type']);
    }

    public function test_open_play_suggestion_uses_database_price_and_remaining_spots(): void
    {
        $customer = $this->customer();
        $this->booking($customer, '2026-11-16', '09:00', '10:00');
        $this->booking($customer, '2026-11-17', '09:00', '10:00');
        $this->booking($this->customer('Lumi Participant'), '2026-11-28', '14:00', '15:00');
        CourtBlock::create([
            'court_id' => $this->court->id,
            'type' => 'open_play',
            'title' => 'Saturday Open Play',
            'block_date' => '2026-11-28',
            'start_time' => '14:00',
            'end_time' => '15:00',
            'max_players' => 8,
            'price_per_player' => 350,
            'created_by' => $customer->user_id,
        ]);

        $suggestion = collect(app(LumiSuggestionService::class)->forCustomer($customer))
            ->firstWhere('type', 'open_play');

        $this->assertNotNull($suggestion);
        $this->assertSame(350.0, $suggestion['details']['price']);
        $this->assertSame(7, $suggestion['details']['spots_left']);
        $this->assertSame('/book?date=2026-11-28&time=14%3A00&court='.$this->court->id, $suggestion['cta_url']);
    }

    public function test_taken_closed_and_past_times_are_skipped(): void
    {
        $customer = $this->customer();
        $otherPlayer = $this->customer('Lumi Other Player');
        $this->booking($otherPlayer, '2026-11-20', '11:00', '12:00');
        CourtBlock::create([
            'court_id' => $this->court->id,
            'type' => 'closed',
            'title' => 'Maintenance',
            'block_date' => '2026-11-20',
            'start_time' => '12:00',
            'end_time' => '13:00',
            'created_by' => $otherPlayer->user_id,
        ]);

        $suggestion = app(LumiSuggestionService::class)->forCustomer($customer)[0];

        $this->assertSame('2026-11-20', $suggestion['details']['date']);
        $this->assertSame('13:00', $suggestion['details']['time']);
    }

    public function test_suggestion_limit_and_dismissal_cooldown_are_applied(): void
    {
        config(['lumi.max_suggestions' => 2]);
        $customer = $this->customer();
        $this->booking($customer, '2026-11-14', '09:00', '10:00');
        $this->booking($customer, '2026-11-15', '09:00', '10:00');
        $this->booking($customer, '2026-11-22', '09:00', '10:00');
        $this->booking($customer, '2026-11-22', '11:00', '12:00');
        CourtBlock::create([
            'court_id' => $this->court->id,
            'type' => 'open_play',
            'title' => 'Saturday Open Play',
            'block_date' => '2026-11-28',
            'start_time' => '14:00',
            'end_time' => '15:00',
            'max_players' => 8,
            'price_per_player' => 350,
            'created_by' => $customer->user_id,
        ]);

        $service = app(LumiSuggestionService::class);
        $suggestions = $service->forCustomer($customer);
        $this->assertCount(2, $suggestions);
        $this->assertSame(['free_slot_same_day', 'open_play'], array_column($suggestions, 'type'));
        $dismissedKey = $suggestions[0]['key'];
        LumiSuggestionDismissal::create([
            'user_id' => $customer->user_id,
            'suggestion_key' => $dismissedKey,
            'dismissed_until' => now()->addDays(7),
        ]);

        $remaining = $service->forCustomer($customer);
        $this->assertCount(2, $remaining);
        $this->assertNotContains($dismissedKey, array_column($remaining, 'key'));
    }

    private function customer(string $name = 'Lumi Test Customer'): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => 'lumi-service-'.$sequence,
            'name' => $name,
            'email' => 'lumi-service-'.$sequence.'@example.test',
            'password' => 'password',
            'role' => 'user',
            'access_version' => 1,
        ]);
    }

    private function booking(User $customer, string $date, string $start, string $end): Booking
    {
        return Booking::create([
            'user_id' => $customer->user_id,
            'customer_name' => $customer->name,
            'contact_number' => '09123456789',
            'court_id' => $this->court->id,
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'amount' => 500,
            'status' => 'paid',
        ]);
    }
}
