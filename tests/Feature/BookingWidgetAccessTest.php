<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Court;
use App\Models\PaymentReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingWidgetAccessTest extends TestCase
{
    use RefreshDatabase;

    private int $userSequence = 0;

    private function makeUser(string $role): User
    {
        $this->userSequence++;

        return User::create([
            'username' => "booking-widget-{$role}-{$this->userSequence}",
            'name' => ucfirst($role) . ' Booking User',
            'email' => "booking-widget-{$role}-{$this->userSequence}@example.test",
            'password' => 'password',
            'role' => $role,
        ]);
    }

    private function signIn(User $user): self
    {
        return $this->actingAs($user)->withSession([
            'access_version_user_id' => $user->user_id,
            'access_version' => (int) ($user->fresh()->access_version ?? 1),
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $user->user_id,
        ]);
    }

    private function makeBooking(User $owner, Court $court): Booking
    {
        $paymentReference = PaymentReference::create([
            'payment_method' => 'qrph',
            'amount' => 500,
        ]);

        return Booking::create([
            'user_id' => $owner->user_id,
            'customer_name' => $owner->name,
            'contact_number' => '09171234567',
            'email' => $owner->email,
            'court_id' => $court->id,
            'payment_reference_id' => $paymentReference->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'amount' => 500,
            'payment_method' => 'qrph',
            'poll_token' => hash('sha256', (string) $owner->user_id),
            'status' => 'pending',
        ]);
    }

    public function test_admin_owner_and_staff_can_load_booking_flow_but_not_customer_history(): void
    {
        foreach (['admin', 'owner', 'staff'] as $role) {
            $user = $this->makeUser($role);

            $bookingPage = $this->signIn($user)->get('/book');
            if (config('auth.multi_guard')) {
                $bookingPage->assertRedirect(route('login'));
            } else {
                $bookingPage->assertOk();
            }
            $history = $this->get('/my-bookings');
            if (config('auth.multi_guard')) {
                $history->assertRedirect(route('login'));
            } else {
                $history->assertForbidden();
            }
        }
    }

    public function test_customer_can_wait_for_own_booking_but_cannot_open_another_users_booking(): void
    {
        $customerOwner = $this->makeUser('user');
        $customer = $this->makeUser('user');
        $court = Court::create([
            'name' => 'Widget Test Court',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 500,
            'status' => 'active',
        ]);
        $ownBooking = $this->makeBooking($customerOwner, $court);
        $otherBooking = $this->makeBooking($customer, $court);

        $this->signIn($customerOwner)
            ->get("/book/bookings/{$ownBooking->id}/waiting")
            ->assertOk()
            ->assertSee('Almost there');

        $this->getJson("/book/bookings/{$ownBooking->id}/status")
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->get("/book/bookings/{$otherBooking->id}/waiting")->assertForbidden();
        $this->getJson("/book/bookings/{$otherBooking->id}/status")->assertForbidden();
    }
}
