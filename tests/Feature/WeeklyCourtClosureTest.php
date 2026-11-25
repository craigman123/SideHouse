<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\User;
use App\Models\WeeklyCourtClosure;
use App\Services\CourtBlockClosureAvailability;
use App\Services\WeeklyCourtClosureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklyCourtClosureTest extends TestCase
{
    use RefreshDatabase;

    private Court $court;
    private Court $otherCourt;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = BusinessSetting::query()->firstOrCreate([], ['open_hour' => 8, 'close_hour' => 22, 'step_minutes' => 30,
            'min_duration_hours' => 1, 'max_duration_hours' => 8, 'closed_weekdays' => []]);
        $settings->update(['open_hour' => 8, 'close_hour' => 22, 'step_minutes' => 30, 'closed_weekdays' => []]);
        $this->court = Court::create(['name' => 'Weekly Court A', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active']);
        $this->otherCourt = Court::create(['name' => 'Weekly Court B', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active']);
    }

    private function account(string $role, ?array $permissions = null): User
    {
        $id = uniqid();
        return User::create(['username' => "weekly-{$role}-{$id}", 'name' => ucfirst($role), 'email' => "weekly-{$role}-{$id}@example.test", 'password' => 'password', 'role' => $role, 'permissions' => $permissions]);
    }

    private function signIn(User $user): self
    {
        return $this->actingAs($user)->withSession(['access_version_user_id' => $user->user_id, 'access_version' => 1, 'mfa_passed_at' => now()->timestamp, 'mfa_passed_user_id' => $user->user_id]);
    }

    private function weekdayDate(int $weekday, int $weeksAhead = 1): string
    {
        $date = CarbonImmutable::now('Asia/Manila')->startOfDay()->addWeeks($weeksAhead);
        while ($date->dayOfWeek !== $weekday) $date = $date->addDay();
        return $date->toDateString();
    }

    private function payload(array $weekdays, ?int $courtId = null): array
    {
        return ['weekdays' => $weekdays, 'court_id' => $courtId, 'start_time' => '09:00', 'end_time' => '10:00', 'reason' => 'Weekly practice'];
    }

    public function test_admin_and_owner_can_create_edit_and_remove_and_staff_and_users_are_denied(): void
    {
        $owner = $this->account('owner');
        $created = $this->signIn($owner)->postJson(role_route('admin.specific-closures.store'), $this->payload([1, 3], $this->court->id))
            ->assertCreated()->assertJsonPath('created', 2);
        $this->assertSame(2, WeeklyCourtClosure::where('court_id', $this->court->id)->count());
        $rule = WeeklyCourtClosure::where('weekday', 1)->firstOrFail();
        $this->patchJson(role_route('admin.specific-closures.update', ['closure' => $rule->id]), ['weekday' => 2, 'court_id' => $this->court->id, 'start_time' => '10:00', 'end_time' => '11:00', 'reason' => 'Edited'])->assertOk();
        $this->deleteJson(role_route('admin.specific-closures.destroy', ['closure' => $rule->id]))->assertOk();
        $admin = $this->account('admin');
        $this->signIn($admin)->postJson(role_route('admin.specific-closures.store'), $this->payload([4]))->assertCreated();
        foreach (['staff', 'user'] as $role) {
            $account = $this->account($role);
            if (config('auth.multi_guard')) {
                $prefix = $role === 'user' ? 'customer' : $role;
                $this->signIn($account)->getJson('/'.$prefix.'/specific-closures')->assertNotFound();
                $this->postJson('/'.$prefix.'/specific-closures', $this->payload([1]))->assertNotFound();
            } else {
                $this->signIn($account)->getJson('/admin/specific-closures')->assertForbidden();
                $this->postJson('/admin/specific-closures', $this->payload([1]))->assertForbidden();
            }
        }
        $staff = $this->account('staff', ['bookings.view']);
        $staffPage = $this->signIn($staff)->get('/staff/bookings')->assertOk();
        $staffPage->assertDontSee('Weekly closures')->assertDontSee('data-specific-closures');
        $this->assertDatabaseHas('activity_logs', ['action' => 'weekly_court_closure.created', 'user_id' => $owner->user_id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'weekly_court_closure.updated', 'user_id' => $owner->user_id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'weekly_court_closure.removed', 'user_id' => $owner->user_id]);
        if (! config('auth.multi_guard')) {
            $this->assertDatabaseHas('activity_logs', ['action' => 'calendar.weekly_closure.denied']);
        }
    }

    public function test_validates_days_hours_and_rejects_overlapping_rules(): void
    {
        $owner = $this->account('owner');
        $this->signIn($owner)->postJson(role_route('admin.specific-closures.store'), $this->payload([]))->assertUnprocessable();
        $this->postJson(role_route('admin.specific-closures.store'), $this->payload([7]))->assertUnprocessable();
        $this->postJson(role_route('admin.specific-closures.store'), array_merge($this->payload([1], $this->court->id), ['start_time' => '07:00']))->assertUnprocessable();
        $this->postJson(role_route('admin.specific-closures.store'), array_merge($this->payload([1]), ['end_time' => '23:00']))->assertUnprocessable();
        $this->postJson(role_route('admin.specific-closures.store'), array_merge($this->payload([1]), ['start_time' => '10:00', 'end_time' => '09:00']))->assertUnprocessable();
        $this->postJson(role_route('admin.specific-closures.store'), $this->payload([1], $this->court->id))->assertCreated();
        $this->postJson(role_route('admin.specific-closures.store'), $this->payload([1], $this->court->id))->assertUnprocessable()->assertJsonValidationErrors('weekdays');
        $this->postJson(role_route('admin.specific-closures.store'), $this->payload([1], $this->otherCourt->id))->assertCreated();
    }

    public function test_availability_checks_only_matching_weekday_court_and_time_and_remove_reopens_slots(): void
    {
        $monday = $this->weekdayDate(1);
        $tuesday = $this->weekdayDate(2);
        $owner = $this->account('owner');
        $this->signIn($owner)->postJson(role_route('admin.specific-closures.store'), $this->payload([1], $this->court->id))->assertCreated();
        $availability = app(CourtBlockClosureAvailability::class);
        $this->assertCount(1, $availability->closedRangesForDate($this->court->id, $monday));
        $this->assertCount(0, $availability->closedRangesForDate($this->court->id, $tuesday));
        $this->assertCount(0, $availability->closedRangesForDate($this->otherCourt->id, $monday));
        $this->assertNull($availability->firstClosedOverlap($this->court->id, CarbonImmutable::parse($monday.' 10:00', 'Asia/Manila'), CarbonImmutable::parse($monday.' 11:00', 'Asia/Manila')));
        $this->assertNotNull($availability->firstClosedOverlap($this->court->id, CarbonImmutable::parse($monday.' 09:30', 'Asia/Manila'), CarbonImmutable::parse($monday.' 10:30', 'Asia/Manila')));

        $guest = $this->getJson('/guest-book/availability?court_id='.$this->court->id.'&date='.$monday)->assertOk();
        $guest->assertJsonPath('closed_blocks.0.start', '09:00')->assertJsonPath('closed_blocks.0.reason', 'Weekly closure: Weekly practice');
        $this->getJson('/guest-book/availability?court_id='.$this->court->id.'&date='.$tuesday)->assertOk()->assertJsonCount(0, 'closed_blocks');
        $user = $this->account('user');
        $this->signIn($user)->getJson('/book/availability?court_id='.$this->court->id.'&date='.$monday)->assertOk()->assertJsonCount(1, 'closed_blocks');

        $staff = $this->account('staff', ['bookings.view']);
        $this->signIn($staff)->get('/staff/bookings')->assertOk()->assertSee('Weekly closure');
        $rule = WeeklyCourtClosure::firstOrFail();
        $this->signIn($owner)->deleteJson(role_route('admin.specific-closures.destroy', ['closure' => $rule->id]))->assertOk();
        $this->assertCount(0, $availability->closedRangesForDate($this->court->id, $monday));
    }

    public function test_existing_bookings_are_warned_about_and_left_unchanged(): void
    {
        $weekday = 1;
        $date = $this->weekdayDate($weekday);
        $booking = Booking::create(['customer_name' => 'Warning Player', 'contact_number' => '09123456789', 'court_id' => $this->court->id, 'date' => $date, 'start_time' => '09:30', 'end_time' => '10:30', 'amount' => 500, 'status' => 'confirmed']);
        BookingSlot::create(['booking_id' => $booking->id, 'court_id' => $this->court->id, 'date' => $date, 'start_time' => '09:30', 'end_time' => '10:30', 'price' => 500]);
        $owner = $this->account('owner');
        $response = $this->signIn($owner)->postJson(role_route('admin.specific-closures.store'), $this->payload([$weekday], $this->court->id))->assertCreated();
        $response->assertJsonPath('warnings.0.customer', 'Warning Player')->assertJsonPath('warnings.0.status', 'Confirmed');
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'start_time' => '09:30']);
        $this->getJson(role_route('admin.specific-closures.index'))->assertOk()->assertJsonPath('closures.0.conflict_count', 1);
    }

    public function test_closed_all_day_weekdays_cannot_be_selected_and_overnight_hours_are_supported(): void
    {
        BusinessSetting::current()->update(['open_hour' => 18, 'close_hour' => 2, 'closed_weekdays' => [0]]);
        $owner = $this->account('owner');
        $this->signIn($owner)->postJson(role_route('admin.specific-closures.store'), $this->payload([0]))->assertUnprocessable()->assertJsonValidationErrors('weekdays');
        $this->postJson(role_route('admin.specific-closures.store'), array_merge($this->payload([1], $this->court->id), ['start_time' => '01:00', 'end_time' => '02:00']))->assertCreated();
        $this->postJson(role_route('admin.specific-closures.store'), array_merge($this->payload([2], $this->court->id), ['start_time' => '23:00', 'end_time' => '01:00']))->assertCreated();
    }

    public function test_weekday_hours_use_sunday_zero_and_intersect_different_days_while_ignoring_closed_days(): void
    {
        BusinessSetting::current()->update(['open_hour' => 6, 'close_hour' => 22, 'closed_weekdays' => [2]]);
        $owner = $this->account('owner');
        $response = $this->signIn($owner)->getJson(role_route('admin.specific-closures.hours').'?weekdays[]=0&weekdays[]=1&weekdays[]=2')->assertOk();
        $response->assertJsonPath('weekdays.0.weekday', 0)
            ->assertJsonPath('weekdays.0.open_minutes', 360)
            ->assertJsonPath('weekdays.1.weekday', 1)
            ->assertJsonPath('weekdays.2.closed_all_day', true);

        $service = app(WeeklyCourtClosureService::class);
        $window = $service->sharedOperatingWindow([0, 1, 2], [
            ['weekday' => 0, 'open_minutes' => 360, 'close_offset' => 1320, 'closed_all_day' => false],
            ['weekday' => 1, 'open_minutes' => 480, 'close_offset' => 1380, 'closed_all_day' => false],
            ['weekday' => 2, 'open_minutes' => 0, 'close_offset' => 0, 'closed_all_day' => true],
        ]);
        $this->assertSame(480, $window['open']);
        $this->assertSame(1320, $window['close']);
        $this->assertSame([2], array_column($window['closed'], 'weekday'));
    }
}
