<?php

namespace App\Services;

use App\Enums\CreditDirection;
use App\Enums\CreditReason;
use App\Enums\CreditType;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\CreditPaymentHold;
use App\Models\PaymentReference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreditService
{
    public function debitForCheckout(
        User $user,
        int $givenUsed,
        int $reservedUsed,
        PaymentReference $paymentReference,
        ?int $bookingId = null
    ): CreditPaymentHold {
        if ($givenUsed < 0 || $reservedUsed < 0) {
            throw new InvalidArgumentException('Credit usage cannot be negative.');
        }
        if ($givenUsed === 0 && $reservedUsed === 0) {
            return CreditPaymentHold::query()->firstOrCreate(
                ['payment_reference_id' => $paymentReference->id],
                [
                    'user_id' => $user->user_id,
                    'given_held' => 0,
                    'reserved_held' => 0,
                    'status' => 'held',
                    'held_at' => now(),
                ]
            );
        }

        $wallet = CreditWallet::query()
            ->where('user_id', $user->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($givenUsed > (int) $wallet->given_balance || $reservedUsed > (int) $wallet->reserved_balance) {
            throw new InvalidArgumentException('Available credit changed. Please review the payment again.');
        }

        if ($givenUsed > 0) {
            $wallet->given_balance -= $givenUsed;
            CreditTransaction::create([
                'user_id' => $user->user_id,
                'type' => CreditType::Given->value,
                'direction' => CreditDirection::Debit->value,
                'amount' => $givenUsed,
                'reason' => CreditReason::BookingPayment->value,
                'note' => null,
                'booking_id' => $bookingId,
                'created_by' => $user->user_id,
                'balance_after' => (int) $wallet->given_balance,
                'created_at' => now(),
            ]);
        }

        if ($reservedUsed > 0) {
            $wallet->reserved_balance -= $reservedUsed;
            CreditTransaction::create([
                'user_id' => $user->user_id,
                'type' => CreditType::Reserved->value,
                'direction' => CreditDirection::Debit->value,
                'amount' => $reservedUsed,
                'reason' => CreditReason::BookingPayment->value,
                'note' => null,
                'booking_id' => $bookingId,
                'created_by' => $user->user_id,
                'balance_after' => (int) $wallet->reserved_balance,
                'created_at' => now(),
            ]);
        }

        $wallet->save();

        $hold = CreditPaymentHold::query()->firstOrCreate(
            ['payment_reference_id' => $paymentReference->id],
            [
                'user_id' => $user->user_id,
                'given_held' => 0,
                'reserved_held' => 0,
                'status' => 'held',
                'held_at' => now(),
            ]
        );
        $hold->given_held = (int) $hold->given_held + $givenUsed;
        $hold->reserved_held = (int) $hold->reserved_held + $reservedUsed;
        $hold->save();

        $paymentReference->update([
            'given_used' => $givenUsed,
            'reserved_used' => $reservedUsed,
            'credit_status' => $givenUsed + $reservedUsed > 0 ? 'held' : 'none',
            'credit_held_at' => $givenUsed + $reservedUsed > 0 ? now() : null,
        ]);

        return $hold;
    }

    public function releasePaymentHold(PaymentReference $paymentReference): bool
    {
        $hold = CreditPaymentHold::query()
            ->where('payment_reference_id', $paymentReference->id)
            ->lockForUpdate()
            ->first();

        if (! $hold || $hold->status !== 'held') return false;

        $wallet = CreditWallet::query()
            ->where('user_id', $hold->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $hold->given_held > 0) {
            $wallet->given_balance += (int) $hold->given_held;
            CreditTransaction::create([
                'user_id' => $hold->user_id,
                'type' => CreditType::Given->value,
                'direction' => CreditDirection::Credit->value,
                'amount' => (int) $hold->given_held,
                'reason' => CreditReason::BookingCancellation->value,
                'note' => null,
                'booking_id' => null,
                'created_by' => $hold->user_id,
                'balance_after' => (int) $wallet->given_balance,
                'created_at' => now(),
            ]);
        }

        if ((int) $hold->reserved_held > 0) {
            $wallet->reserved_balance += (int) $hold->reserved_held;
            CreditTransaction::create([
                'user_id' => $hold->user_id,
                'type' => CreditType::Reserved->value,
                'direction' => CreditDirection::Credit->value,
                'amount' => (int) $hold->reserved_held,
                'reason' => CreditReason::BookingCancellation->value,
                'note' => null,
                'booking_id' => null,
                'created_by' => $hold->user_id,
                'balance_after' => (int) $wallet->reserved_balance,
                'created_at' => now(),
            ]);
        }

        $wallet->save();
        $hold->update(['status' => 'released', 'released_at' => now()]);
        $paymentReference->update([
            'credit_status' => 'released',
            'credit_released_at' => now(),
        ]);

        return true;
    }

    public function quoteAndSpendForGuestCheckout(User $user, int $amountPhp, ?string $checkoutKey = null): array
    {
        if ($amountPhp < 0) {
            throw new InvalidArgumentException('Checkout amount cannot be negative.');
        }
        $minimumCash = max(1, (int) config('credit.qr_minimum_php', 1));

        $wallet = CreditWallet::query()->where('user_id', $user->user_id)->lockForUpdate()->first();
        $given = $wallet ? (int) $wallet->given_balance : 0;
        $reserved = $wallet ? (int) $wallet->reserved_balance : 0;
        $total = $given + $reserved;

        $cashDue = $amountPhp;
        $givenUsed = 0;
        $reservedUsed = 0;

        if ($amountPhp > 0) {
            $givenUsed = min($given, $amountPhp);
            $cashDue -= $givenUsed;
            $reservedUsed = min($reserved, $cashDue);
            $cashDue -= $reservedUsed;

            // Keep the QR usable: never leave a partial QR below the documented minimum.
            if ($cashDue > 0 && $cashDue < $minimumCash) {
                $neededFromCredit = $minimumCash - $cashDue;
                $availableCredit = $given - $givenUsed + $reserved - $reservedUsed;
                if ($availableCredit >= $neededFromCredit) {
                    $fromReserved = min($reserved - $reservedUsed, $neededFromCredit);
                    $reservedUsed += $fromReserved;
                    $neededFromCredit -= $fromReserved;
                    $fromGiven = min($given - $givenUsed, $neededFromCredit);
                    $givenUsed += $fromGiven;
                    $cashDue = max(0, $amountPhp - $givenUsed - $reservedUsed);
                }
            }
        }

        return [
            'given_used' => $givenUsed,
            'reserved_used' => $reservedUsed,
            'cash_due' => $cashDue,
            'cash_paid' => 0,
            'credit_total_available' => $total,
        ];
    }
    public function giveGivenCredit(
        User $recipient,
        int $amount,
        User $actor,
        ?string $note = null
    ): CreditTransaction {
        if ($amount < 1 || $amount > (int) config('credit.max_amount')) {
            throw new InvalidArgumentException(
                'Credit amount must be between 1 and ' . (int) config('credit.max_amount') . ' pesos.'
            );
        }

        if ((int) $recipient->user_id === (int) $actor->user_id) {
            throw new InvalidArgumentException('You cannot give credit to your own account.');
        }

        return DB::transaction(function () use ($recipient, $amount, $actor, $note) {
            User::query()
                ->whereKey($recipient->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $wallet = CreditWallet::query()
                ->where('user_id', $recipient->user_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = CreditWallet::create([
                    'user_id' => $recipient->user_id,
                    'given_balance' => 0,
                    'reserved_balance' => 0,
                ]);
                $wallet = CreditWallet::query()
                    ->whereKey($wallet->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $wallet->increment('given_balance', $amount);
            $wallet->refresh();

            return CreditTransaction::create([
                'user_id' => $recipient->user_id,
                'type' => CreditType::Given->value,
                'direction' => CreditDirection::Credit->value,
                'amount' => $amount,
                'reason' => CreditReason::AdminGrant->value,
                'note' => $note,
                'booking_id' => null,
                'created_by' => $actor->user_id,
                'balance_after' => $wallet->given_balance,
                'created_at' => now(),
            ]);
        });
    }
}
