<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Shared verification for the two SMS-forwarding webhooks (GCash,
 * Landbank).
 *
 * Both used to accept the shared secret as a `?token=` query-string
 * parameter. That's a real exposure: query strings routinely end up
 * somewhere a secret shouldn't be — web server access logs, reverse
 * proxy / CDN logs, any APM or uptime-monitoring tool that records full
 * request URLs, browser history if anyone ever opens the link directly.
 * A header isn't captured by any of those by default.
 *
 * True replay protection normally means a signature over (timestamp +
 * body) that the caller computes fresh per request — but the caller
 * here is a consumer SMS-forwarding app (e.g. an Android "SMS Forwarder"
 * app), which can only be configured to POST a fixed URL/header/body
 * template. It can't compute an HMAC per request. So the practical
 * defense against replay is content-based: if the exact same SMS body
 * was already processed inside the relevant matching window, drop it.
 * See isDuplicate().
 *
 * If the SMS-forwarding app on your device supports custom headers
 * (most do — "SMS Forwarder", Tasker, MacroDroid, etc.), point it at:
 *   Header: X-Webhook-Token: <same secret you'd have put in ?token=>
 * instead of appending ?token=... to the URL. The query-string param is
 * still accepted as a fallback so existing device configs don't break
 * the moment this ships, but treat it as deprecated — migrate the
 * device config to the header and then remove the fallback below.
 */
class WebhookAuth
{
    public static function verifyToken(Request $request, string $configuredSecret): bool
    {
        if ($configuredSecret === '') {
            return false;
        }

        $provided = $request->header('X-Webhook-Token');
        if (! is_string($provided) || $provided === '') {
            // Deprecated fallback — see class docblock.
            $provided = (string) $request->query('token', '');
        }

        return $provided !== '' && hash_equals($configuredSecret, $provided);
    }

    /**
     * Optional IP allow-list check. Only enforced if $configuredCsv is
     * non-empty, so it's opt-in via config/env — skip entirely for
     * devices on dynamic IPs.
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
     * True if this exact SMS body was already processed within the
     * given window (forwarder retried a POST that actually succeeded, a
     * proxy resent it, a captured request got replayed, etc). Marks it
     * as seen for the rest of the window as a side effect.
     */
    public static function isDuplicate(string $cacheKeyPrefix, string $rawMessage, int $windowMinutes): bool
    {
        $key = $cacheKeyPrefix . ':' . hash('sha256', $rawMessage);

        if (Cache::has($key)) {
            return true;
        }

        Cache::put($key, true, now()->addMinutes($windowMinutes));

        return false;
    }
}