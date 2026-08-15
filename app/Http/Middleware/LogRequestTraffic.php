<?php

namespace App\Http\Middleware;

use App\Models\RequestLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lightweight traffic counter backing the admin Reports "System Health"
 * panel. Logs one row per request. The health panel polls
 * admin.reports.system on its own timer — that route is excluded here
 * so its own background polling doesn't inflate the traffic numbers
 * it's displaying.
 */
class LogRequestTraffic
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (! $request->routeIs('admin.reports.system')) {
                RequestLog::create([
                    'method' => $request->method(),
                    'path' => '/' . ltrim($request->path(), '/'),
                    'status' => $response->getStatusCode(),
                    'ip_address' => $request->ip(),
                    'user_id' => optional($request->user())->id,
                ]);
            }
        } catch (Throwable $e) {
            // Traffic logging should never break the actual request.
        }

        return $response;
    }
}
