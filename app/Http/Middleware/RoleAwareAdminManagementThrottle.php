<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class RoleAwareAdminManagementThrottle
{
    private const PASSWORD_CONFIRMED_ROUTES = [
        'admin.users.store',
        'admin.users.update',
        'admin.users.remove-access',
        'admin.users.details.update',
    ];

    public function __construct(
        private readonly UserAccessService $userAccess,
    ) {
    }

    public function handle(Request $request, Closure $next, string $maxAttempts = '60', string $decayMinutes = '1'): Response
    {
        $user = $request->user();
        $role = $user instanceof User ? $this->userAccess->effectiveRole($user, $request) : null;
        $isAdminManagement = in_array($role, ['admin', 'owner'], true)
            && ! in_array($request->route()?->getName(), self::PASSWORD_CONFIRMED_ROUTES, true);

        return app(ThrottleRequests::class)->handle(
            $request,
            $next,
            $isAdminManagement ? (string) ((int) $maxAttempts * 5) : $maxAttempts,
            $decayMinutes,
            $isAdminManagement ? 'admin-mgmt:' : '',
        );
    }
}
