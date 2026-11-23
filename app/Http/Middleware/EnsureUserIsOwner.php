<?php

namespace App\Http\Middleware;

use App\Support\ActivityLogger;
use App\Services\UserAccessService;
use Closure;
use Illuminate\Http\Request;

class EnsureUserIsOwner
{
    public function handle(Request $request, Closure $next)
    {
        $access = app(UserAccessService::class);
        if ($access->effectiveRole($request->user(), $request) !== 'owner') {
            if ($request->routeIs('owner.user-access')) {
                ActivityLogger::log('user_access.denied', 'A non-owner was denied the owner user-access page.', actor: $request->user(), properties: [
                    'action' => 'owner.user-access', 'reason' => 'Only owners can open this page.',
                ]);
            }
            abort(403, 'Only owners can open this page.');
        }

        return $next($request);
    }
}
