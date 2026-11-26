<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class LegacySharedRoutesSnapshotTest extends TestCase
{
    use UsesMultiGuardEnvironment;

    private const AUTH_ROUTES = [
        'login' => ['login', ['GET', 'HEAD'], ['web', 'throttle:30,1']],
        'login.submit' => ['login', ['POST'], ['web', 'throttle:login']],
        'register' => ['register', ['GET', 'HEAD'], ['web', 'throttle:30,1']],
        'register.submit' => ['register', ['POST'], ['web', 'throttle:register']],
        'auth.google' => ['auth/google', ['POST'], ['web', 'throttle:15,1']],
        'logout' => ['logout', ['POST'], ['web', 'throttle:10,1,logout']],
        'mfa.setup' => ['mfa/setup', ['GET', 'HEAD'], ['web', 'auth', 'throttle:20,1']],
        'mfa.setup.init' => ['mfa/setup/init', ['POST'], ['web', 'auth', 'throttle:5,1']],
        'mfa.enable' => ['mfa/enable', ['POST'], ['web', 'auth', 'throttle:5,1']],
        'mfa.challenge' => ['mfa/challenge', ['GET', 'HEAD'], ['web', 'auth', 'throttle:20,1']],
        'mfa.verify' => ['mfa/verify', ['POST'], ['web', 'auth', 'throttle:5,1']],
    ];

    private const SHARED_ROUTES = [
        'admin.specific-closures.index' => [
            'admin/specific-closures',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'throttle.admin-management:120,1', 'throttle.admin-management:30,1'],
        ],
        'admin.specific-closures.hours' => [
            'admin/specific-closures/operating-hours',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'throttle.admin-management:120,1', 'throttle.admin-management:30,1'],
        ],
        'admin.bookings.walk-in.store' => [
            'admin/bookings',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'throttle.admin-management:120,1', 'can.access:bookings.create', 'staff.redact', 'throttle.admin-management:20,1'],
        ],
        'admin.blocks.store' => [
            'admin/blocks',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'throttle.admin-management:120,1', 'can.access:calendar_events.manage,calendar_events.open_play', 'throttle.admin-management:20,1'],
        ],
        'admin.customers.data' => [
            'customers/data',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'throttle.admin-management:120,1', 'can.access:customers.view', 'staff.redact'],
        ],
        'admin.specific-closures.store' => [
            'admin/specific-closures',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'throttle:admin-closures'],
        ],
        'admin.specific-closures.update' => [
            'admin/specific-closures/{closure}',
            ['PATCH'],
            ['web', 'auth', 'admin.mfa', 'throttle:admin-closures'],
        ],
        'admin.specific-closures.destroy' => [
            'admin/specific-closures/{closure}',
            ['DELETE'],
            ['web', 'auth', 'admin.mfa', 'throttle:admin-calendar-delete'],
        ],
        'admin.bookings.calendar' => [
            'admin/bookings/calendar',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'can.access:bookings.view,calendar_events.open_play', 'staff.redact', 'throttle:admin-calendar-read'],
        ],
        'admin.bookings.update' => [
            'admin/bookings/{booking}',
            ['PATCH'],
            ['web', 'auth', 'admin.mfa', 'can.access:bookings.edit', 'staff.redact', 'throttle:admin-calendar-quote', 'throttle:admin-calendar-save'],
        ],
        'admin.blocks.update' => [
            'admin/blocks/{block}',
            ['PATCH'],
            ['web', 'auth', 'admin.mfa', 'can.access:calendar_events.manage,calendar_events.open_play', 'throttle:admin-calendar-save'],
        ],
        'admin.blocks.destroy' => [
            'admin/blocks/{block}',
            ['DELETE'],
            ['web', 'auth', 'admin.mfa', 'can.access:calendar_events.manage,calendar_events.open_play', 'throttle:admin-calendar-delete'],
        ],
        'admin.user-access.page' => [
            'admin/user-access',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'admin.or.owner', 'throttle.admin-management:120,1', 'can.access:user_access.manage', 'throttle.admin-management:20,1', 'owner.page.redirect'],
        ],
        'admin.users.index' => [
            'admin/users',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'admin.or.owner', 'throttle.admin-management:120,1', 'can.access:user_access.manage', 'throttle.admin-management:20,1'],
        ],
        'admin.users.candidates' => [
            'admin/users/candidates',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'admin.or.owner', 'throttle.admin-management:120,1', 'can.access:user_access.manage', 'throttle.admin-management:20,1'],
        ],
        'admin.users.store' => [
            'admin/users',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'admin.or.owner', 'throttle.admin-management:120,1', 'can.access:user_access.manage', 'throttle.admin-management:20,1'],
        ],
        'admin.users.update' => [
            'admin/users/{user}',
            ['PATCH'],
            ['web', 'auth', 'admin.mfa', 'admin.or.owner', 'throttle.admin-management:120,1', 'can.access:user_access.manage', 'throttle.admin-management:20,1'],
        ],
        'admin.users.remove-access' => [
            'admin/users/{user}/remove-access',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'admin.or.owner', 'throttle.admin-management:120,1', 'can.access:user_access.manage', 'throttle.admin-management:20,1'],
        ],
        'admin.users.details' => [
            'admin/users/{user}/details',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'admin.or.owner', 'throttle.admin-management:120,1', 'can.access:user_access.manage', 'throttle.admin-management:20,1', 'can.access:user_details.edit'],
        ],
        'admin.users.details.update' => [
            'admin/users/{user}/details',
            ['PATCH'],
            ['web', 'auth', 'admin.mfa', 'admin.or.owner', 'throttle.admin-management:120,1', 'can.access:user_access.manage', 'throttle.admin-management:20,1', 'can.access:user_details.edit'],
        ],
        'admin.users.mfa.disable' => [
            'admin/users/{user}/mfa/disable',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'real-admin', 'throttle:5,1'],
        ],
        'admin.users.mfa.enable' => [
            'admin/users/{user}/mfa/enable',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'real-admin', 'throttle:5,1'],
        ],
        'admin.users.mfa.allowed-methods' => [
            'admin/users/{user}/mfa/allowed-methods',
            ['PATCH'],
            ['web', 'auth', 'admin.mfa', 'real-admin', 'throttle:5,1'],
        ],
        'admin.users.mfa.owner-request' => [
            'admin/users/{user}/mfa/owner-requests',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'real-admin', 'throttle:5,1'],
        ],
        'admin.users.mfa.owner-approve' => [
            'admin/users/{user}/mfa/owner-requests/{requestId}/approve',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'real-admin', 'throttle:5,1'],
        ],
        'admin.reports.data' => [
            'reports/data',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'admin.or.owner', 'throttle.admin-management:120,1'],
        ],
        'admin.customer-credits.lookup' => [
            'customers/credits/lookup',
            ['GET', 'HEAD'],
            ['web', 'auth', 'admin.mfa', 'credit.giver', 'throttle.admin-management:120,1', 'throttle.admin-management:20,1'],
        ],
        'admin.customer-credits.store' => [
            'customers/credits',
            ['POST'],
            ['web', 'auth', 'admin.mfa', 'credit.giver', 'throttle.admin-management:120,1', 'throttle.admin-management:10,1'],
        ],
    ];

    protected function setUp(): void
    {
        $this->setMultiGuardEnvironment(false);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreMultiGuardEnvironment();
    }

    public function test_flag_off_preserves_all_shared_route_names_uris_methods_and_middleware(): void
    {
        $this->assertFalse((bool) config('auth.multi_guard'));

        $actual = [];
        foreach (array_keys(self::SHARED_ROUTES) as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $actual[$name] = [
                $route->uri(),
                array_values($route->methods()),
                $route->gatherMiddleware(),
            ];
        }

        ksort($actual);
        $expected = self::SHARED_ROUTES;
        ksort($expected);
        $this->assertSame($expected, $actual);

        foreach (require base_path('routes/shared_admin.php') as $definition) {
            $suffix = substr($definition['name'], strlen('admin.'));
            $this->assertNull(app('router')->getRoutes()->getByName('owner.'.$suffix));
            $this->assertNull(app('router')->getRoutes()->getByName('staff.'.$suffix));
        }
    }

    public function test_role_route_helper_uses_the_legacy_name_when_the_flag_is_off(): void
    {
        $this->assertSame(route('admin.bookings.calendar'), role_route('admin.bookings.calendar'));
    }

    public function test_flag_off_preserves_auth_and_mfa_route_names_uris_methods_and_middleware(): void
    {
        $actual = [];
        foreach (array_keys(self::AUTH_ROUTES) as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $actual[$name] = [
                $route->uri(),
                array_values($route->methods()),
                $route->gatherMiddleware(),
            ];
        }

        $this->assertSame(self::AUTH_ROUTES, $actual);
        $this->assertNull(app('router')->getRoutes()->getByName('admin.login'));
        $this->assertNull(app('router')->getRoutes()->getByName('owner.mfa.setup'));
        $this->assertNull(app('router')->getRoutes()->getByName('staff.logout'));
    }
}
