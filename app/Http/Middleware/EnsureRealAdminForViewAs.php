<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRealAdminForViewAs
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user()
            ? User::query()->find($request->user()->user_id)
            : null;

        if ($actor?->role !== 'admin') {
            ActivityLogger::log(
                'view_as.denied',
                'A non-admin account was denied a view-as request.',
                actor: $actor,
                properties: [
                    'action' => $request->route()?->getName(),
                    'real_role' => $actor?->role,
                    'reason' => 'Only a real admin can switch access views.',
                ],
            );
            abort(403, 'Only a real admin can switch access views.');
        }

        return $next($request);
    }
}
