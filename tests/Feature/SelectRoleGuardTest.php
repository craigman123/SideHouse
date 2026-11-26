<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccessVersion;
use App\Http\Middleware\SelectRoleGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class SelectRoleGuardTest extends TestCase
{
    use UsesMultiGuardEnvironment;

    protected function setUp(): void
    {
        $this->setMultiGuardEnvironment(true);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Auth::shouldUse('web');
        parent::tearDown();
        $this->restoreMultiGuardEnvironment();
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
        $this->assertSame(
            [],
            $unmapped,
            'Every authenticated route must have a role guard mapping. Unmapped routes: '.implode(', ', $unmapped),
        );
    }

    public function test_all_auth_and_mfa_lane_routes_have_guard_markers(): void
    {
        foreach ([
            'customer' => ['login', 'login.submit', 'register', 'register.submit', 'auth.google', 'logout', 'mfa.setup', 'mfa.setup.init', 'mfa.enable', 'mfa.challenge', 'mfa.verify', 'mfa.setup.cancel'],
            'admin' => ['admin.login', 'admin.logout', 'admin.mfa.setup', 'admin.mfa.setup.init', 'admin.mfa.enable', 'admin.mfa.challenge', 'admin.mfa.verify'],
            'owner' => ['owner.login', 'owner.logout', 'owner.mfa.setup', 'owner.mfa.setup.init', 'owner.mfa.enable', 'owner.mfa.challenge', 'owner.mfa.verify'],
            'staff' => ['staff.login', 'staff.logout', 'staff.mfa.setup', 'staff.mfa.setup.init', 'staff.mfa.enable', 'staff.mfa.challenge', 'staff.mfa.verify'],
        ] as $guard => $routeNames) {
            foreach ($routeNames as $name) {
                $route = app('router')->getRoutes()->getByName($name);
                $this->assertNotNull($route, $name);
                $this->assertSame($guard, (new SelectRoleGuard)->guardForRoute($route), $name);
            }
        }
    }

    public function test_unmapped_authenticated_route_fails_with_its_route_name(): void
    {
        config(['auth.multi_guard' => true]);
        $route = app('router')->get('/test/unmapped-auth', fn () => response()->noContent())
            ->middleware('auth')
            ->name('test.unmapped-auth');
        $request = Request::create('/test/unmapped-auth', 'GET');
        $request->setRouteResolver(fn () => $route);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('test.unmapped-auth');

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
