<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Verifies a Google Identity Services ID token and returns the claims we
 * actually trust — or null if the token is missing, expired, unverified,
 * or wasn't issued for this site's OAuth client.
 *
 * Uses the tokeninfo endpoint (rather than a JWKS/signature library) to
 * avoid adding a dependency; it's an extra network round trip per call,
 * which is fine at this app's volume. If that ever becomes a bottleneck,
 * swap this for google/apiclient's Google_Client::verifyIdToken(), which
 * checks the signature locally.
 *
 * Shared by GuestBookingController (email confirmation at checkout) and
 * AuthController (Google sign-in for login/register) — previously
 * duplicated between them.
 */
class GoogleIdentity
{
    /**
     * @return array{email: string, name: ?string}|null
     */
    public static function verifyIdToken(string $idToken): ?array
    {
        $clientId = config('services.google.client_id');
        if (! $clientId) {
            return null;
        }

        try {
            $response = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $claims = $response->json();

        if (! is_array($claims) || empty($claims['email']) || empty($claims['aud'])) {
            return null;
        }

        // aud must match our OAuth client — otherwise this is a token
        // issued for a completely different Google app.
        if (! hash_equals($clientId, (string) $claims['aud'])) {
            return null;
        }

        // Google sends this as the string "true"/"false", not a boolean.
        $emailVerified = ($claims['email_verified'] ?? 'false') === 'true'
            || ($claims['email_verified'] ?? false) === true;

        if (! $emailVerified) {
            return null;
        }

        return [
            'email' => $claims['email'],
            'name'  => $claims['name'] ?? null,
        ];
    }
}
