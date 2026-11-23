<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\UserAccessService;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $access = app(UserAccessService::class);
        abort_unless($access->effectiveRole($request->user(), $request) === 'staff', 403);

        return $next($request);
    }
}
