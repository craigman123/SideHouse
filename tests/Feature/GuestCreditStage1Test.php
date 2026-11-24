<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CreditPaymentHold;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Court;
use App\Models\PaymentReference;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestCreditStage1Test extends TestCase
{
    use RefreshDatabase;

    public function test_full_credit_checkout_is_paid_and_ledgers_are_written_per_booking(): void
    {
        $user = $this->createUser();
        $wallet = CreditWallet::create([
            'user_id' => $user->user_id,
            'given_balance' => 100,
            'reserved_balance' => 50,
        ]);
        $court = Court::create([
            'name' => 'Credit Court',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 100,
            'status' => 'active',
        ]);

        $this->withSession([
            'guest_credit_verified' => [
                'user_id' => $user->user_id,
                'email' => $user->email,
                'verified_at' => now()->timestamp,
                'matched' => true,
            ],
        ])->postJson('/guest-book', [
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'slots' => [now()->addDay()->toDateString().' 10:00'],
            'payment_method' => 'qrph',
            'guest_name' => 'Credit Guest',
            'guest_contact' => '09171234567',
            'use_credit' => true,
            'idempotency_token' => str_repeat('a', 32),
        ])->assertOk();

        $reference = PaymentReference::first();
        $booking = Booking::first();
        $this->assertNotNull($reference);
        $this->assertNotNull($booking);
        $this->assertSame('paid', $booking->status);
        $this->assertSame(0, (int) $reference->cash_due);
        $this->assertSame(100, (int) $reference->given_used);
        $this->assertGreaterThan(0, CreditTransaction::where('reason', 'booking_payment')->count());
    }

    public function test_partial_credit_uses_given_then_reserved_and_qr_is_remainder(): void
    {
        $user = $this->createUser();
        $wallet = CreditWallet::create([
            'user_id' => $user->user_id,
            'given_balance' => 30,
            'reserved_balance' => 20,
        ]);
        $court = Court::create([
            'name' => 'Partial Court',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 100,
            'status' => 'active',
        ]);

        $split = app(CreditService::class)->quoteAndSpendForGuestCheckout($user, 100);
        $this->assertSame(30, $split['given_used']);
        $this->assertSame(20, $split['reserved_used']);
        $this->assertSame(50, $split['cash_due']);
        $this->assertGreaterThanOrEqual((int) config('credit.qr_minimum_php'), $split['cash_due']);
    }

    public function test_replayed_checkout_does_not_duplicate_booking_or_debit(): void
    {
        $user = $this->createUser();
        CreditWallet::create([
            'user_id' => $user->user_id,
            'given_balance' => 100,
            'reserved_balance' => 0,
        ]);
        $court = Court::create([
            'name' => 'Replay Court',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 50,
            'status' => 'active',
        ]);
        $payload = [
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'slots' => [now()->addDay()->toDateString().' 10:00'],
            'guest_name' => 'Replay Guest',
            'guest_contact' => '09171234567',
            'use_credit' => true,
            'idempotency_token' => str_repeat('b', 32),
        ];

        $session = [
            'guest_credit_verified' => [
                'user_id' => $user->user_id,
                'email' => $user->email,
                'verified_at' => now()->timestamp,
                'matched' => true,
            ],
        ];
        $this->withSession($session)->postJson('/guest-book', $payload)->assertOk();
        $this->withSession($session)->postJson('/guest-book', $payload)->assertOk();
        $this->assertSame(1, Booking::count());
    }

    public function test_qr_release_restores_original_credit_types_once(): void
    {
        $user = $this->createUser();
        $wallet = CreditWallet::create([
            'user_id' => $user->user_id,
            'given_balance' => 70,
            'reserved_balance' => 40,
        ]);
        $reference = PaymentReference::create([
            'payment_method' => 'qrph',
            'amount' => 100,
            'given_used' => 30,
            'reserved_used' => 20,
            'cash_due' => 50,
            'credit_status' => 'held',
            'matched_credit_user_id' => $user->user_id,
        ]);
        $hold = CreditPaymentHold::create([
            'payment_reference_id' => $reference->id,
            'user_id' => $user->user_id,
            'given_held' => 30,
            'reserved_held' => 20,
            'status' => 'held',
            'held_at' => now(),
        ]);

        $service = app(CreditService::class);
        $this->assertTrue($service->releasePaymentHold($reference));
        $this->assertFalse($service->releasePaymentHold($reference));
        $this->assertSame(100, (int) $wallet->fresh()->given_balance);
        $this->assertSame(60, (int) $wallet->fresh()->reserved_balance);
    }

    public function test_credit_balances_never_go_negative_or_accept_floats(): void
    {
        $user = $this->createUser();
        CreditWallet::create([
            'user_id' => $user->user_id,
            'given_balance' => 10,
            'reserved_balance' => 5,
        ]);

        $split = app(CreditService::class)->quoteAndSpendForGuestCheckout($user, 20);
        $this->assertSame(10, $split['given_used']);
        $this->assertSame(5, $split['reserved_used']);
        $this->assertSame(5, $split['cash_due']);
        $this->assertIsInt($split['given_used']);
        $this->assertIsInt($split['reserved_used']);
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'Credit User',
            'username' => 'credit'.uniqid(),
            'email' => 'credit'.uniqid().'@example.com',
            'password' => 'password',
            'role' => 'user',
        ]);
    }
}
