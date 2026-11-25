<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\User;
use App\Models\WeeklyCourtClosure;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class RoleScopedSharedRoutesTest extends TestCase
{
    use RefreshDatabase;
    use UsesMultiGuardEnvironment;

    private const ROLE_MATRIX = [
        'admin.specific-closures.index' => ['admin', 'owner'],
        'admin.specific-closures.hours' => ['admin', 'owner'],
        'admin.bookings.walk-in.store' => ['admin', 'owner'],
        'admin.blocks.store' => ['admin', 'owner', 'staff'],
        'admin.customers.data' => ['admin', 'owner', 'staff'],
        'admin.specific-closures.store' => ['admin', 'owner'],
        'admin.specific-closures.update' => ['admin', 'owner'],
        'admin.specific-closures.destroy' => ['admin', 'owner'],
        'admin.bookings.calendar' => ['admin', 'owner', 'staff'],
        'admin.bookings.update' => ['admin', 'owner', 'staff'],
        'admin.blocks.update' => ['admin', 'owner', 'staff'],
        'admin.blocks.destroy' => ['admin', 'owner', 'staff'],
        'admin.user-access.page' => ['admin', 'owner'],
        'admin.users.index' => ['admin', 'owner'],
        'admin.users.candidates' => ['admin', 'owner'],
        'admin.users.store' => ['admin', 'owner'],
        'admin.users.update' => ['admin', 'owner'],
        'admin.users.remove-access' => ['admin', 'owner'],
        'admin.users.details' => ['admin'],
        'admin.users.details.update' => ['admin'],
        'admin.reports.data' => ['admin', 'owner'],
        'admin.customer-credits.lookup' => ['admin', 'owner'],
        'admin.customer-credits.store' => ['admin', 'owner'],
    ];

    protected function setUp(): void
    {
        $this->setMultiGuardEnvironment(true);
        parent::setUp();
        $this->assertTrue((bool) config('auth.multi_guard'));
    }

    protected function tearDown(): void
    {
        Auth::shouldUse('web');
        parent::tearDown();
        $this->restoreMultiGuardEnvironment();
    }

    public function test_role_copies_match_the_existing_access_matrix_and_have_no_unscoped_legacy_routes(): void
    {
        $definitions = require base_path('routes/shared_admin.php');
        $definitionRoles = [];
        $expectedCopies = [];

        foreach ($definitions as $definition) {
            $definitionRoles[$definition['name']] = $definition['roles'];
            $suffix = substr($definition['name'], strlen('admin.'));

            foreach ($definition['roles'] as $role) {
                $name = $role.'.'.$suffix;
                $legacyUri = $definition['legacy_uri'];
                $uri = $role === 'admin' && str_starts_with($legacyUri, 'admin/')
                    ? $legacyUri
                    : $role.'/'.$legacyUri;
                $expectedCopies[$name] = [$uri, $role];

                $route = app('router')->getRoutes()->getByName($name);
                $this->assertNotNull($route, $name);
                $this->assertSame($uri, $route->uri(), $name);
                $this->assertSame($role, $route->defaults['_role_guard'] ?? null, $name);
                $this->assertSame(
                    array_merge(['web'], $definition['middleware']),
                    $route->gatherMiddleware(),
                    $name,
                );
            }

            foreach (['admin', 'owner', 'staff'] as $role) {
                if (! in_array($role, $definition['roles'], true)) {
                    $this->assertNull(
                        app('router')->getRoutes()->getByName($role.'.'.$suffix),
                        "{$role} must not receive {$definition['name']}",
                    );
                }
            }

            if (! str_starts_with($legacyUri, 'admin/')) {
                $this->assertNotContains(
                    $legacyUri,
                    array_map(
                        static fn ($route): string => $route->uri(),
                        app('router')->getRoutes()->getRoutes(),
                    ),
                    "Unscoped legacy URI [{$legacyUri}] remains registered.",
                );
            } else {
                $adminCopy = app('router')->getRoutes()->getByName($definition['name']);
                $this->assertSame('admin', $adminCopy?->defaults['_role_guard'] ?? null);
            }
        }

        $authLanePaths = [
            'customer' => [
                'login' => 'login',
                'login.submit' => 'login',
                'register' => 'register',
                'register.submit' => 'register',
                'auth.google' => 'auth/google',
                'logout' => 'logout',
                'mfa.setup' => 'mfa/setup',
                'mfa.setup.init' => 'mfa/setup/init',
                'mfa.enable' => 'mfa/enable',
                'mfa.challenge' => 'mfa/challenge',
                'mfa.verify' => 'mfa/verify',
            ],
            'admin' => [
                'login' => 'admin/login',
                'login.submit' => 'admin/login',
                'auth.google' => 'admin/auth/google',
                'logout' => 'admin/logout',
                'mfa.setup' => 'admin/mfa/setup',
                'mfa.setup.init' => 'admin/mfa/setup/init',
                'mfa.enable' => 'admin/mfa/enable',
                'mfa.challenge' => 'admin/mfa/challenge',
                'mfa.verify' => 'admin/mfa/verify',
            ],
            'owner' => [
                'login' => 'owner/login',
                'login.submit' => 'owner/login',
                'auth.google' => 'owner/auth/google',
                'logout' => 'owner/logout',
                'mfa.setup' => 'owner/mfa/setup',
                'mfa.setup.init' => 'owner/mfa/setup/init',
                'mfa.enable' => 'owner/mfa/enable',
                'mfa.challenge' => 'owner/mfa/challenge',
                'mfa.verify' => 'owner/mfa/verify',
            ],
            'staff' => [
                'login' => 'staff/login',
                'login.submit' => 'staff/login',
                'auth.google' => 'staff/auth/google',
                'logout' => 'staff/logout',
                'mfa.setup' => 'staff/mfa/setup',
                'mfa.setup.init' => 'staff/mfa/setup/init',
                'mfa.enable' => 'staff/mfa/enable',
                'mfa.challenge' => 'staff/mfa/challenge',
                'mfa.verify' => 'staff/mfa/verify',
            ],
        ];
        foreach ($authLanePaths as $guard => $routes) {
            foreach ($routes as $suffix => $uri) {
                $name = $guard === 'customer' ? $suffix : $guard.'.'.$suffix;
                $expectedCopies[$name] = [$uri, $guard];
            }
        }

        ksort($definitionRoles);
        $expectedMatrix = self::ROLE_MATRIX;
        ksort($expectedMatrix);
        $this->assertSame($expectedMatrix, $definitionRoles);

        ksort($expectedCopies);
        $actualCopies = [];
        foreach (app('router')->getRoutes() as $route) {
            $guard = $route->defaults['_role_guard'] ?? null;
            if ($guard !== null) {
                $actualCopies[$route->getName()] = [$route->uri(), $guard];
            }
        }
        ksort($actualCopies);
        $this->assertSame($expectedCopies, $actualCopies);
    }

    public function test_every_role_copy_rejects_each_role_outside_its_access_matrix(): void
    {
        $targetUser = $this->user('staff');
        $court = Court::create([
            'name' => 'Role-route court',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 500,
            'status' => 'active',
        ]);
        $booking = Booking::create([
            'customer_name' => 'Route test booking',
            'contact_number' => '09123456789',
            'court_id' => $court->id,
            'date' => '2030-01-02',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'amount' => 500,
            'status' => 'confirmed',
        ]);
        $block = CourtBlock::create([
            'court_id' => $court->id,
            'type' => 'maintenance',
            'title' => 'Route test block',
            'block_date' => '2030-01-02',
            'start_time' => '11:00',
            'end_time' => '12:00',
            'created_by' => $targetUser->user_id,
        ]);
        $closure = WeeklyCourtClosure::create([
            'weekday' => 1,
            'court_id' => $court->id,
            'start_time' => '13:00',
            'end_time' => '14:00',
            'reason' => 'Route test closure',
            'created_by' => $targetUser->user_id,
            'active' => true,
        ]);
        $roleCandidates = ['admin', 'owner', 'staff', 'user'];

        foreach (require base_path('routes/shared_admin.php') as $definition) {
            $suffix = substr($definition['name'], strlen('admin.'));

            foreach ($definition['roles'] as $guard) {
                $routeName = $guard.'.'.$suffix;
                $parameters = $this->parametersFor($definition['legacy_uri'], [
                    'booking' => $booking->id,
                    'block' => $block->id,
                    'closure' => $closure->id,
                    'user' => $targetUser->user_id,
                ]);
                $url = route($routeName, $parameters);

                foreach ($roleCandidates as $candidateRole) {
                    if (in_array($candidateRole, $definition['roles'], true)) {
                        continue;
                    }

                    $candidate = $this->user($candidateRole);
                    $this->actingAs($candidate, $guard)->withSession([
                        'access_version_user_id_'.$guard => $candidate->user_id,
                        'access_version_'.$guard => 1,
                        'mfa_passed_at_'.$guard => now()->timestamp,
                        'mfa_passed_user_id_'.$guard => $candidate->user_id,
                    ]);

                    $this->call($definition['method'], $url)
                        ->assertForbidden("{$candidateRole} was not rejected by {$routeName}.");
                }
            }
        }
    }

    public function test_role_route_helper_uses_the_selected_guard_and_fails_for_unavailable_copies(): void
    {
        foreach (['admin', 'owner', 'staff'] as $guard) {
            Auth::shouldUse($guard);
            $this->assertSame(
                route($guard.'.bookings.calendar'),
                role_route('admin.bookings.calendar'),
            );
        }

        Auth::shouldUse('owner');
        $this->expectException(LogicException::class);
        role_route('admin.users.details');
    }

    public function test_auth_event_cleanup_does_not_remove_guard_scoped_mfa_keys(): void
    {
        $admin = $this->user('admin');
        $session = app('session.store');
        $session->start();
        $session->put([
            'mfa_passed_at' => 1,
            'mfa_passed_user_id' => 2,
            'mfa_temp_secret' => 'legacy-secret',
            'mfa_temp_secret_user_id' => 2,
            'mfa_passed_at_admin' => 10,
            'mfa_passed_user_id_admin' => $admin->user_id,
            'mfa_temp_secret_admin' => 'admin-secret',
            'mfa_temp_secret_user_id_admin' => $admin->user_id,
            'mfa_passed_at_owner' => 20,
        ]);

        event(new Login('admin', $admin, false));
        $this->assertNull($session->get('mfa_passed_at'));
        $this->assertNull($session->get('mfa_temp_secret'));
        $this->assertSame(10, $session->get('mfa_passed_at_admin'));
        $this->assertSame($admin->user_id, $session->get('mfa_passed_user_id_admin'));
        $this->assertSame('admin-secret', $session->get('mfa_temp_secret_admin'));
        $this->assertSame(20, $session->get('mfa_passed_at_owner'));

        $session->put([
            'mfa_passed_at' => 3,
            'mfa_temp_secret' => 'legacy-secret',
        ]);
        event(new Logout('admin', $admin));
        $this->assertNull($session->get('mfa_passed_at'));
        $this->assertNull($session->get('mfa_temp_secret'));
        $this->assertSame(10, $session->get('mfa_passed_at_admin'));
        $this->assertSame('admin-secret', $session->get('mfa_temp_secret_admin'));
        $this->assertSame(20, $session->get('mfa_passed_at_owner'));
    }

    private function parametersFor(string $uri, array $values): array
    {
        preg_match_all('/\{([^}]+)\}/', $uri, $matches);

        return array_intersect_key($values, array_flip($matches[1]));
    }

    private function user(string $role): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => "role-route-{$role}-{$sequence}",
            'name' => ucfirst($role).' Route Test',
            'email' => "role-route-{$role}-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'access_version' => 1,
        ]);
    }
}
