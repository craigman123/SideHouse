<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentReference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Handles the PayMongo QR Ph flow for a booking.
 *
 * IMPORTANT CHANGE FROM THE FIRST VERSION:
 * createQr() now takes a booking_id, not a bare amount. The booking must
 * already exist as 'pending' (created by storeBooking()/store() first,
 * same as your gcash/maya flow creates a pending Booking before payment
 * confirms). This endpoint just attaches a QR Ph Payment Intent to that
 * existing booking and persists the intent id on it, so the webhook below
 * has something exact to match against — no amount/reference guessing.
 */
class PaymongoQrPhController extends Controller
{
    private const API_BASE = 'https://api.paymongo.com/v1';

    /**
     * POST /guest-book/payment/qrph
     * Body: { booking_id: number, token: string }
     *
     * Called right after storeBooking()/store() returns a booking_id for a
     * payment_method === 'qrph' booking. Re-derives the amount from the
     * booking itself server-side — never trust a client-sent amount.
     *
     * `token` must match the booking's poll_token, so only the guest who
     * actually owns this booking (i.e. has the waiting-page link) can
     * generate a QR for it — booking_id alone is not sufficient, since IDs
     * are sequential and guessable.
     */
    public function createQr(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:bookings,id'],
            'token' => ['nullable', 'string'],
        ]);

        $booking = Booking::findOrFail($validated['booking_id']);

        $isOwner = auth()->check() && $booking->user_id === auth()->id();
        $hasValidToken = $booking->poll_token
            && !empty($validated['token'] ?? null)
            && hash_equals($booking->poll_token, $validated['token']);

        if (! $isOwner && ! $hasValidToken) {
            abort(403);
        }

        if ($booking->status !== 'pending') {
            return response()->json(['message' => 'This booking is no longer awaiting payment.'], 409);
        }

        // This intent covers the whole checkout and the webhook confirms
        // every booking under it as paid once PayMongo reports success —
        // so this must only ever run for bookings that were actually
        // created as qrph in the first place. A token for a booking on a
        // different payment method (or none) must not be able to attach
        // a QR intent and get itself confirmed through this path.
        if ($booking->payment_method !== 'qrph') {
            return response()->json(['message' => 'This booking is not set up for QR Ph payment.'], 409);
        }

        $paymentReference = $booking->paymentReference;

        if (!$paymentReference) {
            return response()->json(['message' => 'Payment reference missing for this booking.'], 422);
        }

        // Atomically claim the PaymentReference before making any PayMongo
        // calls. The intent covers the WHOLE checkout (its amount is the
        // PaymentReference total, and the webhook confirms every sibling
        // booking under it) — so the claim belongs here, not on whichever
        // single Booking row the guest's URL happens to reference. Two
        // simultaneous requests both reading payment_intent_id as null
        // used to both pass and both call out to PayMongo — this row lock
        // + immediate claim write closes that window. We claim with a
        // temporary sentinel (not a real intent id yet) so the webhook
        // never matches against it accidentally, then overwrite it with
        // the real intent id once PayMongo responds.
        $claimToken = 'claiming:' . (string) \Illuminate\Support\Str::uuid();

        // PaymongoQrPhController::createQr() — inside the existing claim transaction
        $claimed = DB::transaction(function () use ($paymentReference, $claimToken, $booking) {
            $locked = PaymentReference::where('id', $paymentReference->id)->lockForUpdate()->first();

            // Re-check now that we hold the lock — a concurrent cancelAll() may
            // have committed between our earlier status check and this one.
            $freshBooking = Booking::find($booking->id);
            if (! $freshBooking || $freshBooking->status !== 'pending') {
                return 'stale';
            }

            if ($locked->payment_intent_id) {
                return null;
            }

            $locked->update(['payment_intent_id' => $claimToken]);
            return $locked;
        });

        if ($claimed === 'stale') {
            return response()->json(['message' => 'This booking is no longer awaiting payment.'], 409);
        }

        if (!$claimed) {
            $current = $paymentReference->fresh();

            $hasRealIntent = $current->payment_intent_id
                && !str_starts_with((string) $current->payment_intent_id, 'claiming:');

            // Not a real conflict — this is the SAME checkout's QR being
            // asked for again (e.g. the guest refreshed the waiting page,
            // or the URL references a different sibling date under the
            // same PaymentReference). A previous call already generated
            // and stored it below, so just hand that back instead of
            // erroring, as long as it hasn't expired.
            if ($hasRealIntent && $current->qr_image_url && $current->qr_code_expires_at?->isFuture()) {
                return response()->json([
                    'payment_intent_id' => $current->payment_intent_id,
                    'qr_image_url' => $current->qr_image_url,
                    'expires_at' => $current->qr_code_expires_at->toIso8601String(),
                ]);
            }

            // Either a genuinely concurrent request is mid-flight right
            // now (payment_intent_id is still the "claiming:" sentinel),
            // or the previously-generated QR has expired with nothing
            // fresh to hand back yet. PayMongo's idempotency key below is
            // derived from the PaymentReference id, so simply retrying
            // can't produce a fresh intent for an expired one — the guest
            // needs to cancel and rebook.
            return response()->json([
                'message' => $hasRealIntent
                    ? 'Your payment QR has expired. Please cancel and rebook to try again.'
                    : 'A payment QR already exists for this booking.',
                'payment_intent_id' => $hasRealIntent ? $current->payment_intent_id : null,
            ], 409);
        }

        $paymentReference = $claimed;

        $amountCentavos = (int) round($paymentReference->amount * 100);
        $secretKey = config('services.paymongo.secret');

        if (!$secretKey) {
            // Http::withBasicAuth()'s $username param is strictly typed
            // string — passing null here throws an uncaught TypeError,
            // which is what was surfacing as a bare, message-less 500 on
            // the client instead of a real error. Catch it here instead.
            Log::error('PayMongo secret key is not configured (services.paymongo.secret / PAYMONGO_SECRET_KEY).');
            $this->releaseClaim($paymentReference, $claimToken);
            return response()->json(['message' => 'Payment is not configured yet. Please contact the venue.'], 502);
        }

        // Deterministic idempotency key from the PaymentReference ID — if
        // this request is retried (e.g. a client timeout followed by a
        // retry) PayMongo will return the same intent instead of creating
        // a second one, even outside of our own row-lock window.
        $idempotencyKey = 'qrph-intent-' . $paymentReference->id;

        $intentResponse = Http::withBasicAuth($secretKey, '')
            ->timeout(10)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post(self::API_BASE . '/payment_intents', [
                'data' => [
                    'attributes' => [
                        'amount' => $amountCentavos,
                        'currency' => 'PHP',
                        'payment_method_allowed' => ['qrph'],
                        'description' => "Side House Paddlers — Payment #{$paymentReference->id}",
                        'metadata' => [
                            'booking_id' => (string) $booking->id,
                        ],
                    ],
                ],
            ]);

        if ($intentResponse->failed()) {
            Log::error('PayMongo payment_intents create failed', [
                'status' => $intentResponse->status(),
                'payment_reference_id' => $paymentReference->id,
            ]);
            $this->releaseClaim($paymentReference, $claimToken);
            return response()->json(['message' => 'Could not start payment. Please try again.'], 502);
        }

        $intent = $intentResponse->json('data');
        $intentId = $intent['id'];
        $clientKey = $intent['attributes']['client_key'];

        $methodResponse = Http::withBasicAuth($secretKey, '')
            ->timeout(10)
            ->post(self::API_BASE . '/payment_methods', [
                'data' => ['attributes' => ['type' => 'qrph']],
            ]);

        if ($methodResponse->failed()) {
            Log::error('PayMongo payment_methods create failed', [
                'status' => $methodResponse->status(),
                'payment_reference_id' => $paymentReference->id,
            ]);
            $this->releaseClaim($paymentReference, $claimToken);
            return response()->json(['message' => 'Could not start payment. Please try again.'], 502);
        }

        $methodId = $methodResponse->json('data.id');

        $attachResponse = Http::withBasicAuth($secretKey, '')
            ->timeout(10)
            ->post(self::API_BASE . "/payment_intents/{$intentId}/attach", [
                'data' => [
                    'attributes' => [
                        'payment_method' => $methodId,
                        'client_key' => $clientKey,
                    ],
                ],
            ]);

        if ($attachResponse->failed()) {
            Log::error('PayMongo attach failed', [
                'status' => $attachResponse->status(),
                'payment_reference_id' => $paymentReference->id,
                'intent_id' => $intentId,
            ]);
            $this->releaseClaim($paymentReference, $claimToken);
            return response()->json(['message' => 'Could not start payment. Please try again.'], 502);
        }

        $attached = $attachResponse->json('data');

        $qrImageUrl = $attached['attributes']['next_action']['code']['image_url'] ?? null;
        $qrExpiresAt = $attached['attributes']['next_action']['code']['expires_at'] ?? null;

        if (!$qrImageUrl) {
            Log::error('PayMongo attach succeeded but no QR image returned', [
                'payment_reference_id' => $paymentReference->id,
                'intent_id' => $intentId,
            ]);
            $this->releaseClaim($paymentReference, $claimToken);
            return response()->json(['message' => 'Could not generate QR. Please try again.'], 502);
        }

        // The critical line that was a TODO before — this is what lets the
        // webhook below find the right PaymentReference (and therefore all
        // of its sibling bookings) by an exact id, no matching heuristics
        // needed. Stored on PaymentReference, not Booking, since the
        // intent covers the whole checkout, not one date.
        $paymentReference->update([
            'payment_intent_id' => $intentId,
            'qr_image_url' => $qrImageUrl,
            'qr_code_expires_at' => $qrExpiresAt,
        ]);

        return response()->json([
            'payment_intent_id' => $intentId,
            'qr_image_url' => $qrImageUrl,
            'expires_at' => $qrExpiresAt,
        ]);
    }

    /**
     * POST /guest-book/payment/qrph/webhook
     *
     * A PayMongo `payment.paid` event's data.attributes.data is a Payment
     * resource, which carries payment_intent_id in ITS attributes — one
     * level deeper than a naive read of the event would expect.
     *
     * DEDUPE: durable, DB-backed instead of cache-based. The old
     * Cache::add() approach marked an event "done" the instant it was
     * first seen — before we knew processing actually succeeded. If
     * anything threw partway through (a DB blip, an unexpected null),
     * the event was permanently marked processed in cache even though the
     * booking was never actually confirmed, and PayMongo's retry would
     * just get swallowed as "Already processed" forever, with no record
     * anything went wrong.
     *
     * Here the claim (insert/lock the events row) and the completion
     * (mark it 'completed') are two separate writes. A crash in between
     * leaves the row as 'processing' or 'failed' — visibly retryable, and
     * durable across cache flushes/restarts — instead of silently lying
     * about success.
     */
    public function webhook(Request $request)
    {
        $signatureHeader = $request->header('Paymongo-Signature', '');
        $payload = $request->getContent();

        if (!$this->verifySignature($payload, $signatureHeader)) {
            Log::warning('PayMongo webhook signature mismatch');
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = json_decode($payload, true);
        $eventType = $event['data']['attributes']['type'] ?? null;
        $eventId = $event['data']['id'] ?? null;

        if ($eventType === 'qrph.expired') {
            return $this->handleExpired($event);
        }

        if ($eventType !== 'payment.paid') {
            // Ack anything else (including events you haven't subscribed to
            // but might receive anyway) so PayMongo doesn't keep retrying it.
            return response()->json(['message' => 'Ignored'], 200);
        }

        if (!$eventId) {
            Log::warning('PayMongo payment.paid webhook missing event id');
            return response()->json(['message' => 'Malformed payload'], 400);
        }

        $eventRowId = $this->claimWebhookEvent('paymongo', $eventId, $eventType, $payload);

        if ($eventRowId === null) {
            // Row existed and was already 'completed' — a true retry of
            // already-finished work. Ack and stop, nothing to do.
            return response()->json(['message' => 'Already processed'], 200);
        }

        try {
            $payment = $event['data']['attributes']['data'] ?? null;
            $intentId = $payment['attributes']['payment_intent_id'] ?? null;

            if (!$intentId) {
                Log::warning('PayMongo payment.paid webhook missing payment_intent_id', [
                    'event_id' => $eventId,
                ]);
                $this->markWebhookEvent($eventRowId, 'failed', 'Missing payment_intent_id');
                return response()->json(['message' => 'Malformed payload'], 400);
            }

            $paymentReference = PaymentReference::where('payment_intent_id', $intentId)->first();

            if (!$paymentReference) {
                Log::warning('PayMongo webhook: no payment_reference found for intent', ['intent_id' => $intentId]);
                $this->markWebhookEvent($eventRowId, 'completed');
                return response()->json(['message' => 'No matching payment reference'], 200);
            }

            // Validate against the full PaymentReference total (supports multi-date)
            $paidAmount = $payment['attributes']['amount'] ?? null;
            $paidCurrency = $payment['attributes']['currency'] ?? null;
            $expectedAmount = (int) round($paymentReference->amount * 100);

            if ($paidAmount !== $expectedAmount || $paidCurrency !== 'PHP') {
                Log::error('PayMongo webhook amount/currency mismatch — bookings NOT marked paid', [
                    'payment_reference_id' => $paymentReference->id,
                    'intent_id' => $intentId,
                    'expected_amount' => $expectedAmount,
                    'paid_amount' => $paidAmount,
                    'paid_currency' => $paidCurrency,
                ]);
                $this->markWebhookEvent($eventRowId, 'failed', 'Amount/currency mismatch');
                return response()->json(['message' => 'Amount mismatch'], 400);
            }

            // Mark ALL pending bookings under this PaymentReference as paid
            $updatedCount = Booking::where('payment_reference_id', $paymentReference->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'paid',
                    'confirmed_at' => now(),
                ]);

            // Also confirm the PaymentReference itself
            if ($updatedCount > 0 && !$paymentReference->isConfirmed()) {
                $paymentReference->update(['confirmed_at' => now()]);
            }

            if ($updatedCount === 0) {
                Log::warning('PayMongo payment.paid but no pending bookings found', [
                    'payment_reference_id' => $paymentReference->id,
                    'intent_id' => $intentId,
                ]);
            }

            return response()->json(['message' => 'OK'], 200);
        } catch (\Throwable $e) {
            $this->markWebhookEvent($eventRowId, 'failed', $e->getMessage());
            Log::error('PayMongo webhook processing failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // 500 so PayMongo retries — row stays 'failed', not falsely 'completed'.
        }
    }

    /**
     * Claim a webhook event for processing in payment_webhook_events.
     *
     * Returns the row id to process, or null if the event was already
     * fully completed (caller should just ack and stop).
     *
     * If a row exists but isn't 'completed' (a prior attempt died
     * mid-flight), it's re-claimed for processing — safe because
     * everything downstream is itself idempotent (guarded pending->paid
     * update, amount check).
     */
    private function claimWebhookEvent(string $provider, string $eventId, ?string $eventType, string $payload): ?int
    {
        return DB::transaction(function () use ($provider, $eventId, $eventType, $payload) {
            $existing = DB::table('payment_webhook_events')
                ->where('provider', $provider)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->status === 'completed') {
                    return null;
                }

                DB::table('payment_webhook_events')
                    ->where('id', $existing->id)
                    ->update(['status' => 'processing', 'updated_at' => now()]);

                return $existing->id;
            }

            return DB::table('payment_webhook_events')->insertGetId([
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'status' => 'processing',
                'payload' => $payload,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function markWebhookEvent(int $rowId, string $status, ?string $error = null): void
    {
        DB::table('payment_webhook_events')
            ->where('id', $rowId)
            ->update([
                'status' => $status,
                'error' => $error,
                'updated_at' => now(),
            ]);
    }

    /**
     * Undo an in-progress claim (see createQr()) after a failed PayMongo
     * call, but only if it's still our claim — if another request or the
     * webhook has since moved the PaymentReference on, leave it alone.
     */
    private function releaseClaim(PaymentReference $paymentReference, string $claimToken): void
    {
        PaymentReference::where('id', $paymentReference->id)
            ->where('payment_intent_id', $claimToken)
            ->update(['payment_intent_id' => null]);
    }

    private function handleExpired(array $event)
    {
        $intentId = $event['data']['attributes']['data']['id'] ?? null;

        if (! $intentId) {
            return response()->json(['message' => 'OK'], 200);
        }

        $cancelled = 0;

        DB::transaction(function () use ($intentId, &$cancelled) {
            // Lock the PaymentReference the same way createQr()/cancelAll() do —
            // if a payment.paid webhook for this same intent is racing this
            // expiry event, whichever transaction commits first wins, and the
            // other sees the already-updated status instead of acting on stale
            // data.
            $paymentReference = PaymentReference::where('payment_intent_id', $intentId)
                ->lockForUpdate()
                ->first();

            if (! $paymentReference) {
                return;
            }

            $siblings = Booking::where('payment_reference_id', $paymentReference->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($siblings as $sibling) {
                $sibling->update(['status' => 'cancelled']);
                $sibling->slots()->update(['is_active' => false]);
                $cancelled++;
            }

            Log::info('PayMongo QR Ph expired — cancelled pending bookings', [
                'payment_reference_id' => $paymentReference->id,
                'intent_id' => $intentId,
                'cancelled_count' => $cancelled,
            ]);
        });

        return response()->json(['message' => 'OK'], 200);
    }

    private function verifySignature(string $payload, string $signatureHeader): bool
    {
        $webhookSecret = config('services.paymongo.webhook_secret');

        parse_str(str_replace(',', '&', $signatureHeader), $parts);
        $timestamp = $parts['t'] ?? null;

        // 'li' carries the signature in Live Mode, 'te' in Test Mode. In
        // Test Mode, 'li' is present in the header but set to an empty
        // string — a bare `??` fallback treats "" as present and never
        // falls through to 'te', so the comparison always fails. Must use
        // !empty() here, not ??.
        $signature = !empty($parts['li']) ? $parts['li'] : ($parts['te'] ?? null);

        if (!$timestamp || !$signature || !$webhookSecret) {
            return false;
        }

        // Reject stale signed payloads. A valid-but-old signature could
        // otherwise be replayed indefinitely by anyone who ever captured
        // one (e.g. from logs, a proxy, or a compromised intermediary).
        $skewSeconds = abs(time() - (int) $timestamp);
        if ($skewSeconds > 300) {
            Log::warning('PayMongo webhook signature rejected: timestamp outside allowed window', [
                'skew_seconds' => $skewSeconds,
            ]);
            return false;
        }

        $signedPayload = "{$timestamp}.{$payload}";
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }
}