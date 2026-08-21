<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Shared verification for the two SMS-forwarding webhooks.
 *
 * The forwarding device must send the configured secret in the
 * X-Webhook-Token header. Tokens in query strings are intentionally not
 * accepted because URLs are commonly retained in access, proxy, and APM
 * logs. This is still a shared-secret integration: use a provider-signed
 * webhook or payment-provider API when one becomes available.
 */
class WebhookAuth
{
    public static function verifyToken(Request $request, string $configuredSecret): bool
    {
        if ($configuredSecret === '') {
            return false;
        }

        $provided = $request->header('X-Webhook-Token');

        return is_string($provided) && $provided !== ''
            && hash_equals($configuredSecret, $provided);
    }

    /**
     * Optional IP allow-list check. It is enforced only when configured,
     * allowing SMS-forwarding devices with dynamic IP addresses.
     */
    public static function verifyIp(Request $request, string $configuredCsv): bool
    {
        $configuredCsv = trim($configuredCsv);
        if ($configuredCsv === '') {
            return true;
        }

        $allowed = array_filter(array_map('trim', explode(',', $configuredCsv)));

        return in_array($request->ip(), $allowed, true);
    }

    /**
     * Atomically records an SMS body hash and reports whether it was seen
     * during the matching window. This prevents a concurrent retry from
     * passing a separate cache has()/put() race.
     */
    public static function isDuplicate(string $cacheKeyPrefix, string $rawMessage, int $windowMinutes): bool
    {
        $key = $cacheKeyPrefix . ':' . hash('sha256', $rawMessage);

        return ! Cache::add($key, true, now()->addMinutes($windowMinutes));
    }
}
