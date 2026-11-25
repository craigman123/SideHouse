<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

if (! function_exists('role_route')) {
    function role_route(string $baseName, array $parameters = []): string
    {
        if (! config('auth.multi_guard')) {
            return route($baseName, $parameters);
        }

        [$baseRole, $routeSuffix] = array_pad(explode('.', $baseName, 2), 2, null);
        if ($baseRole !== 'admin' || $routeSuffix === null) {
            throw new InvalidArgumentException('Role-scoped route names must use an admin base route name.');
        }

        $guard = Auth::getDefaultDriver();
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
