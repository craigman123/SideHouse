<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

class RoleAwareAuthenticate extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        if (config('auth.multi_guard') && $guards === [] && $request instanceof Request) {
            $route = $request->route();
            $guard = $route ? app(SelectRoleGuard::class)->selectedGuardForRoute($request, $route) : null;

            if ($guard !== null) {
                $guards = [$guard];
            }
        }

        return parent::handle($request, $next, ...$guards);
    }

    protected function redirectTo(Request $request): ?string
    {
        if (config('auth.multi_guard') && ($route = $request->route())) {
            $guard = app(SelectRoleGuard::class)->selectedGuardForRoute($request, $route);

            if ($guard !== null) {
                return route($guard === 'customer' ? 'login' : $guard.'.login');
            }
        }

        return parent::redirectTo($request);
    }
}
