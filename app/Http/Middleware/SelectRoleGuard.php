<?php

namespace App\Http\Middleware;

use App\Support\GuardSessionState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class SelectRoleGuard
{
    private const ROLE_GUARDS = [
        'admin' => 'admin',
        'real-admin' => 'admin',
        'real-admin.view-as' => 'admin',
        'owner.only' => 'owner',
        'staff' => 'staff',
        'customer.only' => 'customer',
        'customer.mfa' => 'customer',
    ];

    private const SHARED_AUTH_ROUTES = [
        'login',
        'login.submit',
        'register',
        'register.submit',
        'auth.google',
        'logout',
        'admin.login',
        'admin.logout',
        'owner.login',
        'owner.logout',
        'staff.login',
        'staff.logout',
        'mfa.setup',
        'mfa.setup.init',
        'mfa.enable',
        'mfa.challenge',
        'mfa.verify',
        'admin.mfa.setup',
        'admin.mfa.setup.init',
        'admin.mfa.enable',
        'admin.mfa.challenge',
        'admin.mfa.verify',
        'owner.mfa.setup',
        'owner.mfa.setup.init',
        'owner.mfa.enable',
        'owner.mfa.challenge',
        'owner.mfa.verify',
        'staff.mfa.setup',
        'staff.mfa.setup.init',
        'staff.mfa.enable',
        'staff.mfa.challenge',
        'staff.mfa.verify',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.multi_guard')) {
            return $next($request);
        }

        $route = $request->route();
        if (! $route) {
            return $next($request);
        }

        $guard = $this->selectedGuardForRoute($request, $route);
        if ($guard !== null) {
            Auth::shouldUse($guard);

            return $next($request);
        }

        $middleware = $this->middlewareNames($route->gatherMiddleware());
        if (in_array('auth', $middleware, true) || in_array($route->getName(), self::SHARED_AUTH_ROUTES, true)) {
            $routeName = $route->getName() ?: $request->path();
            throw new LogicException("No role guard mapping is configured for route [{$routeName}].");
        }

        return $next($request);
    }

    public function guardForRoute(Route $route): ?string
    {
        $guards = [];

        $roleGuard = $route->defaults['_role_guard'] ?? null;
        if ($roleGuard !== null) {
            if (! in_array($roleGuard, ['admin', 'owner', 'staff', 'customer'], true)) {
                $routeName = $route->getName() ?: $route->uri();
                throw new LogicException("Route [{$routeName}] has an invalid role guard marker.");
            }

            $guards[] = $roleGuard;
        }

        foreach ($this->middlewareNames($route->gatherMiddleware()) as $middleware) {
            if (isset(self::ROLE_GUARDS[$middleware])) {
                $guards[] = self::ROLE_GUARDS[$middleware];
            }
        }

        $guards = array_values(array_unique($guards));
        if (count($guards) > 1) {
            $routeName = $route->getName() ?: $route->uri();
            throw new LogicException("Route [{$routeName}] has conflicting role guard groups.");
        }

        return $guards[0] ?? null;
    }

    public function selectedGuardForRoute(Request $request, Route $route): ?string
    {
        $guard = $this->guardForRoute($route);
        if ($guard === null || $guard === 'admin') {
            return $guard;
        }

        if (Auth::guard($guard)->check()) {
            return $guard;
        }

        $viewAs = $request->hasSession()
            ? $request->session()->get(GuardSessionState::key('view_as', 'admin'))
            : null;
        if ($viewAs === $guard && Auth::guard('admin')->check()) {
            return 'admin';
        }

        return $guard;
    }

    private function middlewareNames(array $middleware): array
    {
        return array_map(
            static fn (string $name): string => explode(':', $name, 2)[0],
            $middleware,
        );
    }
}
