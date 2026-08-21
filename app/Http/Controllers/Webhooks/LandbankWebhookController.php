<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentReference;
use App\Models\UnmatchedPayment;
use App\Support\ActivityLogger;
use App\Support\PaymentReference as PaymentReferenceNormalizer;
use App\Support\PaymentWindows;
use App\Support\WebhookAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives Landbank "fund transfer received" SMS text forwarded by an
 * SMS-forwarding app (e.g. an Android "SMS Forwarder" app) running on the
 * phone tied to the Landbank account guests are told to transfer to. Same
 * pattern as GcashWebhookController — this is the only thing that
 * actually confirms a guest's payment; the reference number the guest
 * types into the booking form is never trusted on its own.
 *
 * Matches against payment_reference now, not bookings directly — see
 * App\Models\PaymentReference and GcashWebhookController's docblock.
 *
 * Setup checklist (none of this is in the codebase — it's account/device
 * config):
 *   1. Landbank account that receives SMS/mobile alerts for incoming
 *      transfers (enroll in Landbank's SMS notification service for the
 *      receiving account, if not already on by default).
 *   2. A phone with that SIM, running an SMS-forwarding app configured to
 *      POST every incoming SMS to:
 *      https://yourdomain.com/webhooks/landbank-sms
 *      with header:
 *      X-Webhook-Token: <LANDBANK_SMS_WEBHOOK_SECRET>
 *      as JSON body: {"message": "<full SMS text>"}
 *      (a `?token=` query-string fallback still works but is deprecated
 *      — see App\Support\WebhookAuth — migrate the device config off it)
 *   3. LANDBANK_SMS_WEBHOOK_SECRET set in .env and read via
 *      config('services.landbank_sms.secret') — see config/services.php.
 *      Optionally also set LANDBANK_SMS_ALLOWED_IPS (comma-separated) if
 *      the forwarding phone has a stable IP/VPN egress; read via
 *      config('services.landbank_sms.allowed_ips').
 *   4. Route (routes/web.php, CSRF-exempt via bootstrap/app.php's
 *      validateCsrfTokens(except: [...]) since it's an external POST
 *      with no session), rate-limited:
 *      Route::post('/webhooks/landbank-sms', [LandbankWebhookController::class, 'handleSms'])
 *          ->middleware('throttle:30,1');
 *
 * IMPORTANT: unlike GCash, this regex is an unverified guess at Landbank's
 * SMS wording — nobody on this project has seen a real one yet. Set
 * SMS_WEBHOOK_LOG_RAW=true temporarily to capture the first real SMS,
 * then adjust parseLandbankSms() to match it exactly before relying on
 * this for real bookings, and turn raw logging back off.
 */
