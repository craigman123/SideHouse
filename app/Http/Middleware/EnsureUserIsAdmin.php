<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->query('debug_admin') === '1') {
            return response()->json([
                'has_user' => (bool) $request->user(),
                'user_id' => $request->user()?->user_id,
                'role' => $request->user()?->role,
                'comparison_result' => $request->user()?->role !== 'admin',
            ]);
        }

        if (!$request->user() || $request->user()->role !== 'admin') {
            abort(403);
        }
        return $next($request);
    }
}