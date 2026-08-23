<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles the PayMongo QR Ph flow for a booking:
 *
 *   1. createQr()  — called via AJAX right after the guest picks "QR Ph" as
 *      their payment method (same moment gcashQrPanel/landbankQrPanel would
 *      normally open). Creates a PayMongo Payment Intent for the booking's
 *      amount, attaches the qrph payment method, and returns the QR image
 *      URL + expiry back to the frontend to render inline.
 *
 *   2. webhook()   — PayMongo POSTs here when the Payment Intent succeeds.
 *      We verify the signature, look up the booking by the payment_intent_id
 *      we stored on it, and mark it 'paid' — mirroring how your existing
 *      GcashWebhookController/LandbankWebhookController mark bookings paid,
 *      so watchPaymentConfirmation()'s polling picks it up unchanged.
 *
 * Env vars needed (add to .env once your mom's PayMongo account is verified):
 *   PAYMONGO_SECRET_KEY=sk_test_xxx      (server-side only, never expose)
 *   PAYMONGO_WEBHOOK_SECRET=whsk_xxx     (from Dashboard > Developers > Webhooks)
 */
class PaymongoQrPhController extends Controller
{
    private const API_BASE = 'https://api.paymongo.com/v1';

    /**
     * POST /guest-book/payment/qrph
     *
     * Expects: booking amount context — adjust to however your flow already
     * knows the total (e.g. re-derive server-side from slots/equipment
     * rather than trusting a client-sent amount, same as your store() logic
     * presumably already does for gcash/landbank).
     */
    public function createQr(Request $request)
    {
        $validated = $request->validate([
            // Whatever you use today to identify "this in-progress booking"
            // before it's persisted — e.g. a draft/session key. If your
            // flow only creates the Booking row inside store(), you may
            // instead want to call createQr() *after* store() creates a
            // 'pending' booking, passing booking_id here instead.
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $amountCentavos = (int) round($validated['amount'] * 100);

        $secretKey = config('services.paymongo.secret');

        // 1. Create the Payment Intent
        $intentResponse = Http::withBasicAuth($secretKey, '')
            ->post(self::API_BASE . '/payment_intents', [
                'data' => [
                    'attributes' => [
                        'amount' => $amountCentavos,
                        'currency' => 'PHP',
                        'payment_method_allowed' => ['qrph'],
                        'description' => $validated['description'] ?? 'Side House Paddlers court booking',
                        'metadata' => [
                            // Anything that helps you reconcile in the webhook —
                            // e.g. a booking draft token you generate client-side.
                            'source' => 'guest-book',
                        ],
                    ],
                ],
            ]);

        if ($intentResponse->failed()) {
            Log::error('PayMongo payment_intents create failed', ['body' => $intentResponse->body()]);
            return response()->json(['message' => 'Could not start payment. Please try again.'], 502);
        }

        $intent = $intentResponse->json('data');
        $intentId = $intent['id'];
        $clientKey = $intent['attributes']['client_key'];

        // 2. Create + attach a qrph Payment Method to that intent — this is
        //    what actually generates the scannable QR code image.
        $methodResponse = Http::withBasicAuth($secretKey, '')
            ->post(self::API_BASE . '/payment_methods', [
                'data' => [
                    'attributes' => [
                        'type' => 'qrph',
                    ],
                ],
            ]);

        if ($methodResponse->failed()) {
            Log::error('PayMongo payment_methods create failed', ['body' => $methodResponse->body()]);
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
            Log::error('PayMongo attach failed', ['body' => $attachResponse->body()]);
            return response()->json(['message' => 'Could not start payment. Please try again.'], 502);
        }

        $attached = $attachResponse->json('data');
        $qrImageUrl = $attached['attributes']['next_action']['code']['image_url'] ?? null;
        $qrExpiresAt = $attached['attributes']['next_action']['code']['expires_at'] ?? null; // unix timestamp

        if (!$qrImageUrl) {
            Log::error('PayMongo attach succeeded but no QR image returned', ['body' => $attachResponse->body()]);
            return response()->json(['message' => 'Could not generate QR. Please try again.'], 502);
        }

        // TODO: persist $intentId against your booking/draft row here so the
        // webhook below can find it later, e.g.:
        //   $booking->update(['payment_intent_id' => $intentId]);

        return response()->json([
            'payment_intent_id' => $intentId,
            'qr_image_url' => $qrImageUrl,
            'expires_at' => $qrExpiresAt,
        ]);
    }

    /**
     * POST /guest-book/payment/qrph/webhook
     *
     * Register this URL in PayMongo Dashboard > Developers > Webhooks,
     * subscribed to payment_intent.succeeded (and optionally
     * payment_intent.payment_failed to auto-release the slot early instead
     * of waiting for your existing expiry job).
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

        if ($eventType !== 'payment_intent.succeeded') {
            // Ack anything else so PayMongo doesn't retry it forever.
            return response()->json(['message' => 'Ignored'], 200);
        }

        $intentId = $event['data']['attributes']['data']['id'] ?? null;
        if (!$intentId) {
            return response()->json(['message' => 'Malformed payload'], 400);
        }

        $booking = Booking::where('payment_intent_id', $intentId)->first();

        if (!$booking) {
            // Same "unmatched payment" situation your GcashWebhookController
            // already handles — the payment arrived before/without a
            // matching booking row. Mirror whatever UnmatchedPayment claim
            // logic store() uses for gcash/landbank here if you want the
            // same retroactive-match behavior for QR Ph.
            Log::info('PayMongo webhook: no booking found for intent', ['intent_id' => $intentId]);
            return response()->json(['message' => 'No matching booking'], 200);
        }

        if ($booking->status !== 'paid') {
            $booking->update(['status' => 'paid']);
        }

        return response()->json(['message' => 'OK'], 200);
    }

    private function verifySignature(string $payload, string $signatureHeader): bool
    {
        $webhookSecret = config('services.paymongo.webhook_secret');

        // Paymongo-Signature header format: "t=timestamp,te=test_sig,li=live_sig"
        parse_str(str_replace(',', '&', $signatureHeader), $parts);
        $timestamp = $parts['t'] ?? null;
        $signature = $parts['li'] ?? ($parts['te'] ?? null); // use 'te' while testing, 'li' once live

        if (!$timestamp || !$signature) {
            return false;
        }

        $signedPayload = "{$timestamp}.{$payload}";
        $expectedSignature = hash_hmac('sha256', $signedPayload, $webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }
}
