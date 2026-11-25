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
        $role = $access->effectiveRole($request->user(), $request);
        $bookingFlowRoutes = [
            'book.index',
            'book.availability',
            'book.equipment-availability',
            'book.status',
            'book.waiting',
            'book.receipt',
            'book.store',
        ];
        $canUseBookingFlow = in_array($request->route()?->getName(), $bookingFlowRoutes, true)
            && in_array($role, ['admin', 'owner', 'staff'], true);

        abort_unless($role === 'user' || $canUseBookingFlow, 403);

        return $next($request);
    }
}
