<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\CreditWallet;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\GoogleIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GuestCreditController extends Controller
{
    private const VERIFIED_KEY = 'guest_credit_verified';
    private const NONCE_KEY = 'guest_credit_google_nonce';

    public function nonce(Request $request): JsonResponse
    {
        $nonce = bin2hex(random_bytes(32));
        $request->session()->put(self::NONCE_KEY, [
            'value' => $nonce,
            'created_at' => now()->timestamp,
        ]);
        $request->session()->save();

        return response()->json(['nonce' => $nonce]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
        ]);

        $nonceEntry = $request->session()->get(self::NONCE_KEY);
        $nonce = is_array($nonceEntry) ? ($nonceEntry['value'] ?? null) : null;
        if (! is_string($nonce) || $nonce === '') {
            return response()->json(['message' => 'Please sign in with Google again.'], 422);
        }

        $claims = GoogleIdentity::verifyIdToken($validated['id_token'], $nonce);
        if (! $claims) {
            return response()->json(['message' => "We couldn't verify that Google sign-in. Please try again."], 422);
        }

        $matches = User::query()
            ->whereRaw('LOWER(email) = LOWER(?)', [$claims['email']])
            ->limit(2)
            ->get(['user_id', 'name', 'email', 'phone_number']);

        $request->session()->put(self::NONCE_KEY, null);

        if ($matches->count() !== 1) {
            if ($matches->count() > 1) {
                Cache::increment('security:ambiguous-credit-email:'.hash('sha256', $claims['email']));
            }
            $request->session()->put(self::VERIFIED_KEY, [
                'user_id' => null,
                'email' => $claims['email'],
                'verified_at' => now()->timestamp,
                'matched' => false,
            ]);
            $request->session()->save();
            return response()->json([
                'verified' => false,
                'credit' => null,
                'prefill' => null,
            ], 200);
        }

        $user = $matches->first();
        $wallet = CreditWallet::query()->firstOrCreate(
            ['user_id' => $user->user_id],
            ['given_balance' => 0, 'reserved_balance' => 0]
        );

        $request->session()->put(self::VERIFIED_KEY, [
            'user_id' => $user->user_id,
            'email' => $user->email,
            'verified_at' => now()->timestamp,
        ]);
        $request->session()->put(self::NONCE_KEY, null);
        $request->session()->save();
        $request->session()->regenerate();

        ActivityLogger::log(
            'guest.credit_identity_verified',
            'A guest verified a registered Google email for credit use.',
            actor: null,
            subject: $user,
            properties: ['credit_email_verified' => true]
        );

        return response()->json([
            'verified' => true,
            'email' => $user->email,
            'credit' => [
                'given' => (int) $wallet->given_balance,
                'reserved' => (int) $wallet->reserved_balance,
                'total' => (int) $wallet->given_balance + (int) $wallet->reserved_balance,
            ],
            'prefill' => [
                'name' => $user->name,
                'phone' => $user->phone_number,
                'email' => $user->email,
            ],
        ]);
    }

    public function state(Request $request): JsonResponse
    {
        $verified = $this->verifiedIdentity($request);
        if (! $verified) {
            return response()->json(['verified' => false, 'credit' => null, 'prefill' => null]);
        }

        $user = User::query()->find($verified['user_id']);
        if (! $user) {
            $request->session()->forget(self::VERIFIED_KEY);
            return response()->json(['verified' => false, 'credit' => null, 'prefill' => null]);
        }

        $wallet = CreditWallet::query()->where('user_id', $user->user_id)->first();
        return response()->json([
            'verified' => true,
            'email' => $user->email,
            'credit' => [
                'given' => (int) ($wallet->given_balance ?? 0),
                'reserved' => (int) ($wallet->reserved_balance ?? 0),
                'total' => (int) (($wallet->given_balance ?? 0) + ($wallet->reserved_balance ?? 0)),
            ],
            'prefill' => [
                'name' => $user->name,
                'phone' => $user->phone_number,
                'email' => $user->email,
            ],
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        $request->session()->forget([self::VERIFIED_KEY, self::NONCE_KEY]);
        $request->session()->save();
        $request->session()->regenerate();
        return response()->json(['cleared' => true]);
    }

    private function verifiedIdentity(Request $request): ?array
    {
        $value = $request->session()->get(self::VERIFIED_KEY);
        if (! is_array($value) || empty($value['user_id']) || empty($value['verified_at'])) return null;
        $hours = max(1, (int) config('credit.guest_verified_hours', 12));
        if ((int) $value['verified_at'] + $hours * 3600 < now()->timestamp) {
            $request->session()->forget(self::VERIFIED_KEY);
            return null;
        }
        return $value;
    }
}
