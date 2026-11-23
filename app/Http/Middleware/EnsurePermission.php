<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserAccessService;
use App\Support\ActivityLogger;
use Closure;
use Illuminate\Http\Request;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission, string ...$alternates)
    {
        $authenticated = $request->user();
        $user = $authenticated ? User::query()->find($authenticated->user_id) : null;

        $allowed = $user && app(UserAccessService::class)->allowsAny($user, [$permission, ...$alternates]);
        if (! $allowed && $user && in_array($permission, ['user_access.manage', 'user_details.edit'], true)) {
            ActivityLogger::log('user_access.denied', "{$user->name} was denied user management access.", actor: $user, properties: [
                'action' => $request->route()?->getName(), 'permission' => $permission, 'reason' => 'The account role cannot perform this action.',
            ]);
        }
        abort_unless($allowed, 403, 'You do not have permission to manage user access.');

        return $next($request);
    }
}
