<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
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
            'token' => ['required', 'string'],
        ]);

        $booking = Booking::findOrFail($validated['booking_id']);

        if (!$booking->poll_token || !hash_equals($booking->poll_token, $validated['token'])) {
            abort(403);
        }

        if ($booking->status !== 'pending') {
            return response()->json(['message' => 'This booking is no longer awaiting payment.'], 409);
        }

        if ($booking->payment_intent_id) {
            // A QR was already generated for this booking. Don't create a
            // second PayMongo intent — that would orphan the first one if
            // the guest already scanned it.
            return response()->json([
                'message' => 'A payment QR already exists for this booking.',
                'payment_intent_id' => $booking->payment_intent_id,
            ], 409);
        }

        $amountCentavos = (int) round($booking->amount * 100);
        $secretKey = config('services.paymongo.secret');

        if (!$secretKey) {
            // Http::withBasicAuth()'s $username param is strictly typed
            // string — passing null here throws an uncaught TypeError,
            // which is what was surfacing as a bare, message-less 500 on
            // the client instead of a real error. Catch it here instead.
            Log::error('PayMongo secret key is not configured (services.paymongo.secret / PAYMONGO_SECRET_KEY).');
            return response()->json(['message' => 'Payment is not configured yet. Please contact the venue.'], 502);
        }

        $intentResponse = Http::withBasicAuth($secretKey, '')
            ->post(self::API_BASE . '/payment_intents', [
                'data' => [
                    'attributes' => [
                        'amount' => $amountCentavos,
                        'currency' => 'PHP',
                        'payment_method_allowed' => ['qrph'],
                        'description' => "Side House Paddlers — Booking #{$booking->id}",
                        'metadata' => [
                            'booking_id' => (string) $booking->id,
                        ],
                    ],
                ],
            ]);

        if ($intentResponse->failed()) {
            Log::error('PayMongo payment_intents create failed', [
                'status' => $intentResponse->status(),
                'booking_id' => $booking->id,
            ]);
            return response()->json(['message' => 'Could not start payment. Please try again.'], 502);
        }

        $intent = $intentResponse->json('data');
        $intentId = $intent['id'];
        $clientKey = $intent['attributes']['client_key'];

        $methodResponse = Http::withBasicAuth($secretKey, '')
            ->post(self::API_BASE . '/payment_methods', [
                'data' => ['attributes' => ['type' => 'qrph']],
            ]);

        if ($methodResponse->failed()) {
            Log::error('PayMongo payment_methods create failed', [
                'status' => $methodResponse->status(),
                'booking_id' => $booking->id,
            ]);
            return response()->json(['message' => 'Could not start payment. Please try again.'], 502);
        }

        $methodId = $methodResponse->json('data.id');

        $attachResponse = Http::withBasicAuth($secretKey, '')
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
                'booking_id' => $booking->id,
                'intent_id' => $intentId,
            ]);
            return response()->json(['message' => 'Could not start payment. Please try again.'], 502);
        }

        $attached = $attachResponse->json('data');

        $qrImageUrl = $attached['attributes']['next_action']['code']['image_url'] ?? null;
        $qrExpiresAt = $attached['attributes']['next_action']['code']['expires_at'] ?? null;

        if (!$qrImageUrl) {
            Log::error('PayMongo attach succeeded but no QR image returned', [
                'booking_id' => $booking->id,
                'intent_id' => $intentId,
            ]);
            return response()->json(['message' => 'Could not generate QR. Please try again.'], 502);
        }

        // The critical line that was a TODO before — this is what lets the
        // webhook below find the right booking by an exact id, no matching
        // heuristics needed.
        $booking->update(['payment_intent_id' => $intentId]);

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

        if ($eventType === 'qrph.expired') {
            return $this->handleExpired($event);
        }

        if ($eventType !== 'payment.paid') {
            // Ack anything else (including events you haven't subscribed to
            // but might receive anyway) so PayMongo doesn't keep retrying it.
            return response()->json(['message' => 'Ignored'], 200);
        }

        $payment = $event['data']['attributes']['data'] ?? null;
        $intentId = $payment['attributes']['payment_intent_id'] ?? null;

        if (!$intentId) {
            Log::warning('PayMongo payment.paid webhook missing payment_intent_id', [
                'event_id' => $event['data']['id'] ?? null,
            ]);
            return response()->json(['message' => 'Malformed payload'], 400);
        }

        $booking = Booking::where('payment_intent_id', $intentId)->first();

        if (!$booking) {
            // Genuinely shouldn't happen in the qrph flow since the intent
            // is only ever created for an existing booking — but log it
            // rather than silently dropping it, in case of a race or a
            // manually-created test intent.
            Log::warning('PayMongo webhook: no booking found for intent', ['intent_id' => $intentId]);
            return response()->json(['message' => 'No matching booking'], 200);
        }

        // Atomic, guarded update: only ever transitions pending -> paid.
        // This prevents a delayed/retried webhook from flipping a booking
        // that's since been cancelled (or expired) back to paid.
        $updated = Booking::where('id', $booking->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'paid',
                'confirmed_at' => now(),
            ]);

        if (!$updated && $booking->status !== 'paid') {
            // Booking existed but wasn't pending, and wasn't already paid
            // either — a payment came in for a booking that's no longer
            // valid (e.g. cancelled). Don't silently flip it; log it so it
            // can be manually reviewed/refunded.
            Log::warning('PayMongo payment.paid for non-pending booking', [
                'booking_id' => $booking->id,
                'current_status' => $booking->status,
                'intent_id' => $intentId,
            ]);
        }

        return response()->json(['message' => 'OK'], 200);
    }

    private function handleExpired(array $event)
    {
        $intentId = $event['data']['attributes']['data']['id'] ?? null;

        if ($intentId) {
            $booking = Booking::where('payment_intent_id', $intentId)
                ->where('status', 'pending')
                ->first();

            // Leave the row alone — your existing
            // bookings:expire-unconfirmed-* scheduled commands already
            // sweep up stale pending bookings. This just logs it so you
            // can see expired QR Ph attempts distinctly if useful later.
            if ($booking) {
                Log::info('PayMongo QR Ph expired for booking', ['booking_id' => $booking->id]);
            }
        }

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

        $signedPayload = "{$timestamp}.{$payload}";
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }
}