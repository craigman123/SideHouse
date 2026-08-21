<?php

namespace Tests\Feature;

use App\Console\Commands\ExpireUnconfirmedGcashBookings;
use App\Models\Booking;
use App\Models\PaymentReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_gcash_booking_is_cancelled_when_its_payment_method_is_persisted(): void
    {
        $booking = Booking::create([
            'customer_name' => 'Test Guest',
            'contact_number' => '09171234567',
            'date' => today()->addDay(),
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'amount' => 500,
            'payment_method' => 'gcash',
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan(ExpireUnconfirmedGcashBookings::class)
            ->assertSuccessful();

        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_webhooks_require_the_secret_in_a_header(): void
    {
        config()->set('services.gcash_sms.secret', 'test-webhook-secret');

        $this->postJson('/webhooks/gcash-sms?token=test-webhook-secret', [
            'message' => 'received PHP 500.00 GCASH Ref. No. 123456',
        ])->assertForbidden();
    }

    public function test_guest_can_correct_a_pending_payment_reference_from_the_waiting_step(): void
    {
        $payment = PaymentReference::create([
            'payment_reference' => '111111',
            'payment_method' => 'gcash',
            'amount' => 500,
        ]);
        $booking = Booking::create([
            'customer_name' => 'Test Guest',
            'contact_number' => '09171234567',
            'date' => today()->addDay(),
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'amount' => 500,
            'payment_method' => 'gcash',
            'payment_reference_id' => $payment->id,
            'poll_token' => 'test-poll-token',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->putJson("/guest/bookings/{$booking->id}/reference?token=test-poll-token", [
            'payment_reference' => '222222',
        ])->assertOk()->assertJsonPath('status', 'pending');

        $this->assertSame('222222', $payment->fresh()->payment_reference);
        $this->get("/guest/bookings/{$booking->id}/waiting?token=test-poll-token")
            ->assertOk()
            ->assertSee('Correct reference');
    }
}
