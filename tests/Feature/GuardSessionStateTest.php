<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Middleware\EnsureAccessVersion;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\User;
use App\Services\UserAccessService;
use App\Support\GuardSessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class GuardSessionStateTest extends TestCase
{
    use RefreshDatabase;
    use UsesMultiGuardEnvironment;

    protected function setUp(): void
    {
        $this->setMultiGuardEnvironment(true);
        parent::setUp();
        config(['auth.multi_guard' => true]);
    }

    protected function tearDown(): void
    {
        Auth::shouldUse('web');
        parent::tearDown();
        $this->restoreMultiGuardEnvironment();
    }

    public function test_customer_login_keeps_the_admin_guard_and_session_state(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('user');
        $session = app('session.store');
        $session->start();

        Auth::shouldUse('admin');
        Auth::guard('admin')->login($admin);
        $session->put(GuardSessionState::key('view_as'), 'staff');
        $session->put([
            GuardSessionState::key('lumi_login_popover_pending', 'admin') => true,
            GuardSessionState::key('lumi_login_popover_shown', 'admin') => true,
            GuardSessionState::key('lumi_suggestions_seen', 'admin') => true,
            GuardSessionState::key('lumi_login_popover_shown', 'customer') => true,
            GuardSessionState::key('lumi_suggestions_seen', 'customer') => true,
        ]);
        EnsureAccessVersion::stamp($this->request($session, $admin), $admin);
        $adminGuardSessionKey = Auth::guard('admin')->getName();

        Auth::shouldUse('customer');
        $loginRequest = Request::create('/login', 'POST', [
            'username' => $customer->username,
            'password' => 'password',
        ]);
        $loginRequest->headers->set('Accept', 'application/json');
        $loginRequest->setLaravelSession($session);

        $response = (new AuthController)->login($loginRequest);

        $this->assertSame('/my-dashboard', json_decode($response->getContent(), true)['redirect']);
        $this->assertSame($admin->user_id, $session->get($adminGuardSessionKey));
        $this->assertSame('staff', $session->get(GuardSessionState::key('view_as', 'admin')));
        $this->assertSame($admin->user_id, $session->get(GuardSessionState::key('access_version_user_id', 'admin')));
        $this->assertSame(1, $session->get(GuardSessionState::key('access_version', 'admin')));
        $this->assertSame($customer->user_id, $session->get(GuardSessionState::key('access_version_user_id', 'customer')));
        $this->assertSame(1, $session->get(GuardSessionState::key('access_version', 'customer')));
        $this->assertTrue($session->get(GuardSessionState::key('lumi_login_popover_pending', 'admin')));
        $this->assertTrue($session->get(GuardSessionState::key('lumi_login_popover_shown', 'admin')));
        $this->assertTrue($session->get(GuardSessionState::key('lumi_suggestions_seen', 'admin')));
        $this->assertTrue($session->get(GuardSessionState::key('lumi_login_popover_pending', 'customer')));
        $this->assertNull($session->get(GuardSessionState::key('lumi_login_popover_shown', 'customer')));
        $this->assertNull($session->get(GuardSessionState::key('lumi_suggestions_seen', 'customer')));

        Auth::forgetGuards();
        Auth::shouldUse('admin');
        $this->assertSame($admin->user_id, Auth::guard('admin')->user()?->user_id);

        $adminRequest = $this->request($session, $admin);
        $adminResponse = (new EnsureAccessVersion)->handle($adminRequest, fn () => response()->noContent());
        $this->assertSame(204, $adminResponse->getStatusCode());
    }

    public function test_revoked_access_logs_out_only_the_affected_guard(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('user');
        $session = app('session.store');
        $session->start();

        Auth::shouldUse('admin');
        Auth::guard('admin')->login($admin);
        EnsureAccessVersion::stamp($this->request($session, $admin), $admin);
        $adminGuardSessionKey = Auth::guard('admin')->getName();

        Auth::shouldUse('customer');
        Auth::guard('customer')->login($customer);
        EnsureAccessVersion::stamp($this->request($session, $customer), $customer);
        $customerGuardSessionKey = Auth::guard('customer')->getName();
        $customer->forceFill(['access_version' => 2])->save();

        $request = $this->request($session, $customer);
        $request->headers->set('Accept', 'application/json');
        $response = (new EnsureAccessVersion)->handle($request, fn () => response()->noContent());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertNull($session->get($customerGuardSessionKey));
        $this->assertSame($admin->user_id, $session->get($adminGuardSessionKey));
        $this->assertSame($admin->user_id, $session->get(GuardSessionState::key('access_version_user_id', 'admin')));
        $this->assertNull($session->get(GuardSessionState::key('access_version_user_id', 'customer')));
    }

    public function test_revoked_access_redirects_to_the_selected_role_login_lane(): void
    {
        $admin = $this->user('admin');
        $session = app('session.store');
        $session->start();
        Auth::shouldUse('admin');
        Auth::guard('admin')->login($admin);
        EnsureAccessVersion::stamp($this->request($session, $admin), $admin);
        $admin->forceFill(['access_version' => 2])->save();

        $response = (new EnsureAccessVersion)->handle(
            $this->request($session, $admin),
            fn () => response()->noContent(),
        );

        $this->assertSame(route('admin.login'), $response->headers->get('Location'));
    }

    public function test_mfa_pass_for_admin_does_not_satisfy_owner_or_staff(): void
    {
        $admin = $this->user('admin', ['mfa_enabled' => true, 'mfa_secret' => 'test-secret']);
        $owner = $this->user('owner', ['mfa_enabled' => true, 'mfa_secret' => 'test-secret']);
        $staff = $this->user('staff', ['mfa_enabled' => true, 'mfa_secret' => 'test-secret']);
        $session = app('session.store');
        $session->start();
        $session->put([
            GuardSessionState::key('mfa_passed_at', 'admin') => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id', 'admin') => $admin->user_id,
        ]);
        $middleware = new EnsureAdminMfa;

        Auth::shouldUse('admin');
        $adminResponse = $middleware->handle($this->request($session, $admin), fn () => response()->noContent());
        $this->assertSame(204, $adminResponse->getStatusCode());

        foreach (['owner' => $owner, 'staff' => $staff] as $guard => $user) {
            Auth::shouldUse($guard);
            $response = $middleware->handle($this->request($session, $user), fn () => response()->noContent());
            $this->assertSame(route($guard.'.mfa.challenge'), $response->headers->get('Location'));
            $this->assertSame($admin->user_id, $session->get(GuardSessionState::key('mfa_passed_user_id', 'admin')));
            $this->assertNull($session->get(GuardSessionState::key('mfa_passed_user_id', $guard)));
        }
    }

    public function test_mfa_setup_secrets_are_stored_per_guard(): void
    {
        $users = [
            'admin' => $this->user('admin'),
            'owner' => $this->user('owner'),
            'staff' => $this->user('staff'),
        ];
        $session = app('session.store');
        $session->start();
        $controller = new MfaController;

        foreach ($users as $guard => $user) {
            Auth::shouldUse($guard);
            $controller->initSetup($this->request($session, $user));
        }

        $secrets = [];
        foreach ($users as $guard => $user) {
            $secret = $session->get(GuardSessionState::key('mfa_temp_secret', $guard));
            $this->assertSame($user->user_id, $session->get(GuardSessionState::key('mfa_temp_secret_user_id', $guard)));
            $this->assertNotEmpty($secret);
            $secrets[] = $secret;
        }

        $this->assertCount(3, array_unique($secrets));
    }

    public function test_customer_login_and_non_admin_role_cleanup_preserve_admin_view_as(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('user');
        $session = app('session.store');
        $session->start();
        $session->put([
            GuardSessionState::key('view_as', 'admin') => 'owner',
            GuardSessionState::key('view_as', 'customer') => 'stale',
        ]);

        Auth::shouldUse('customer');
        $request = $this->request($session, $customer);
        $this->assertSame('user', app(UserAccessService::class)->effectiveRole($customer, $request));
        $this->assertSame('owner', $session->get(GuardSessionState::key('view_as', 'admin')));
        $this->assertNull($session->get(GuardSessionState::key('view_as', 'customer')));

        Auth::shouldUse('admin');
        $this->assertSame('owner', app(UserAccessService::class)->effectiveRole($admin, $this->request($session, $admin)));
        $this->assertSame('owner', $session->get(GuardSessionState::key('view_as', 'admin')));
    }

    public function test_logging_out_customer_preserves_admin_guard_and_scoped_state(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('user');
        $session = app('session.store');
        $session->start();

        Auth::shouldUse('admin');
        Auth::guard('admin')->login($admin);
        $adminGuardSessionKey = Auth::guard('admin')->getName();
        $session->put([
            GuardSessionState::key('access_version_user_id', 'admin') => $admin->user_id,
            GuardSessionState::key('access_version', 'admin') => 1,
            GuardSessionState::key('view_as', 'admin') => 'staff',
        ]);

        Auth::shouldUse('customer');
        Auth::guard('customer')->login($customer);
        $customerGuardSessionKey = Auth::guard('customer')->getName();
        $session->put([
            GuardSessionState::key('access_version_user_id', 'customer') => $customer->user_id,
            GuardSessionState::key('access_version', 'customer') => 1,
            GuardSessionState::key('mfa_passed_at', 'customer') => now()->timestamp,
        ]);

        $request = Request::create('/logout', 'POST');
        $request->setLaravelSession($session);
        Auth::shouldUse('customer');
        (new AuthController)->logout($request);

        $this->assertNull($session->get($customerGuardSessionKey));
        $this->assertSame($admin->user_id, $session->get($adminGuardSessionKey));
        $this->assertSame($admin->user_id, $session->get(GuardSessionState::key('access_version_user_id', 'admin')));
        $this->assertSame('staff', $session->get(GuardSessionState::key('view_as', 'admin')));
        $this->assertNull($session->get(GuardSessionState::key('mfa_passed_at', 'customer')));

        Auth::forgetGuards();
        Auth::shouldUse('admin');
        $this->assertSame($admin->user_id, Auth::guard('admin')->user()?->user_id);
    }

    private function request($session, User $user): Request
    {
        $request = Request::create('/dashboard');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function user(string $role, array $attributes = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_merge([
            'username' => "guard-session-{$role}-{$sequence}",
            'name' => ucfirst($role).' Guard Session',
            'email' => "guard-session-{$role}-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'access_version' => 1,
        ], $attributes));
    }
}
