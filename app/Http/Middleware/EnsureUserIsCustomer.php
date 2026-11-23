<?php

namespace App\Http\Middleware;

use App\Services\UserAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $access = app(UserAccessService::class);
        abort_unless($access->effectiveRole($request->user(), $request) === 'user', 403);

        return $next($request);
    }
}
