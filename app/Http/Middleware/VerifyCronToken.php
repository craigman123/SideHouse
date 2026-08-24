<?php

// app/Http/Middleware/VerifyCronToken.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyCronToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('services.cron.token');
        $provided = $request->bearerToken(); // reads "Authorization: Bearer <token>"

        if (!$expected || !$provided || !hash_equals($expected, $provided)) {
            abort(403);
        }

        return $next($request);
    }
}