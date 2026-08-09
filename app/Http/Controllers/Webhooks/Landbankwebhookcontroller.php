<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Landbank "fund transfer received" SMS text forwarded by an
 * SMS-forwarding app (e.g. an Android "SMS Forwarder" app) running on the
 * phone tied to the Landbank account guests are told to transfer to. Same
 * pattern as GcashWebhookController — this is the only thing that
 * actually confirms a guest's payment; the reference number the guest
 * types into the booking form is never trusted on its own.
 *
 * Setup checklist (none of this is in the codebase — it's account/device
 * config):
 *   1. Landbank account that receives SMS/mobile alerts for incoming
 *      transfers (enroll in Landbank's SMS notification service for the
 *      receiving account, if not already on by default).
 *   2. A phone with that SIM, running an SMS-forwarding app configured to
 *      POST every incoming SMS to:
 *      https://yourdomain.com/webhooks/landbank-sms?token=<LANDBANK_SMS_WEBHOOK_SECRET>
 *      as JSON: {"message": "<full SMS text>"}
 *   3. LANDBANK_SMS_WEBHOOK_SECRET set in .env and read via
 *      config('services.landbank_sms.secret') — see config/services.php.
 *   4. Route (routes/web.php, CSRF-exempt via bootstrap/app.php's
 *      validateCsrfTokens(except: [...]) since it's an external POST
 *      with no session):
 *      Route::post('/webhooks/landbank-sms', [LandbankWebhookController::class, 'handleSms']);
 *
 * IMPORTANT: unlike GCash, this regex is an unverified guess at Landbank's
 * SMS wording — nobody on this project has seen a real one yet. Log the
 * first real SMS that comes in (see the 'ignored' branch below) and
 * adjust parseLandbankSms() to match it exactly before relying on this
 * for real bookings.
 */
class LandbankWebhookController extends Controller
{
    // Must stay >= GuestBookingController::GCASH_CONFIRM_WINDOW_MINUTES —
    // see the comment on GcashWebhookController::MATCH_WINDOW_MINUTES for
    // why.
    private const MATCH_WINDOW_MINUTES = 20;

    public function handleSms(Request $request)
    {
        $secret = (string) config('services.landbank_sms.secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->query('token'))) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $parsed = $this->parseLandbankSms($validated['message']);

        if ($parsed === null) {
            // Not every SMS on this line is a payment (could be an OTP,
            // a balance alert, etc.) — that's expected and not an error.
            // But if *none* ever match once real transfers start coming
            // in, the regex below needs updating to the actual wording.
            Log::info('landbank_sms: message did not match payment format', [
                'message' => $validated['message'],
            ]);
            return response()->json(['status' => 'ignored']);
        }

        [$amount, $refNumber] = $parsed;

        $candidates = Booking::where('status', 'pending')
            ->where('payment_method', 'landbank')
            ->where('amount', $amount)
            ->where('created_at', '>=', now()->subMinutes(self::MATCH_WINDOW_MINUTES))
            ->orderBy('created_at')
            ->get();

        if ($candidates->isEmpty()) {
            Log::warning('landbank_sms: payment received but no matching pending booking', [
                'amount' => $amount,
                'ref' => $refNumber,
            ]);
            return response()->json(['status' => 'no_match']);
        }

        // Prefer the booking whose guest-entered reference number matches
        // the real one from the SMS — disambiguates two guests who happen
        // to owe the exact same amount around the same time. If only one
        // candidate exists at all, match on amount alone.
        $booking = $candidates->first(fn ($b) => $refNumber && $b->gcash_reference_number === $refNumber)
            ?? ($candidates->count() === 1 ? $candidates->first() : null);

        if ($booking === null) {
            // Multiple same-amount bookings, no reference match — don't
            // guess which one got paid. Leave them pending; auto-expire
            // will still clean up whichever one never gets a real match,
            // and staff can sort it out from the Landbank account
            // directly.
            Log::warning('landbank_sms: ambiguous match, multiple same-amount pending bookings', [
                'amount' => $amount,
                'ref' => $refNumber,
                'candidate_ids' => $candidates->pluck('id')->all(),
            ]);
            return response()->json(['status' => 'ambiguous']);
        }

        $booking->update([
            'status'       => 'confirmed',
            'confirmed_at' => now(),
            'gcash_reference_number' => $refNumber ?? $booking->gcash_reference_number,
        ]);

        return response()->json(['status' => 'confirmed', 'booking_id' => $booking->id]);
    }

    /**
     * Pulls (amount, reference number) out of a Landbank "fund transfer
     * received" SMS.
     *
     * UNVERIFIED — placeholder pattern only. Landbank alert wording
     * varies by service (InstaPay/PESONet credit alert, mobile banking
     * notification, etc.) and nobody has confirmed the exact text this
     * account will receive. Written loosely to catch common phrasings
     * like:
     *   "You have received PHP 1,500.00 via InstaPay. Ref No. 123456789012."
     * Log a real sample first (see handleSms above) and tighten/adjust
     * this once you have one.
     */
    private function parseLandbankSms(string $message): ?array
    {
        if (! preg_match('/received\s+(?:PHP\s*)?([\d,]+(?:\.\d{2})?)/i', $message, $amountMatch)) {
            return null;
        }
        $amount = (float) str_replace(',', '', $amountMatch[1]);

        $refNumber = null;
        if (preg_match('/Ref\.?\s*(?:No\.?|Number)?\s*[:.]?\s*([\d\s]{6,20})/i', $message, $refMatch)) {
            $refNumber = preg_replace('/\s+/', '', $refMatch[1]);
        }

        return [$amount, $refNumber];
    }
}