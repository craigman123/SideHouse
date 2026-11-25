<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccessVersion;
use App\Http\Middleware\SelectRoleGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Tests\TestCase;

class SelectRoleGuardTest extends TestCase
{
    private const SHARED_AUTHENTICATED_ROUTES = [
        'admin.blocks.destroy',
        'admin.blocks.store',
        'admin.blocks.update',
        'admin.bookings.calendar',
        'admin.bookings.update',
        'admin.bookings.walk-in.store',
        'admin.customer-credits.lookup',
        'admin.customer-credits.store',
        'admin.customers.data',
        'admin.reports.data',
        'admin.specific-closures.destroy',
        'admin.specific-closures.hours',
        'admin.specific-closures.index',
        'admin.specific-closures.store',
        'admin.specific-closures.update',
        'admin.user-access.page',
        'admin.users.candidates',
        'admin.users.details',
        'admin.users.details.update',
        'admin.users.index',
        'admin.users.remove-access',
        'admin.users.store',
        'admin.users.update',
        'mfa.challenge',
        'mfa.enable',
        'mfa.setup',
        'mfa.setup.init',
        'mfa.verify',
    ];

    protected function tearDown(): void
    {
        Auth::shouldUse('web');
        parent::tearDown();
    }

    public function test_feature_flag_off_preserves_the_default_web_guard(): void
    {
        config(['auth.multi_guard' => false]);
        Auth::shouldUse('web');

        $response = (new SelectRoleGuard)->handle(
            $this->requestFor('GET', '/dashboard'),
            fn () => response()->noContent(),
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('web', Auth::getDefaultDriver());
    }

    public function test_feature_flag_selects_the_guard_for_each_role_route_group(): void
    {
        config(['auth.multi_guard' => true]);
        $middleware = new SelectRoleGuard;

        foreach ([
            '/dashboard' => 'admin',
            '/owner/dashboard' => 'owner',
            '/staff/dashboard' => 'staff',
            '/my-dashboard' => 'customer',
        ] as $path => $guard) {
            $middleware->handle(
                $this->requestFor('GET', $path),
                fn () => response()->noContent(),
            );

            $this->assertSame($guard, Auth::getDefaultDriver(), $path);
        }
    }

    public function test_authenticated_routes_are_mapped_or_explicitly_listed_as_shared(): void
    {
        config(['auth.multi_guard' => true]);
        $middleware = new SelectRoleGuard;
        $unmapped = [];

        foreach (app('router')->getRoutes() as $route) {
            $middlewareNames = array_map(
                static fn (string $name): string => explode(':', $name, 2)[0],
                $route->gatherMiddleware(),
            );

            if (! in_array('auth', $middlewareNames, true)) {
                continue;
            }

            if ($middleware->guardForRoute($route) === null) {
                $unmapped[] = $route->getName() ?: $route->uri();
            }
        }

        sort($unmapped);
        $expectedShared = self::SHARED_AUTHENTICATED_ROUTES;
        sort($expectedShared);

        $this->assertSame(
            $expectedShared,
            $unmapped,
            'Update the explicit shared-route list or assign a role guard. Unmapped routes: '.implode(', ', $unmapped),
        );
    }

    public function test_unmapped_authenticated_route_fails_with_its_route_name(): void
    {
        config(['auth.multi_guard' => true]);
        $request = $this->requestFor('GET', '/mfa/setup');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('mfa.setup');

        (new SelectRoleGuard)->handle($request, fn () => response()->noContent());
    }

    public function test_selector_runs_before_access_version_in_the_web_middleware_group(): void
    {
        $webMiddleware = app(Kernel::class)->getMiddlewareGroups()['web'];
        $selectorIndex = array_search(SelectRoleGuard::class, $webMiddleware, true);
        $accessVersionIndex = array_search(EnsureAccessVersion::class, $webMiddleware, true);

        $this->assertNotFalse($selectorIndex);
        $this->assertNotFalse($accessVersionIndex);
        $this->assertTrue($selectorIndex < $accessVersionIndex);
    }

    private function requestFor(string $method, string $path): Request
    {
        $request = Request::create($path, $method);
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
