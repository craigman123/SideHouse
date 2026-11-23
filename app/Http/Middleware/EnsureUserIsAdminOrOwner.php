<?php

namespace App\Http\Middleware;

use App\Services\UserAccessService;
use App\Support\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdminOrOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $access = app(UserAccessService::class);
        $role = $access->effectiveRole($request->user(), $request);
        if (! in_array($role, ['admin', 'owner'], true)) {
            if ($request->routeIs('admin.user-access.page', 'admin.users.*')) {
                ActivityLogger::log('user_access.denied', 'A non-admin and non-owner was denied the user-access route.', actor: $request->user(), properties: [
                    'action' => $request->route()?->getName(),
                    'reason' => 'Only admins and owners can access user management.',
                ]);
            }
            abort(403);
        }

        return $next($request);
    }
}
