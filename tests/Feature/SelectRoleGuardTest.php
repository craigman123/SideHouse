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

    private const UNMAPPED_AUTHENTICATED_ROUTES = [
        'mfa.challenge',
        'mfa.enable',
        'mfa.setup',
        'mfa.setup.init',
        'mfa.verify',
    ];

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
        $expectedUnmapped = self::UNMAPPED_AUTHENTICATED_ROUTES;
        sort($expectedUnmapped);

        $this->assertSame(
            $expectedUnmapped,
            $unmapped,
            'Only MFA routes may remain unmapped in this checkpoint. Unmapped routes: '.implode(', ', $unmapped),
        );
    }

    public function test_shared_auth_and_mfa_routes_remain_unmapped_for_checkpoint_five(): void
    {
        foreach ([
            'login',
            'login.submit',
            'register',
            'register.submit',
            'auth.google',
            'logout',
            ...self::UNMAPPED_AUTHENTICATED_ROUTES,
        ] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $this->assertNull((new SelectRoleGuard)->guardForRoute($route), $routeName);
        }
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
