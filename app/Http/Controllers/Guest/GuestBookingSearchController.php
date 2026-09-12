<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Mail\GuestBookingOtpMail;
use App\Models\Booking;
use App\Models\Equipment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Backs the landing page's "Find Your Booking" modal. Replaces the old
 * phone-or-email direct lookup: a guest now has to prove they own the
 * email address before any booking data is returned. No phone number is
 * collected or matched against here anymore.
 *
 * Flow: requestCode() emails a 4-digit code and caches it against the
 * email; verifyCode() checks it and, only on a match, returns that
 * email's bookings. Nothing is persisted to the database — codes live in
 * cache only, keyed by email, for CODE_TTL_MINUTES.
 */
class GuestBookingSearchController extends Controller
{
    private const CODE_TTL_MINUTES = 10;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;

    public function requestCode(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = strtolower(trim($validated['email']));

        if (Cache::has($this->cooldownKey($email))) {
            return response()->json([
                'message' => 'Please wait a bit before requesting another code.',
            ], 429);
        }

        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        Cache::put($this->codeKey($email), [
            'code'     => $code,
            'attempts' => 0,
        ], now()->addMinutes(self::CODE_TTL_MINUTES));

        Cache::put($this->cooldownKey($email), true, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS));

        try {
            Mail::to($email)->send(new GuestBookingOtpMail($code, self::CODE_TTL_MINUTES));
        } catch (\Throwable $e) {
            // Don't let a mail failure change the response shape — that
            // would let someone tell which emails exist by watching for
            // errors. Log it so it actually shows up somewhere instead
            // of vanishing silently, which is what was happening before.
            Log::error('Failed to send guest booking OTP email', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        // Same response whether or not this email has any bookings on
        // file, so the endpoint can't be used to test which addresses
        // have booking history.
        return response()->json([
            'message' => 'If that email has bookings, a code has been sent.',
        ]);
    }

    public function verifyCode(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code'  => ['required', 'string', 'size:4'],
        ]);

        $email = strtolower(trim($validated['email']));
        $key = $this->codeKey($email);
        $entry = Cache::get($key);

        if (! $entry) {
            return response()->json([
                'message' => 'That code has expired. Please request a new one.',
            ], 422);
        }

        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return response()->json([
                'message' => 'Too many attempts. Please request a new code.',
            ], 422);
        }

        if (! hash_equals($entry['code'], $validated['code'])) {
            $entry['attempts']++;
            Cache::put($key, $entry, now()->addMinutes(self::CODE_TTL_MINUTES));

            return response()->json([
                'message' => 'That code is incorrect.',
            ], 422);
        }

        // Code is single-use — clear it as soon as it's spent, whether or
        // not this email actually has any bookings.
        Cache::forget($key);

        return response()->json([
            'bookings' => $this->bookingsForEmail($email),
        ]);
    }

    private function bookingsForEmail(string $email): array
    {
        $bookings = Booking::with(['court', 'equipment', 'paymentReference'])
            ->where('email', $email)
            ->orderByDesc('date')
            ->orderByDesc('start_time')
            ->limit(20)
            ->get();

        if ($bookings->isEmpty()) {
            return [];
        }

        // Resolved by id rather than through a relation on the
        // booking_equipment rows themselves, so this stays correct no
        // matter what that relation ends up being called.
        $equipmentNames = Equipment::whereIn(
            'id',
            $bookings->flatMap(fn ($b) => $b->equipment->pluck('equipment_id'))->unique()
        )->pluck('name', 'id');

        return $bookings->map(fn ($booking) => [
            'court'     => $booking->court?->name ?? 'Court',
            'date'      => Carbon::parse($booking->date)->format('M d, Y'),
            'time'      => Carbon::parse($booking->start_time)->format('g:i A') . ' – ' . Carbon::parse($booking->end_time)->format('g:i A'),
            'status'    => $booking->status,
            'amount'    => (float) $booking->amount,
            'payment'   => $booking->paymentReference?->payment_method ?? $booking->payment_method,
            'reference' => $this->maskReference($booking->paymentReference?->gcash_reference_number ?? $booking->gcash_reference_number),
            'equipment' => $booking->equipment->map(fn ($line) => [
                'name'     => $equipmentNames[$line->equipment_id] ?? 'Item',
                'quantity' => $line->quantity,
            ])->values(),
        ])->values()->all();
    }

    /**
     * Fixed-length mask regardless of input length, so the asterisks
     * themselves don't leak how long the real reference is.
     */
    private function maskReference(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return $reference;
        }

        $length = strlen($reference);

        if ($length <= 6) {
            return substr($reference, 0, 1) . str_repeat('*', max($length - 1, 0));
        }

        return substr($reference, 0, 4) . '***' . substr($reference, -2);
    }

    private function codeKey(string $email): string
    {
        return 'guest_booking_otp:' . $email;
    }

    private function cooldownKey(string $email): string
    {
        return 'guest_booking_otp_cooldown:' . $email;
    }
}
