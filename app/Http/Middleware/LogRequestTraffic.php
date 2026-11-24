<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Illuminate\Support\Facades\Cache;

class LogRequestTraffic
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);

        try {
            if (! $request->routeIs('admin.reports.system', 'admin.widgets.metrics')) {
                $minute = (int) floor(microtime(true) / 60);
                $status = $response->getStatusCode();
                $durationMs = max(0, (microtime(true) - $startedAt) * 1000);
                $prefix = "system-monitor:traffic:{$minute}";

                Cache::add("{$prefix}:requests", 0, now()->addMinutes(6));
                Cache::increment("{$prefix}:requests");
                Cache::add("{$prefix}:duration-sum", 0, now()->addMinutes(6));
                Cache::increment("{$prefix}:duration-sum", (int) round($durationMs));
                Cache::add("{$prefix}:duration-count", 0, now()->addMinutes(6));
                Cache::increment("{$prefix}:duration-count");

                if ($status >= 400) {
                    Cache::add("{$prefix}:errors", 0, now()->addMinutes(6));
                    Cache::increment("{$prefix}:errors");
                }
            }
        } catch (Throwable $e) {
            // Traffic collection must never affect application responses.
        }

        return $response;
    }
}
