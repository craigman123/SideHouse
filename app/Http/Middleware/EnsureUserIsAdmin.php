<?php

namespace App\Http\Middleware;

use App\Services\UserAccessService;
use App\Support\ActivityLogger;
use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $access = app(UserAccessService::class);
        $role = $request->user() ? $access->effectiveRole($request->user(), $request) : null;
        $ownerRedirectRoutes = [
            'admin.dashboard', 'bookings.index', 'courts.index', 'admin.configuration.index',
            'admin.profile', 'admin.equipment.availability', 'admin.customers.index',
            'admin.announcements.index', 'admin.announcements.create', 'admin.reports.index',
            'activity_logs.index',
        ];
        $ownerNeedsRedirect = $role === 'owner' && $request->routeIs(...$ownerRedirectRoutes);

        if (!$request->user() || ($role !== 'admin' && ! $ownerNeedsRedirect)) {
            if ($request->routeIs('admin.user-access.page', 'admin.users.*')) {
                ActivityLogger::log('user_access.denied', 'A non-admin was denied the admin user-access route.', actor: $request->user(), properties: [
                    'action' => $request->route()?->getName(), 'reason' => 'Only admins and owners can access user management.',
                ]);
            }
            abort(403);
        }
        return $next($request);
    }
}
