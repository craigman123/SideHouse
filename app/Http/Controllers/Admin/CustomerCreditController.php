<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditLookupRequest;
use App\Http\Requests\GiveCreditRequest;
use App\Models\User;
use App\Services\CreditService;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class CustomerCreditController extends Controller
{
    public function lookup(CreditLookupRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim($request->validated('email')));
        $recipient = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first(['user_id', 'name', 'email']);

        if (! $recipient) {
            return response()->json([
                'found' => false,
                'message' => 'No registered account found for that email.',
            ], 404);
        }

        return response()->json([
            'found' => true,
            'name' => $recipient->name,
            'email' => $recipient->email,
        ]);
    }

    public function store(GiveCreditRequest $request, CreditService $credits): JsonResponse
    {
        $validated = $request->validated();
        $email = mb_strtolower(trim($validated['email']));
        $recipient = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $recipient) {
            return response()->json([
                'message' => 'No registered account found for that email.',
            ], 422);
        }

        $actor = $request->user();
        if ((int) $recipient->user_id === (int) $actor->user_id) {
            return response()->json([
                'message' => 'You cannot give credit to your own account.',
            ], 422);
        }

        $key = 'credit-give:' . hash('sha256', implode('|', [
            $actor->user_id,
            $recipient->user_id,
            (int) $validated['amount'],
            trim((string) ($validated['note'] ?? '')),
            $validated['idempotency_token'],
        ]));

        $existing = Cache::get($key);
        if (is_array($existing)) {
            return response()->json([
                'message' => 'Given Credit was already sent.',
                'idempotent' => true,
                'recipient' => [
                    'name' => $recipient->name,
                    'email' => $recipient->email,
                ],
                'amount' => (int) $validated['amount'],
            ]);
        }

        if (! Cache::add($key, ['pending' => true], now()->addMinutes(10))) {
            return response()->json([
                'message' => 'Given Credit was already sent.',
                'idempotent' => true,
            ]);
        }

        try {
            $transaction = $credits->giveGivenCredit(
                $recipient,
                (int) $validated['amount'],
                $actor,
                trim((string) ($validated['note'] ?? '')) ?: null
            );
        } catch (InvalidArgumentException $exception) {
            Cache::forget($key);
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            Cache::forget($key);
            report($exception);
            return response()->json([
                'message' => 'Could not send Given Credit. Please try again.',
            ], 500);
        }

        Cache::put($key, [
            'transaction_id' => $transaction->id,
            'balance_after' => $transaction->balance_after,
        ], now()->addMinutes(10));

        ActivityLogger::log(
            'credit.given',
            "{$actor->name} gave ₱{$transaction->amount} Given Credit to {$recipient->name} ({$recipient->email}).",
            actor: $actor,
            subject: $recipient,
            properties: [
                'recipient_email' => $recipient->email,
                'amount' => $transaction->amount,
                'credit_type' => 'given',
                'note' => $transaction->note,
                'transaction_id' => $transaction->id,
            ]
        );

        return response()->json([
            'message' => 'Given Credit sent.',
            'idempotent' => false,
            'recipient' => [
                'name' => $recipient->name,
                'email' => $recipient->email,
            ],
            'amount' => $transaction->amount,
        ]);
    }
}
