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

class SpecificDateClosureTest extends TestCase
{
    use RefreshDatabase;

    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8, 'close_hour' => 22, 'step_minutes' => 30,
            'min_duration_hours' => 1, 'max_duration_hours' => 8, 'closed_weekdays' => [],
        ]);
        $settings->update(['open_hour' => 8, 'close_hour' => 22, 'step_minutes' => 30, 'closed_weekdays' => []]);
        $this->court = Court::create(['name' => 'Closure Court', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active']);
    }

    private function account(string $role, ?array $permissions = null): User
    {
        return User::create(['username' => "closure-{$role}-".uniqid(), 'name' => ucfirst($role), 'email' => "closure-{$role}-".uniqid().'@example.test', 'password' => 'password', 'role' => $role, 'permissions' => $permissions]);
    }

    private function signIn(User $user): self
    {
        return $this->actingAs($user)->withSession(['access_version_user_id' => $user->user_id, 'access_version' => 1, 'mfa_passed_at' => now()->timestamp, 'mfa_passed_user_id' => $user->user_id]);
    }

    private function payload(string $date): array
    {
        return ['start_date' => $date, 'end_date' => $date, 'start_time' => '09:00', 'end_time' => '10:00', 'court_id' => $this->court->id, 'reason' => 'Private event'];
    }

    public function test_admin_and_owner_can_manage_closures_and_staff_and_customer_cannot(): void
    {
        $date = now('Asia/Manila')->addDays(5)->toDateString();
        $owner = $this->account('owner');
        $created = $this->signIn($owner)->postJson('/admin/specific-closures', $this->payload($date))->assertCreated();
        $id = $created->json('closures.0.id');
        $this->assertDatabaseHas('court_blocks', ['id' => $id, 'type' => 'closed', 'notes' => 'Private event']);
        $this->patchJson("/admin/specific-closures/{$id}", ['date' => $date, 'court_id' => $this->court->id, 'start_time' => '10:00', 'end_time' => '11:00', 'reason' => 'Updated'])->assertOk();
        $this->deleteJson("/admin/specific-closures/{$id}")->assertOk();

        $admin = $this->account('admin');
        $this->signIn($admin)->postJson('/admin/specific-closures', $this->payload($date))->assertCreated();
        foreach (['staff', 'customer'] as $role) {
            $user = $this->account($role);
            $this->signIn($user)->getJson('/admin/specific-closures')->assertForbidden();
            $this->postJson('/admin/specific-closures', $this->payload($date))->assertForbidden();
        }
        $this->assertDatabaseHas('activity_logs', ['action' => 'court_block.specific_closure_created', 'user_id' => $owner->user_id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'court_block.specific_closure_updated', 'user_id' => $owner->user_id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'court_block.specific_closure_removed', 'user_id' => $owner->user_id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'calendar.specific_closure.denied']);
        $staff = $this->account('staff', ['bookings.view']);
        $staffPage = $this->signIn($staff)->get('/staff/bookings')->assertOk();
        $staffPage->assertDontSee('Specific date and time closure');
    }

    public function test_rejects_out_of_hours_past_invalid_ranges_and_conflicts_without_changing_bookings(): void
    {
        $owner = $this->account('owner');
        $date = now('Asia/Manila')->addDays(5)->toDateString();
        $this->signIn($owner)->postJson('/admin/specific-closures', array_merge($this->payload($date), ['start_time' => '07:00']))->assertUnprocessable();
        $this->postJson('/admin/specific-closures', array_merge($this->payload($date), ['end_time' => '23:00']))->assertUnprocessable();
        $this->postJson('/admin/specific-closures', array_merge($this->payload($date), ['start_time' => '10:00', 'end_time' => '09:00']))->assertUnprocessable();
        $this->postJson('/admin/specific-closures', array_merge($this->payload(now('Asia/Manila')->subDay()->toDateString())))->assertUnprocessable();
        $this->postJson('/admin/specific-closures', array_merge($this->payload($date), ['end_date' => now('Asia/Manila')->addDays(40)->toDateString()]))->assertUnprocessable();

        $booking = Booking::create(['customer_name' => 'Conflict Player', 'contact_number' => '09123456789', 'court_id' => $this->court->id, 'date' => $date, 'start_time' => '09:30', 'end_time' => '10:30', 'amount' => 500, 'status' => 'confirmed']);
        BookingSlot::create(['booking_id' => $booking->id, 'court_id' => $this->court->id, 'date' => $date, 'start_time' => '09:30', 'end_time' => '10:30', 'price' => 500]);
        $response = $this->postJson('/admin/specific-closures', $this->payload($date))->assertStatus(409)->assertJsonPath('conflicts.0.customer', 'Conflict Player');
        $this->assertSame(0, CourtBlock::where('type', 'closed')->count());
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_guest_and_user_availability_include_timed_closures(): void
    {
        $date = now('Asia/Manila')->addDays(5)->toDateString();
        $owner = $this->account('owner');
        $this->signIn($owner)->postJson('/admin/specific-closures', $this->payload($date))->assertCreated();
        $guest = $this->getJson('/guest-book/availability?court_id='.$this->court->id.'&date='.$date)->assertOk();
        $guest->assertJsonPath('closed_blocks.0.start', '09:00')->assertJsonPath('closed_blocks.0.end', '10:00');
        $this->getJson('/guest-book/equipment-availability?court_id='.$this->court->id.'&slots[]='.urlencode($date.' 09:00'))->assertUnprocessable();
        $this->postJson('/admin/bookings', ['customer_name' => 'Walk-in', 'contact_number' => '09123456789', 'court_id' => $this->court->id, 'date' => $date, 'start_time' => '09:00', 'end_time' => '10:00', 'amount' => 500])->assertUnprocessable();
        $customer = $this->account('user');
        $this->signIn($customer)->getJson('/book/availability?court_id='.$this->court->id.'&date='.$date)->assertOk()->assertJsonPath('closed_blocks.0.start', '09:00');
        $this->getJson('/book/equipment-availability?court_id='.$this->court->id.'&slots[]='.urlencode($date.' 09:00'))->assertUnprocessable();
    }

    public function test_overnight_operating_hours_allow_a_closure_crossing_midnight(): void
    {
        BusinessSetting::current()->update(['open_hour' => 18, 'close_hour' => 2, 'step_minutes' => 30]);
        $date = now('Asia/Manila')->addDays(5)->toDateString();
        $owner = $this->account('owner');
        $this->signIn($owner)->getJson('/admin/specific-closures/operating-hours?start_date='.$date.'&end_date='.$date)->assertOk()
            ->assertJsonPath('overnight', true)
            ->assertJsonPath('days.0.close_offset', 480);
        $payload = ['start_date' => $date, 'end_date' => $date, 'start_time' => '23:00', 'end_time' => '01:00', 'court_id' => $this->court->id, 'reason' => 'Overnight closure'];
        $this->postJson('/admin/specific-closures', $payload)->assertCreated();
    }
}
