<?php

namespace App\Services;

use App\Enums\CreditDirection;
use App\Enums\CreditReason;
use App\Enums\CreditType;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreditService
{
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
