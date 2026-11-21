<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserAccessService;
use Closure;
use Illuminate\Http\Request;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $authenticated = $request->user();
        $user = $authenticated ? User::query()->find($authenticated->user_id) : null;

        abort_unless($user && app(UserAccessService::class)->allows($user, $permission), 403);

        return $next($request);
    }
}
