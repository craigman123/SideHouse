<?php

namespace App\Http\Middleware;

use App\Services\UserAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCreditGiver
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        if (! $actor) {
            abort(403);
        }

        $access = app(UserAccessService::class);
        $realRole = $access->realRole($actor);
        $allowed = $realRole === 'owner'
            || ($realRole === 'admin' && $access->isRealAdmin($actor, $request));

        if (! $allowed) {
            abort(403);
        }

        return $next($request);
    }
}
