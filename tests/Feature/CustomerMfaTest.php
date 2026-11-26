<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\GuardSessionState;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
