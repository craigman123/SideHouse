<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\UnmatchedPayment;
use App\Support\PaymentReference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives GCash "money received" SMS text forwarded by an SMS-forwarding
 * app (e.g. an Android "SMS Forwarder" app) running on the phone tied to
 * your GCash for Business merchant QR. This is the only thing that
 * actually confirms a guest's payment — the screenshot/reference number
 * the guest types into the booking form is never trusted on its own.
 *
 * Setup checklist (none of this is in the codebase — it's account/device
 * config):
 *   1. GCash for Business account approved, merchant QR active.
 *   2. A phone with that SIM, running an SMS-forwarding app configured to
 *      POST every incoming SMS to:
 *      https://yourdomain.com/webhooks/gcash-sms?token=<GCASH_SMS_WEBHOOK_SECRET>
 *      as JSON: {"message": "<full SMS text>"}
 *   3. GCASH_SMS_WEBHOOK_SECRET set in .env and read via
 *      config('services.gcash_sms.secret') — see config/services.php.
 *   4. Route (routes/web.php, CSRF-exempt via bootstrap/app.php's
 *      validateCsrfTokens(except: [...]) since it's an external POST
 *      with no session):
 *      Route::post('/webhooks/gcash-sms', [GcashWebhookController::class, 'handleSms']);
 */
class GcashWebhookController extends Controller
{
    // How far back we'll look for a pending booking to match this SMS
    // against. Must stay >= GuestBookingController::GCASH_CONFIRM_WINDOW_MINUTES
    // — if this is shorter, a real payment that arrives just before the
    // booking expires could fail to match a booking that's still pending.
    private const MATCH_WINDOW_MINUTES = 20;

    public function handleSms(Request $request)
    {
        $secret = (string) config('services.gcash_sms.secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->query('token'))) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $parsed = $this->parseGcashSms($validated['message']);

        if ($parsed === null) {
            // Not every SMS on this line is a payment (could be a load
            // promo, OTP, etc.) — that's expected and not an error. But if
            // *none* ever match once real payments start coming in, the
            // regex below needs updating to your actual SMS wording.
            Log::info('gcash_sms: message did not match payment format', [
                'message' => $validated['message'],
            ]);
            return response()->json(['status' => 'ignored']);
        }

        [$amount, $refNumber] = $parsed;

        $candidates = Booking::where('status', 'pending')
            ->where('payment_method', 'gcash')
            ->where('amount', $amount)
            ->where('created_at', '>=', now()->subMinutes(self::MATCH_WINDOW_MINUTES))
            ->orderBy('created_at')
            ->get();

        if ($candidates->isEmpty()) {
            // Don't just log and drop this — the guest may pay (and this
            // SMS may arrive) before they've finished typing their name/
            // contact and hitting "Confirm Booking". Park it here so
            // GuestBookingController::store() can claim it retroactively
            // the moment a matching booking actually gets created.
            UnmatchedPayment::create([
                'payment_method'    => 'gcash',
                'amount'            => $amount,
                'reference_number'  => $refNumber,
                'raw_message'       => $validated['message'],
            ]);

            Log::warning('gcash_sms: payment received but no matching pending booking — parked for later claim', [
                'amount' => $amount,
                'ref' => $refNumber,
            ]);
            return response()->json(['status' => 'no_match']);
        }

        // Normalize before comparing — a guest might type "294-087-757",
        // "Ref# 294087757", or with stray spaces; the SMS-parsed number
        // is already digits-only, but comparing both through the same
        // normalizer keeps this correct even if that ever changes.
        $normalizedRef = $refNumber !== null ? PaymentReference::normalize($refNumber) : null;

        $booking = $candidates->first(function ($b) use ($normalizedRef) {
                return $normalizedRef !== null
                    && $normalizedRef !== ''
                    && PaymentReference::normalize((string) $b->gcash_reference_number) === $normalizedRef;
            })
            ?? ($candidates->count() === 1 ? $candidates->first() : null);

        if ($booking === null) {
            // Multiple same-amount bookings, no reference match — don't
            // guess which one got paid. Leave them pending; auto-expire
            // will still clean up whichever one never gets a real match,
            // and staff can sort it out from the GCash dashboard directly.
            Log::warning('gcash_sms: ambiguous match, multiple same-amount pending bookings', [
                'amount' => $amount,
                'ref' => $refNumber,
                'candidate_ids' => $candidates->pluck('id')->all(),
            ]);
            return response()->json(['status' => 'ambiguous']);
        }

        $booking->update([
            'status'       => 'paid',
            'confirmed_at' => now(),
            'gcash_reference_number' => $refNumber ?? $booking->gcash_reference_number,
        ]);

        return response()->json(['status' => 'confirmed', 'booking_id' => $booking->id]);
    }

    /**
     * Pulls (amount, reference number) out of a GCash "money received" SMS.
     *
     * Based on GCash's long-standing peer-transfer wording:
     *   "You have received 2,950.00 GCASH from Juan D. 09171234567.
     *    Your new balance is 7,950.00. Ref. No. 294087757."
     *
     * GCash for Business (merchant QR) notifications may be worded
     * differently — confirm against a real SMS once the merchant account
     * is active and adjust the two patterns below if it doesn't match.
     * Log a real sample first rather than guessing blind.
     */
    private function parseGcashSms(string $message): ?array
    {
        if (! preg_match('/received\s+(?:PHP\s*)?([\d,]+(?:\.\d{2})?)\s*(?:GCASH|PHP)?/i', $message, $amountMatch)) {
            return null;
        }
        $amount = (float) str_replace(',', '', $amountMatch[1]);

        $refNumber = null;
        if (preg_match('/Ref\.?\s*No\.?\s*[:.]?\s*([\d\s]{6,20})/i', $message, $refMatch)) {
            $refNumber = PaymentReference::normalize($refMatch[1]);
        }

        return [$amount, $refNumber];
    }
}