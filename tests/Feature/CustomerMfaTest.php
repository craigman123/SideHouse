<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\GuardSessionState;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Session\TokenMismatchException;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class CustomerMfaTest extends TestCase
{
    use RefreshDatabase;
    use UsesMultiGuardEnvironment;

    protected function setUp(): void
    {
        $this->setMultiGuardEnvironment(getenv('MULTI_GUARD_ENABLED') === 'true');
        parent::setUp();
        config(['auth.multi_guard' => getenv('MULTI_GUARD_ENABLED') === 'true']);
    }

    protected function tearDown(): void
    {
        Auth::shouldUse('web');
        parent::tearDown();
        $this->restoreMultiGuardEnvironment();
    }

    public function test_customer_can_enable_and_disable_mfa_with_hashed_recovery_codes(): void
    {
        $customer = $this->customer();
        $google2fa = new Google2FA();
        $this->actingAs($customer, 'customer');
        session()->put(GuardSessionState::key('mfa_setup_voluntary'), true);

        $init = $this->postJson('/mfa/setup/init')->assertOk();
        $secret = $init->json('secret');
        $this->assertNotEmpty($secret);

        $enable = $this->postJson('/mfa/enable', [
            'code' => $google2fa->getCurrentOtp($secret),
        ]);
        $enable->assertOk()->assertJsonStructure(['recovery_codes']);

        $customer->refresh();
        $this->assertTrue($customer->hasMfaEnabled());
        $this->assertSame('totp', $customer->mfa_method);
        $this->assertNull(session(GuardSessionState::key('mfa_setup_voluntary')));
        $plainCodes = $enable->json('recovery_codes');
        $this->assertCount(8, $plainCodes);
        foreach ($plainCodes as $index => $plainCode) {
            $this->assertStringStartsWith('$', $customer->mfa_recovery_codes[$index]);
            $this->assertTrue(Hash::check($plainCode, $customer->mfa_recovery_codes[$index]));
        }

        $response = $this->post('/profile/mfa/disable', [
            'mfa_code' => $enable->json('recovery_codes')[0],
        ])->assertRedirect('/profile');

        $this->assertFalse($customer->fresh()->hasMfaEnabled());
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.mfa_disabled', 'subject_id' => $customer->user_id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.mfa_enabled', 'subject_id' => $customer->user_id]);
    }

    public function test_customer_can_cancel_voluntary_setup_without_changing_saved_mfa_or_other_guard_state(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer, 'customer');

        $this->post('/profile/mfa/setup')->assertRedirect('/mfa/setup');
        $this->get('/mfa/setup')
            ->assertOk()
            ->assertSee('action="'.route('mfa.setup.cancel').'"', false)
            ->assertSee('class="mfa-modal-ghost-btn">Cancel</button>', false);

        $session = app('session.store');
        $session->put([GuardSessionState::key('mfa_passed_at') => 123]);
        if (config('auth.multi_guard')) {
            $session->put('mfa_temp_secret_admin', 'admin-secret');
            $session->put('mfa_temp_secret_user_id_admin', 99);
        }

        $this->post('/mfa/setup/cancel')->assertRedirect('/profile')->assertSessionHas('success', 'MFA setup cancelled.');

        $this->assertNull($session->get(GuardSessionState::key('mfa_temp_secret')));
        $this->assertNull($session->get(GuardSessionState::key('mfa_temp_secret_user_id')));
        $this->assertNull($session->get(GuardSessionState::key('mfa_setup_voluntary')));
        $this->assertSame(123, $session->get(GuardSessionState::key('mfa_passed_at')));
        if (config('auth.multi_guard')) {
            $this->assertSame('admin-secret', $session->get('mfa_temp_secret_admin'));
            $this->assertSame(99, $session->get('mfa_temp_secret_user_id_admin'));
        }

        $this->assertFalse($customer->fresh()->mfa_enabled);
        $this->assertNull($customer->fresh()->mfa_secret);
    }

    public function test_cancel_does_not_change_an_already_enabled_customers_mfa(): void
    {
        $customer = $this->customer([
            'mfa_enabled' => true,
            'mfa_method' => 'totp',
            'mfa_secret' => (new Google2FA())->generateSecretKey(),
            'mfa_recovery_codes' => ['existing-code'],
        ]);
        $savedSecret = $customer->mfa_secret;
        $this->actingAs($customer, 'customer');
        $session = app('session.store');
        $session->put([
            GuardSessionState::key('mfa_setup_voluntary') => true,
            GuardSessionState::key('mfa_temp_secret') => 'pending-secret',
            GuardSessionState::key('mfa_temp_secret_user_id') => $customer->user_id,
        ]);

        $this->post('/mfa/setup/cancel')->assertRedirect('/profile');

        $fresh = $customer->fresh();
        $this->assertTrue($fresh->mfa_enabled);
        $this->assertSame('totp', $fresh->mfa_method);
        $this->assertSame($savedSecret, $fresh->mfa_secret);
        $this->assertSame('existing-code', $fresh->mfa_recovery_codes[0]);
    }

    public function test_privileged_roles_cannot_cancel_mandatory_setup(): void
    {
        foreach (['admin', 'owner', 'staff'] as $role) {
            $actor = $this->createUser($role);
            $setupUri = config('auth.multi_guard') ? '/'.$role.'/mfa/setup' : '/mfa/setup';
            $authenticated = $this->actingAs($actor, $role)
                ->withSession([
                    'access_version_user_id' => $actor->user_id,
                    'access_version' => 1,
                    'mfa_setup_voluntary' => true,
                    'mfa_temp_secret' => 'mandatory-secret',
                    'mfa_temp_secret_user_id' => $actor->user_id,
                ]);
            $authenticated->get($setupUri)->assertOk()->assertDontSee('mfa-modal-ghost-btn');
            $response = $authenticated->post('/mfa/setup/cancel');

            $this->assertNotContains($response->getStatusCode(), [200, 201, 202, 204]);
        }
    }

    public function test_cancel_requires_csrf_token(): void
    {
        $request = Request::create('/mfa/setup/cancel', 'POST');
        $request->setLaravelSession(app('session.store'));
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $middleware = new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response()->noContent());
    }

    public function test_recovery_code_is_consumed_once_and_plaintext_codes_remain_supported(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $plaintext = 'OLDRECOV1';
        $customer = $this->customer([
            'mfa_enabled' => true,
            'mfa_method' => 'totp',
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => [$plaintext],
        ]);
        $this->actingAs($customer, 'customer');

        $first = $this->postJson('/mfa/verify', ['code' => $plaintext])->assertOk();
        $first->assertJsonPath('redirect', '/my-dashboard');
        $this->assertSame([], $customer->fresh()->mfa_recovery_codes);

        $this->postJson('/mfa/verify', ['code' => $plaintext])->assertUnprocessable();

        $hash = Hash::make('HASHRECOV1');
        $customer->forceFill(['mfa_recovery_codes' => [$hash]])->save();
        $this->postJson('/mfa/verify', ['code' => 'HASHRECOV1'])->assertOk();
        $this->assertSame([], $customer->fresh()->mfa_recovery_codes);
    }

    public function test_password_login_enforces_customer_challenge_in_both_guard_modes(): void
    {
        $multiGuard = config('auth.multi_guard');
        $secret = (new Google2FA())->generateSecretKey();
        $customer = $this->customer([
            'mfa_enabled' => true,
            'mfa_method' => 'totp',
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => [],
        ]);

        $this->postJson('/login', [
            'username' => $customer->username,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'challenge');

        $guard = $multiGuard ? 'customer' : 'web';
        $this->assertSame($customer->user_id, Auth::guard($guard)->user()?->user_id);
    }

    public function test_non_customer_actors_cannot_disable_customer_mfa(): void
    {
        $customer = $this->customer([
            'mfa_enabled' => true,
            'mfa_secret' => (new Google2FA())->generateSecretKey(),
        ]);

        foreach (['admin', 'owner', 'staff', 'user'] as $role) {
            if ($role === 'user') {
                $actor = $this->customer(['username' => "other-{$role}-".uniqid()]);
            } else {
                $actor = $this->createUser($role);
            }

            $response = $this->actingAs($actor, $role === 'user' ? 'customer' : $role)
                ->post('/profile/mfa/disable', ['mfa_code' => '123456', 'target_user_id' => $customer->user_id]);
            $this->assertContains($response->getStatusCode(), [403, 302]);
        }

        $this->assertTrue($customer->fresh()->hasMfaEnabled());
    }

    public function test_login_and_logout_events_clear_only_the_affected_guard_mfa_state(): void
    {
        $admin = $this->createUser('admin');
        $customer = $this->customer();
        $session = app('session.store');
        $session->start();
        $session->put([
            GuardSessionState::key('mfa_passed_at', 'admin') => 10,
            GuardSessionState::key('mfa_passed_at', 'customer') => 20,
            GuardSessionState::key('mfa_temp_secret', 'customer') => 'customer-secret',
        ]);

        event(new Login('admin', $admin, false));
        if (config('auth.multi_guard')) {
            $this->assertNull($session->get(GuardSessionState::key('mfa_passed_at', 'admin')));
            $this->assertSame(20, $session->get(GuardSessionState::key('mfa_passed_at', 'customer')));
        } else {
            $this->assertNull($session->get(GuardSessionState::key('mfa_passed_at')));
        }

        event(new Logout('customer', $customer, false));
        if (config('auth.multi_guard')) {
            $this->assertNull($session->get(GuardSessionState::key('mfa_passed_at', 'customer')));
            $this->assertNull($session->get(GuardSessionState::key('mfa_temp_secret', 'customer')));
        } else {
            $this->assertNull($session->get(GuardSessionState::key('mfa_passed_at')));
        }
    }

    private function customer(array $overrides = []): User
    {
        return $this->createUser('user', $overrides);
    }

    private function createUser(string $role, array $overrides = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_merge([
            'username' => "{$role}-customer-mfa-{$sequence}",
            'name' => ucfirst($role).' Customer MFA',
            'email' => "{$role}-customer-mfa-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'access_version' => 1,
        ], $overrides));
    }
}
