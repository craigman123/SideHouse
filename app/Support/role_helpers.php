<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

if (! function_exists('role_route')) {
    function role_route(string $baseName, array $parameters = []): string
    {
        if (! config('auth.multi_guard')) {
            return route($baseName, $parameters);
        }

        $guard = Auth::getDefaultDriver();
        if ($baseName === 'logout') {
            $routeName = match ($guard) {
                'admin', 'owner', 'staff' => $guard.'.logout',
                'customer' => 'logout',
                default => throw new LogicException("Role-scoped routes are not available for guard [{$guard}]."),
            };

            if (! Route::has($routeName)) {
                throw new LogicException("Route [{$routeName}] is not available for guard [{$guard}].");
            }

            return route($routeName, $parameters);
        }

        [$baseRole, $routeSuffix] = array_pad(explode('.', $baseName, 2), 2, null);
        if ($baseRole !== 'admin' || $routeSuffix === null) {
            throw new InvalidArgumentException('Role-scoped route names must use an admin base route name.');
        }

        if (! in_array($guard, ['admin', 'owner', 'staff'], true)) {
            throw new LogicException("Role-scoped routes are not available for guard [{$guard}].");
        }

        $routeName = $guard.'.'.$routeSuffix;
        if (! Route::has($routeName)) {
            throw new LogicException("Route [{$routeName}] is not available for guard [{$guard}].");
        }

        return route($routeName, $parameters);
    }
}

if (! function_exists('mfa_route_name')) {
    function mfa_route_name(string $routeSuffix, ?string $guard = null): string
    {
        if (! config('auth.multi_guard')) {
            return $routeSuffix;
        }

        $guard ??= Auth::getDefaultDriver();
        if (! in_array($guard, ['admin', 'owner', 'staff', 'customer'], true)) {
            throw new LogicException("MFA routes are not available for guard [{$guard}].");
        }

        $routeName = ($guard === 'customer' ? '' : $guard.'.').$routeSuffix;
        if (! Route::has($routeName)) {
            throw new LogicException("Route [{$routeName}] is not available for guard [{$guard}].");
        }

        return $routeName;
    }
}

if (! function_exists('role_home_url')) {
    function role_home_url(): string
    {
        $routeName = match (Auth::getDefaultDriver()) {
            'admin' => 'admin.dashboard',
            'owner' => 'owner.dashboard',
            'staff' => 'staff.dashboard',
            'customer' => 'user.dashboard',
            default => 'landing',
        };

        return route($routeName);
    }
}
