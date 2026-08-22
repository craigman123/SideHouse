<?php

namespace Tests\Feature;

use App\Console\Commands\ExpireUnconfirmedGcashBookings;
use App\Console\Commands\ExpireUnconfirmedLandbankBookings;
use App\Models\Booking;
use App\Models\PaymentReference;
use App\Models\UnmatchedPayment;
use App\Models\User;
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

    public function test_gcash_webhook_confirms_only_the_matching_pending_payment(): void
    {
        config()->set('services.gcash_sms.secret', 'test-webhook-secret');

        $payment = PaymentReference::create([
            'payment_reference' => '294087757',
            'payment_method' => 'gcash',
            'amount' => 500,
        ]);
        $booking = $this->createBooking([
            'payment_reference_id' => $payment->id,
            'payment_method' => 'gcash',
            'status' => 'pending',
        ]);

        $this->postJson('/webhooks/gcash-sms', [
            'message' => 'You have received PHP 500.00 GCASH. Ref. No. 294087757.',
        ], [
            'X-Webhook-Token' => 'test-webhook-secret',
        ])->assertOk()->assertJsonPath('status', 'confirmed');

        $this->assertNotNull($payment->fresh()->confirmed_at);
        $this->assertSame('paid', $booking->fresh()->status);
        $this->assertSame('294087757', $booking->fresh()->gcash_reference_number);
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

    public function test_signed_in_user_correction_claims_a_matching_parked_payment(): void
    {
        $user = User::factory()->create();
        $payment = PaymentReference::create([
            'payment_reference' => 'wrong-reference',
            'payment_method' => 'gcash',
            'amount' => 500,
        ]);
        $booking = $this->createBooking([
            'user_id' => $user->user_id,
            'payment_reference_id' => $payment->id,
            'payment_method' => 'gcash',
            'status' => 'pending',
        ]);
        $unmatched = UnmatchedPayment::create([
            'payment_method' => 'gcash',
            'amount' => 500,
            'reference_number' => '294-087-757',
            'raw_message' => 'received PHP 500.00 GCASH Ref. No. 294087757',
        ]);

        $this->actingAs($user)
            ->putJson("/book/bookings/{$booking->id}/reference", [
                'payment_reference' => 'Ref# 294087757',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->assertNotNull($payment->fresh()->confirmed_at);
        $this->assertSame('paid', $booking->fresh()->status);
        $this->assertSame($payment->id, $unmatched->fresh()->matched_payment_reference_id);
    }

    public function test_signed_in_user_can_cancel_only_a_pending_booking(): void
    {
        $user = User::factory()->create();
        $pending = $this->createBooking([
            'user_id' => $user->user_id,
            'payment_method' => 'gcash',
            'status' => 'pending',
        ]);
        $paid = $this->createBooking([
            'user_id' => $user->user_id,
            'payment_method' => 'gcash',
            'status' => 'paid',
            'confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/my-bookings/{$pending->id}/cancel")
            ->assertOk();

        $this->assertSame('cancelled', $pending->fresh()->status);

        $this->actingAs($user)
            ->postJson("/my-bookings/{$paid->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This booking is already paid and confirmed. Cancelling a paid booking requires a refund — please contact us directly.');

        $this->assertSame('paid', $paid->fresh()->status);
    }

    public function test_expiry_commands_never_cancel_an_already_paid_booking(): void
    {
        foreach (['gcash', 'landbank'] as $method) {
            $booking = $this->createBooking([
                'payment_method' => $method,
                'status' => 'paid',
                'confirmed_at' => now(),
                'expires_at' => now()->subMinute(),
            ]);

            $this->artisan($method === 'gcash'
                ? ExpireUnconfirmedGcashBookings::class
                : ExpireUnconfirmedLandbankBookings::class)
                ->assertSuccessful();

            $this->assertSame('paid', $booking->fresh()->status);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'customer_name' => 'Test Customer',
            'contact_number' => '09171234567',
            'date' => today()->addDay(),
            'start_time' => '16:00:00',
            'end_time' => '17:00:00',
            'amount' => 500,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ], $overrides));
    }
}
