<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\UnmatchedPayment;
use App\Support\ActivityLogger;
use App\Support\PaymentReference;
use App\Support\PaymentWindows;
use App\Support\WebhookAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 *      https://yourdomain.com/webhooks/gcash-sms
 *      with header:
 *      X-Webhook-Token: <GCASH_SMS_WEBHOOK_SECRET>
 *      as JSON body: {"message": "<full SMS text>"}
 *      (a `?token=` query-string fallback still works but is deprecated
 *      — see App\Support\WebhookAuth — migrate the device config off it)
 *   3. GCASH_SMS_WEBHOOK_SECRET set in .env and read via
 *      config('services.gcash_sms.secret') — see config/services.php.
 *      Optionally also set GCASH_SMS_ALLOWED_IPS (comma-separated) if
 *      the forwarding phone has a stable IP/VPN egress; read via
 *      config('services.gcash_sms.allowed_ips').
 *   4. Route (routes/web.php, CSRF-exempt via bootstrap/app.php's
 *      validateCsrfTokens(except: [...]) since it's an external POST
 *      with no session), rate-limited:
 *      Route::post('/webhooks/gcash-sms', [GcashWebhookController::class, 'handleSms'])
 *          ->middleware('throttle:30,1');
 */
class GcashWebhookController extends Controller
{
    public function handleSms(Request $request)
    {
        $secret = (string) config('services.gcash_sms.secret');
        if (! WebhookAuth::verifyToken($request, $secret)) {
            abort(403);
        }

        if (! WebhookAuth::verifyIp($request, (string) config('services.gcash_sms.allowed_ips', ''))) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $rawMessage = $validated['message'];
        $matchWindow = PaymentWindows::matchWindowMinutes('gcash');

        // Replay guard: the exact same SMS body arriving twice (forwarder
        // retry, proxy resend, a captured-and-replayed request) must
        // never be processed twice. Window matches the match window
        // since that's the longest this message could still legitimately
        // matter for.
        if (WebhookAuth::isDuplicate('gcash_sms', $rawMessage, $matchWindow)) {
            Log::info('gcash_sms: duplicate message ignored', ['hash' => hash('sha256', $rawMessage)]);
            return response()->json(['status' => 'duplicate']);
        }

        $parsed = $this->parseGcashSms($rawMessage);

        if ($parsed === null) {
            // Never log the full SMS body by default — it can carry a
            // sender's name and partial phone number. Log a hash+length
            // so you can still tell "same unmatched wording keeps
            // recurring" apart from noise. If you're actively tuning the
            // regex below, set SMS_WEBHOOK_LOG_RAW=true in .env
            // temporarily (config('services.sms_webhook.log_raw')) to
            // see real bodies, then turn it back off.
            Log::info('gcash_sms: message did not match payment format', [
                'hash'   => hash('sha256', $rawMessage),
                'length' => strlen($rawMessage),
                'raw'    => config('services.sms_webhook.log_raw') ? $rawMessage : null,
            ]);
            return response()->json(['status' => 'ignored']);
        }

        [$amount, $refNumber] = $parsed;
        $normalizedRef = $refNumber !== null ? PaymentReference::normalize($refNumber) : null;

        $result = DB::transaction(function () use ($amount, $refNumber, $normalizedRef, $matchWindow, $rawMessage) {
            // lockForUpdate() here closes the same race the expire-command
            // could otherwise cause: without it, ExpireUnconfirmedGcashBookings
            // could flip a candidate to 'cancelled' between this query and
            // the update below, and we'd happily mark a cancelled booking
            // 'paid'.
            $candidates = Booking::where('status', 'pending')
                ->where('payment_method', 'gcash')
                ->where('amount', $amount)
                ->where('created_at', '>=', now()->subMinutes($matchWindow))
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($candidates->isEmpty()) {
                // Don't just log and drop this — the guest may pay (and
                // this SMS may arrive) before they've finished typing
                // their name/contact and hitting "Confirm Booking". Park
                // it here so GuestBookingController::store() can claim it
                // retroactively the moment a matching booking actually
                // gets created. raw_message is pruned after a short time
                // by the payments:prune-raw-sms scheduled command — see
                // App\Console\Commands\PruneUnmatchedPaymentMessages.
                UnmatchedPayment::create([
                    'payment_method'   => 'gcash',
                    'amount'           => $amount,
                    'reference_number' => $refNumber,
                    'raw_message'      => $rawMessage,
                ]);

                return ['status' => 'no_match'];
            }

            // Normalize before comparing — a guest might type
            // "294-087-757", "Ref# 294087757", or with stray spaces; the
            // SMS-parsed number is already digits-only, but comparing
            // both through the same normalizer keeps this correct even
            // if that ever changes.
            //
            // Reference number is the ONLY thing that confirms which
            // booking this is — there's deliberately no "only one
            // candidate at this amount, so it must be them" fallback.
            // Court pricing is round numbers, so two unrelated bookings
            // landing on the same amount is common, not rare; auto-
            // confirming on amount alone would let a stranger who typed a
            // made-up reference number get matched to someone else's real
            // payment. A mismatched reference stays unmatched — the guest
            // can fix a typo from the booking's "waiting for payment"
            // screen, which re-attempts this same match.
            $booking = ($normalizedRef !== null && $normalizedRef !== '')
                ? $candidates->first(fn ($b) => PaymentReference::normalize((string) $b->gcash_reference_number) === $normalizedRef)
                : null;

            if ($booking === null) {
                return ['status' => 'ambiguous', 'candidate_ids' => $candidates->pluck('id')->all()];
            }

            // Re-check status under the lock. lockForUpdate() on the
            // query above already serializes this against a second,
            // concurrent handleSms() call for the same amount — this
            // guard is specifically for the expire-command race: the
            // booking could have already been cancelled by
            // ExpireUnconfirmedGcashBookings in between being selected
            // above (it was still 'pending' then, hence matched the
            // where()) and this line.
            if ($booking->status !== 'pending') {
                return ['status' => 'already_resolved', 'booking_id' => $booking->id];
            }

            // Belt-and-suspenders: refuse to let the same reference
            // number confirm a second booking. The matching logic above
            // shouldn't be able to reach this given normal traffic, but
            // it's cheap insurance against a future change to that logic
            // accidentally allowing it. Bounded to the last day of paid
            // bookings rather than the whole table.
            if ($normalizedRef !== null && $normalizedRef !== '') {
                $alreadyUsed = Booking::where('payment_method', 'gcash')
                    ->where('status', 'paid')
                    ->where('id', '!=', $booking->id)
                    ->where('created_at', '>=', now()->subDay())
                    ->get(['id', 'gcash_reference_number'])
                    ->contains(fn ($b) => PaymentReference::normalize((string) $b->gcash_reference_number) === $normalizedRef);

                if ($alreadyUsed) {
                    return ['status' => 'duplicate_reference', 'booking_id' => $booking->id];
                }
            }

            $booking->update([
                'status'       => 'paid',
                'confirmed_at' => now(),
                'gcash_reference_number' => $refNumber ?? $booking->gcash_reference_number,
            ]);

            return ['status' => 'confirmed', 'booking_id' => $booking->id];
        });

        if (in_array($result['status'], ['no_match', 'ambiguous', 'already_resolved', 'duplicate_reference'], true)) {
            Log::warning("gcash_sms: {$result['status']}", ['amount' => $amount, 'ref' => $refNumber] + $result);
        }

        // Logged outside the transaction (which has already committed by
        // this point) and only for the 'confirmed' outcome — an
        // unmatched/ambiguous/duplicate SMS isn't a real payment event
        // yet and shouldn't show up as one in the audit trail. actor is
        // explicitly null since this request has no authenticated user —
        // it's an incoming webhook, not a guest or staff action.
        if ($result['status'] === 'confirmed') {
            $booking = Booking::find($result['booking_id']);

            if ($booking) {
                ActivityLogger::log(
                    'booking.paid',
                    sprintf(
                        "%s's GCash payment for %s was confirmed via SMS.",
                        $booking->customer_name,
                        $booking->court?->name ?? 'a court',
                    ),
                    actor: null,
                    subject: $booking,
                    properties: ['amount' => $amount, 'reference_number' => $refNumber],
                );
            }
        }

        return response()->json($result);
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
     * Log a real sample first (SMS_WEBHOOK_LOG_RAW=true) rather than
     * guessing blind.
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