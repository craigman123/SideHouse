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
use App\Models\BusinessSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class GuestCreditStage1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8, 'close_hour' => 7, 'step_minutes' => 30,
            'min_duration_hours' => 1, 'max_duration_hours' => 10, 'closed_weekdays' => [],
        ]);
        BusinessSetting::current()->update(['closed_weekdays' => []]);

        $instant = Carbon::parse('2026-11-20 10:00:00', 'Asia/Manila');
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

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
            'slots' => [
                now()->addDay()->toDateString().' 06:00',
                now()->addDay()->toDateString().' 06:30',
            ],
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
            'slots' => [
                now()->addDay()->toDateString().' 06:00',
                now()->addDay()->toDateString().' 06:30',
            ],
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
        $this->assertSame(50, (int) CreditWallet::where('user_id', $user->user_id)->value('given_balance'));
        $this->assertSame(1, CreditTransaction::where('reason', 'booking_payment')->count());
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

    public function test_cancel_all_cannot_refund_credit_on_a_paid_booking(): void
    {
        $user = $this->createUser();
        CreditWallet::create([
            'user_id' => $user->user_id,
            'given_balance' => 100,
            'reserved_balance' => 0,
        ]);
        $court = Court::create([
            'name' => 'Cancel Court', 'width' => 10, 'length' => 20,
            'hourly_rate' => 100, 'status' => 'active',
        ]);
        $day = now()->addDay()->toDateString();

        $res = $this->withSession([
            'guest_credit_verified' => [
                'user_id' => $user->user_id,
                'email' => $user->email,
                'verified_at' => now()->timestamp,
                'matched' => true,
            ],
        ])->postJson('/guest-book', [
            'court_id' => $court->id,
            'date' => $day,
            'slots' => [$day.' 06:00', $day.' 06:30'],
            'guest_name' => 'Cancel Guest',
            'guest_contact' => '09171234567',
            'use_credit' => true,
            'idempotency_token' => str_repeat('c', 32),
        ])->assertOk();

        $cancel = $this->postJson(
            '/guest/bookings/'.$res->json('booking_id').'/cancel-all?token='.$res->json('poll_token')
        );
        dump($cancel->status(), $cancel->getContent());
        $cancel->assertOk();

        dump(\App\Models\CreditPaymentHold::first()?->status, \App\Models\CreditTransaction::pluck('reason')->all());

        $this->assertSame('paid', Booking::first()->status);
        $this->assertSame(0, (int) CreditWallet::where('user_id', $user->user_id)->value('given_balance'));
        $this->assertSame(0, CreditTransaction::where('reason', 'booking_cancellation')->count());
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

    public function test_paymongo_failure_does_not_refund_credit_on_a_pending_booking(): void
    {
        config(['services.paymongo.secret' => 'sk_test_dummy']);
        \Illuminate\Support\Facades\Http::fake(['api.paymongo.com/*' => \Illuminate\Support\Facades\Http::response([], 500)]);

        $user = $this->createUser();
        CreditWallet::create(['user_id' => $user->user_id, 'given_balance' => 30, 'reserved_balance' => 0]);
        $court = Court::create([
            'name' => 'Qr Court', 'width' => 10, 'length' => 20,
            'hourly_rate' => 100, 'status' => 'active',
        ]);
        $day = now()->addDay()->toDateString();

        $res = $this->withSession([
            'guest_credit_verified' => [
                'user_id' => $user->user_id, 'email' => $user->email,
                'verified_at' => now()->timestamp, 'matched' => true,
            ],
        ])->postJson('/guest-book', [
            'court_id' => $court->id, 'date' => $day,
            'slots' => [$day.' 06:00', $day.' 06:30'],
            'guest_name' => 'Qr Guest', 'guest_contact' => '09171234567',
            'use_credit' => true, 'idempotency_token' => str_repeat('d', 32),
        ])->assertOk();

        $this->assertSame('pending', $res->json('status'));

        $this->postJson('/guest-book/payment/qrph', [
            'booking_id' => $res->json('booking_id'),
            'token' => $res->json('poll_token'),
        ])->assertStatus(502);

        $this->assertSame('pending', Booking::first()->status);
        $this->assertSame(0, (int) CreditWallet::where('user_id', $user->user_id)->value('given_balance'));
        $this->assertSame(0, CreditTransaction::where('reason', 'booking_cancellation')->count());
    }
}
