<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleIdentity
{
    public static function verifyIdToken(string $idToken, ?string $expectedNonce = null): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) return null;

        $header = self::decodeSegment($parts[0]);
        $claims = self::decodeSegment($parts[1]);
        if (! is_array($header) || ! is_array($claims)) return null;

        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || ($header['alg'] ?? null) !== 'RS256') return null;

        $clientId = config('services.google.client_id');
        if (! is_string($clientId) || $clientId === '') return null;

        $issuer = $claims['iss'] ?? null;
        $audience = $claims['aud'] ?? null;
        $expires = $claims['exp'] ?? null;
        $email = $claims['email'] ?? null;
        $emailVerified = $claims['email_verified'] ?? false;
        $nonce = $claims['nonce'] ?? null;

        if (! in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) return null;
        if (is_array($audience) ? ! in_array($clientId, $audience, true) : ! hash_equals($clientId, (string) $audience)) return null;
        if (! is_numeric($expires) || (int) $expires <= now()->timestamp) return null;
        if (! is_string($email) || $email === '') return null;
        if ($emailVerified !== true && $emailVerified !== 'true') return null;
        if ($expectedNonce !== null && (! is_string($nonce) || ! hash_equals($expectedNonce, $nonce))) return null;

        try {
            $response = Http::timeout(5)->get('https://www.googleapis.com/oauth2/v3/certs');
        } catch (\Throwable) {
            return null;
        }
        if (! $response->ok()) return null;

        $jwk = collect($response->json('keys') ?? [])
            ->first(fn ($key) => ($key['kid'] ?? null) === $kid && ($key['alg'] ?? null) === 'RS256');
        if (! is_array($jwk)) return null;

        $pem = self::jwkToPem($jwk);
        if ($pem === null) return null;
        $signature = self::decodeBase64Url($parts[2]);
        if ($signature === null) return null;
        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) return null;

        $jti = is_string($claims['jti'] ?? null) ? $claims['jti'] : null;
        if ($jti !== null) {
            $key = 'google-id-token:jti:'.hash('sha256', $jti);
            if (! Cache::add($key, true, now()->addMinutes(30))) return null;
        }

        return [
            'email' => mb_strtolower(trim($email)),
            'name' => is_string($claims['name'] ?? null) ? $claims['name'] : null,
            'nonce' => is_string($nonce) ? $nonce : null,
            'jti' => $jti,
        ];
    }

    private static function decodeSegment(string $segment): ?array
    {
        $json = self::decodeBase64Url($segment);
        if ($json === null) return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function decodeBase64Url(string $value): ?string
    {
        $base64 = strtr($value, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        return $decoded === false ? null : $decoded;
    }

    private static function jwkToPem(array $jwk): ?string
    {
        $n = self::decodeBase64Url($jwk['n'] ?? '');
        $e = self::decodeBase64Url($jwk['e'] ?? '');
        if ($n === null || $e === null) return null;
        $nHex = self::positiveHex($n);
        $eHex = self::positiveHex($e);
        $rsaSequence = self::derSequence(self::derInteger($nHex).self::derInteger($eHex));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $bitString = "\x03".self::derLength(strlen($rsaSequence) + 1)."\x00".$rsaSequence;
        $sequence = self::derSequence($algorithm.$bitString);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($sequence), 64, "\n")."-----END PUBLIC KEY-----\n";
        return @openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    private static function positiveHex(string $bytes): string
    {
        $hex = bin2hex($bytes);
        if (strlen($hex) === 0) return '00';
        if (strlen($hex) % 2) $hex = '0'.$hex;
        if (hexdec(substr($hex, 0, 2)) > 127) $hex = '00'.$hex;
        return $hex;
    }

    private static function derInteger(string $hex): string
    {
        $bytes = hex2bin($hex);
        return "\x02".self::derLength(strlen($bytes)).$bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30".self::derLength(strlen($content)).$content;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) return chr($length);
        $hex = dechex($length);
        if (strlen($hex) % 2) $hex = '0'.$hex;
        $bytes = hex2bin($hex);
        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
