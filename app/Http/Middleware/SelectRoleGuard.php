<?php

namespace App\Http\Middleware;

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
    ];

    private const SHARED_AUTH_ROUTES = [
        'login',
        'login.submit',
        'register',
        'register.submit',
        'auth.google',
        'logout',
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

        $guard = $this->guardForRoute($route);
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

    private function middlewareNames(array $middleware): array
    {
        return array_map(
            static fn (string $name): string => explode(':', $name, 2)[0],
            $middleware,
        );
    }
}
