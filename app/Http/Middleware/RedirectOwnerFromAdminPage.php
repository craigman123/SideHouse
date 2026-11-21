<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectOwnerFromAdminPage
{
    private const OWNER_ROUTES = [
        'admin.dashboard' => 'owner.dashboard',
        'bookings.index' => 'owner.bookings',
        'courts.index' => 'owner.courts',
        'admin.configuration.index' => 'owner.configuration',
        'admin.profile' => 'owner.profile',
        'admin.equipment.availability' => 'owner.equipment',
        'admin.customers.index' => 'owner.customers',
        'admin.user-access.page' => 'owner.user-access',
        'admin.announcements.index' => 'owner.announcements',
        'admin.announcements.create' => 'owner.announcements',
        'admin.reports.index' => 'owner.reports',
        'activity_logs.index' => 'owner.activity-logs',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->role === 'owner') {
            $destination = self::OWNER_ROUTES[$request->route()?->getName() ?? ''] ?? null;
            if ($destination !== null) {
                return redirect()->route($destination, $request->query());
            }
        }

        return $next($request);
    }
}