class LandbankWebhookController extends Controller
{
    public function handleSms(Request $request)
    {
        $secret = (string) config('services.landbank_sms.secret');
        if (! WebhookAuth::verifyToken($request, $secret)) {
            abort(403);
        }

        if (! WebhookAuth::verifyIp($request, (string) config('services.landbank_sms.allowed_ips', ''))) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $rawMessage = $validated['message'];
        $matchWindow = PaymentWindows::matchWindowMinutes('landbank');

        if (WebhookAuth::isDuplicate('landbank_sms', $rawMessage, $matchWindow)) {
            Log::info('landbank_sms: duplicate message ignored', ['hash' => hash('sha256', $rawMessage)]);
            return response()->json(['status' => 'duplicate']);
        }

        $parsed = $this->parseLandbankSms($rawMessage);

        if ($parsed === null) {
            // Never log the full SMS body by default — see the class
            // docblock. Set SMS_WEBHOOK_LOG_RAW=true in .env while
            // capturing the first real sample to fix the parser below.
            Log::info('landbank_sms: message did not match payment format', [
                'hash'   => hash('sha256', $rawMessage),
                'length' => strlen($rawMessage),
                'raw'    => config('services.sms_webhook.log_raw') ? $rawMessage : null,
            ]);
            return response()->json(['status' => 'ignored']);
        }

        [$amount, $refNumber] = $parsed;
        $normalizedRef = $refNumber !== null ? PaymentReferenceNormalizer::normalize($refNumber) : null;

        $result = DB::transaction(function () use ($amount, $refNumber, $normalizedRef, $matchWindow, $rawMessage) {
            $candidates = PaymentReference::whereNull('confirmed_at')
                ->where('payment_method', 'landbank')
                ->where('amount', $amount)
                ->where('created_at', '>=', now()->subMinutes($matchWindow))
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($candidates->isEmpty()) {
                // Park it so a payment_reference created moments later can
                // still claim it — see the matching comment in
                // GcashWebhookController. raw_message is pruned after a
                // short time by payments:prune-raw-sms.
                UnmatchedPayment::create([
                    'payment_method'   => 'landbank',
                    'amount'           => $amount,
                    'reference_number' => $refNumber,
                    'raw_message'      => $rawMessage,
                ]);

                return ['status' => 'no_match'];
            }

            // Reference number is the ONLY thing that confirms which
            // payment this is — deliberately no "only one candidate at
            // this amount" fallback. Court pricing is round numbers, so
            // same-amount collisions are common; auto-confirming on
            // amount alone would let a stranger who typed a made-up
            // reference race a real payer and get matched to their
            // payment before the real payer even finishes the form. A
            // mismatched reference stays unmatched — recoverable
            // manually by staff; a stolen payment is not.
            $paymentReference = ($normalizedRef !== null && $normalizedRef !== '')
                ? $candidates->first(fn ($p) => PaymentReferenceNormalizer::normalize((string) $p->payment_reference) === $normalizedRef)
                : null;

            if ($paymentReference === null) {
                // See GcashWebhookController for why this is parked
                // rather than dropped — a same-amount collision with an
                // unrelated pending booking must not cause a real
                // payment to vanish with no record anywhere.
                UnmatchedPayment::create([
                    'payment_method'   => 'landbank',
                    'amount'           => $amount,
                    'reference_number' => $refNumber,
                    'raw_message'      => $rawMessage,
                ]);

                return ['status' => 'ambiguous', 'candidate_ids' => $candidates->pluck('id')->all()];
            }

            // See GcashWebhookController for why this re-check exists —
            // covers the expire-command race specifically.
            if ($paymentReference->confirmed_at !== null) {
                return ['status' => 'already_resolved', 'payment_reference_id' => $paymentReference->id];
            }

            if ($normalizedRef !== null && $normalizedRef !== '') {
                $alreadyUsed = PaymentReference::where('payment_method', 'landbank')
                    ->whereNotNull('confirmed_at')
                    ->where('id', '!=', $paymentReference->id)
                    ->where('created_at', '>=', now()->subDay())
                    ->get(['id', 'gcash_reference_number'])
                    ->contains(fn ($p) => PaymentReferenceNormalizer::normalize((string) $p->gcash_reference_number) === $normalizedRef);

                if ($alreadyUsed) {
                    return ['status' => 'duplicate_reference', 'payment_reference_id' => $paymentReference->id];
                }
            }

            $paymentReference->update([
                'confirmed_at'           => now(),
                'gcash_reference_number' => $refNumber ?? $paymentReference->gcash_reference_number,
            ]);

            $bookingIds = $paymentReference->bookings()
                ->where('status', 'pending')
                ->pluck('id');

            $paymentReference->bookings()->where('status', 'pending')->update([
                'status'                 => 'paid',
                'confirmed_at'           => now(),
                'gcash_reference_number' => $refNumber,
            ]);

            return [
                'status'               => 'confirmed',
                'payment_reference_id' => $paymentReference->id,
                'booking_ids'          => $bookingIds->all(),
            ];
        });

        if (in_array($result['status'], ['no_match', 'ambiguous', 'already_resolved', 'duplicate_reference'], true)) {
            Log::warning("landbank_sms: {$result['status']}", ['amount' => $amount, 'ref' => $refNumber] + $result);
        }

        // See GcashWebhookController for why this is only logged on
        // 'confirmed' and outside the transaction.
        if ($result['status'] === 'confirmed') {
            foreach (\App\Models\Booking::whereIn('id', $result['booking_ids'])->get() as $booking) {
                ActivityLogger::log(
                    'booking.paid',
                    sprintf(
                        "%s's Landbank payment for %s was confirmed via SMS.",
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
     * Pulls (amount, reference number) out of a Landbank "fund transfer
     * received" SMS.
     *
     * UNVERIFIED — placeholder pattern only. Landbank alert wording
     * varies by service (InstaPay/PESONet credit alert, mobile banking
     * notification, etc.) and nobody has confirmed the exact text this
     * account will receive. Written loosely to catch common phrasings
     * like:
     *   "You have received PHP 1,500.00 via InstaPay. Ref No. 123456789012."
     * Capture a real sample first (SMS_WEBHOOK_LOG_RAW=true) and
     * tighten/adjust this once you have one, then add a couple of tests
     * pinned to the real wording.
     */
    private function parseLandbankSms(string $message): ?array
    {
        if (! preg_match('/received\s+(?:PHP\s*)?([\d,]+(?:\.\d{2})?)/i', $message, $amountMatch)) {
            return null;
        }
        $amount = (float) str_replace(',', '', $amountMatch[1]);

        $refNumber = null;
        if (preg_match('/Ref\.?\s*(?:No\.?|Number)?\s*[:.]?\s*([\d\s]{6,20})/i', $message, $refMatch)) {
            $refNumber = PaymentReferenceNormalizer::normalize($refMatch[1]);
        }

        return [$amount, $refNumber];
    }
}