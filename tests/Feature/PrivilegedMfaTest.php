<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MfaResetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

class PrivilegedMfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.brevo.key' => null]);
    }

    private function user(string $role, array $overrides = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_merge([
            'username' => "privileged-mfa-{$role}-{$sequence}",
            'name' => ucfirst($role).' MFA Test '.$sequence,
            'email' => "privileged-mfa-{$role}-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'permissions' => $role === 'staff' ? ['bookings.view'] : null,
            'access_version' => 1,
        ], $overrides));
    }

    private function activeTotpUser(string $role, array $overrides = []): array
    {
        $secret = (new Google2FA())->generateSecretKey();
        $user = $this->user($role, array_merge([
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
            'mfa_method' => 'totp',
            'mfa_allowed_methods' => 'totp',
        ], $overrides));

        return [$user, $secret];
    }

    private function signIn(User $user): self
    {
        $fresh = $user->fresh();
        $session = [
            'access_version_user_id' => $fresh->user_id,
            'access_version' => $fresh->access_version,
        ];
        if ($fresh->requiresMfa()) {
            $session['mfa_passed_at'] = now()->timestamp;
            $session['mfa_passed_user_id'] = $fresh->user_id;
        }

        return $this->actingAs($fresh)->withSession($session);
    }

    private function totp(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }

    private function sendJsonAction(self $test, string $method, string $url, array $payload)
    {
        return $method === 'PATCH'
            ? $test->patchJson($url, $payload)
            : $test->postJson($url, $payload);
    }

    public function test_direct_requests_reject_non_admins_self_targets_customers_and_view_as_admins(): void
    {
        $target = $this->user('staff');
        $actions = [
            ['POST', 'admin.users.mfa.disable', ['code' => '000000']],
            ['POST', 'admin.users.mfa.enable', []],
            ['PATCH', 'admin.users.mfa.allowed-methods', ['allowed_methods' => 'totp']],
        ];

        foreach (['owner', 'staff', 'user'] as $role) {
            foreach ($actions as [$method, $routeName, $payload]) {
                $actor = $this->user($role);
                $response = $this->sendJsonAction(
                    $this->signIn($actor),
                    $method,
                    route($routeName, ['user' => $target->user_id]),
                    $payload,
                );
                $this->assertContains($response->getStatusCode(), [401, 403], $role.' must not perform '.$routeName.'.');
            }
        }

        $customer = $this->user('user');
        foreach ($actions as [$method, $routeName, $payload]) {
            $selfTargetingAdmin = $this->user('admin');
            $this->sendJsonAction(
                $this->signIn($selfTargetingAdmin),
                $method,
                route($routeName, ['user' => $selfTargetingAdmin->user_id]),
                $payload,
            )->assertForbidden();

            $customerTargetAdmin = $this->user('admin');
            $this->sendJsonAction(
                $this->signIn($customerTargetAdmin),
                $method,
                route($routeName, ['user' => $customer->user_id]),
                $payload,
            )->assertForbidden();

            $viewAsAdmin = $this->user('admin');
            $this->signIn($viewAsAdmin)->withSession([
                'access_version_user_id' => $viewAsAdmin->user_id,
                'access_version' => $viewAsAdmin->access_version,
                'mfa_passed_at' => now()->timestamp,
                'mfa_passed_user_id' => $viewAsAdmin->user_id,
                'view_as' => 'staff',
            ]);
            $this->sendJsonAction(
                $this,
                $method,
                route($routeName, ['user' => $target->user_id]),
                $payload,
            )->assertForbidden();
        }
    }

    public function test_disable_requires_a_fresh_one_use_totp_and_invalidates_sessions(): void
    {
        [$admin, $secret] = $this->activeTotpUser('admin');
        $owner = $this->user('owner');
        [$target] = $this->activeTotpUser('staff');
        [$secondTarget] = $this->activeTotpUser('admin');
        $oldRememberToken = $target->remember_token;
        $code = $this->totp($secret);

        $this->signIn($admin)->postJson(
            route('admin.users.mfa.disable', ['user' => $target->user_id]),
            ['code' => '000000'],
        )->assertUnprocessable();
        $this->assertTrue($target->fresh()->hasMfaEnabled());

        $response = $this->signIn($admin)->postJson(
            route('admin.users.mfa.disable', ['user' => $target->user_id]),
            ['code' => $code],
        )->assertOk();

        $this->assertSame('disabled', $response->json('mfa_state.key'));
        $this->assertFalse($target->fresh()->hasMfaEnabled());
        $this->assertSame(2, (int) $target->fresh()->access_version);
        $this->assertNotSame($oldRememberToken, $target->fresh()->remember_token);
        $this->assertEqualsWithDelta(
            now()->addDay()->timestamp,
            $target->fresh()->mfa_admin_disabled_until->timestamp,
            2,
        );
        $this->assertDatabaseHas('notifications', [
            'user_id' => $target->user_id,
            'type' => 'privileged_mfa_disabled',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_disabled',
        ]);
        $disabledRow = collect($this->getJson(route('admin.users.index'))->json('users'))
            ->firstWhere('id', $target->user_id);
        $this->assertStringStartsWith('Disabled', $disabledRow['mfa_state_label']);

        $this->postJson(
            route('admin.users.mfa.disable', ['user' => $secondTarget->user_id]),
            ['code' => $code],
        )->assertUnprocessable();
        $this->assertTrue($secondTarget->fresh()->hasMfaEnabled());

        $this->postJson(
            route('admin.users.mfa.enable', ['user' => $target->user_id]),
        )->assertOk()->assertJsonPath('mfa_state.key', 'reenrollment_required');
        $this->assertTrue($target->fresh()->mfa_reenroll_required);
        $this->assertNull($target->fresh()->mfa_admin_disabled_until);
        $reenrollmentRow = collect($this->getJson(route('admin.users.index'))->json('users'))
            ->firstWhere('id', $target->user_id);
        $this->assertSame('Re-enrollment required', $reenrollmentRow['mfa_state_label']);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'privileged_mfa.reenrollment_required',
            'subject_id' => $target->user_id,
        ]);

        $this->signIn($target)->get('/staff/dashboard')
            ->assertRedirect(route(mfa_route_name('mfa.setup', config('auth.multi_guard') ? 'staff' : null)));
        $this->get(route(mfa_route_name('mfa.challenge', config('auth.multi_guard') ? 'staff' : null)))
            ->assertRedirect(route(mfa_route_name('mfa.setup', config('auth.multi_guard') ? 'staff' : null)));
    }

    public function test_owner_mfa_change_needs_a_second_admin_and_fresh_approval(): void
    {
        [$requestingAdmin] = $this->activeTotpUser('admin');
        [$approvingAdmin, $approverSecret] = $this->activeTotpUser('admin');
        [$owner] = $this->activeTotpUser('owner');

        $created = $this->signIn($requestingAdmin)->postJson(
            route('admin.users.mfa.owner-request', ['user' => $owner->user_id]),
            ['action' => 'disable'],
        )->assertCreated();
        $requestId = $created->json('request.id');
        $requestRecord = MfaResetRequest::findOrFail($requestId);
        $this->assertSame('pending', $requestRecord->status);
        $this->assertTrue($owner->fresh()->hasMfaEnabled());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_owner_request',
        ]);

        $this->postJson(
            route('admin.users.mfa.owner-approve', ['user' => $owner->user_id, 'requestId' => $requestId]),
            ['code' => '000000'],
        )->assertForbidden();
        $this->assertSame('pending', $requestRecord->fresh()->status);

        $this->signIn($approvingAdmin)->postJson(
            route('admin.users.mfa.owner-approve', ['user' => $owner->user_id, 'requestId' => $requestId]),
            ['code' => $this->totp($approverSecret)],
        )->assertOk();
        $this->assertSame('approved', $requestRecord->fresh()->status);
        $this->assertFalse($owner->fresh()->hasMfaEnabled());
        $this->assertNotNull($owner->fresh()->mfa_admin_disabled_until);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_owner_executed',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'privileged_mfa.owner_request_approved',
            'subject_id' => $owner->user_id,
        ]);
    }

    public function test_expired_owner_request_cannot_execute_and_is_audited(): void
    {
        [$requestingAdmin] = $this->activeTotpUser('admin');
        [$approvingAdmin] = $this->activeTotpUser('admin');
        [$owner] = $this->activeTotpUser('owner');
        $requestId = $this->signIn($requestingAdmin)->postJson(
            route('admin.users.mfa.owner-request', ['user' => $owner->user_id]),
            ['action' => 'disable'],
        )->assertCreated()->json('request.id');
        MfaResetRequest::whereKey($requestId)->update(['expires_at' => now()->subSecond()]);

        $this->signIn($approvingAdmin)->postJson(
            route('admin.users.mfa.owner-approve', ['user' => $owner->user_id, 'requestId' => $requestId]),
            ['code' => '000000'],
        )->assertGone();

        $this->assertTrue($owner->fresh()->hasMfaEnabled());
        $this->assertSame('expired', MfaResetRequest::findOrFail($requestId)->status);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'privileged_mfa.owner_request_approval',
            'subject_id' => $owner->user_id,
        ]);
    }

    public function test_owner_force_reenrollment_also_requires_two_real_admins(): void
    {
        [$requestingAdmin] = $this->activeTotpUser('admin');
        [$approvingAdmin, $approverSecret] = $this->activeTotpUser('admin');
        [$owner, $ownerSecret] = $this->activeTotpUser('owner');
        $requestId = $this->signIn($requestingAdmin)->postJson(
            route('admin.users.mfa.owner-request', ['user' => $owner->user_id]),
            ['action' => 'enable'],
        )->assertCreated()->json('request.id');

        $this->signIn($approvingAdmin)->postJson(
            route('admin.users.mfa.owner-approve', ['user' => $owner->user_id, 'requestId' => $requestId]),
            ['code' => $this->totp($approverSecret)],
        )->assertOk();

        $this->assertTrue($owner->fresh()->hasMfaEnabled());
        $this->assertSame($ownerSecret, $owner->fresh()->mfa_secret);
        $this->assertTrue($owner->fresh()->mfa_reenroll_required);
        $this->assertNull($owner->fresh()->mfa_admin_disabled_until);
    }

    public function test_allowed_methods_preserve_a_usable_totp_and_render_only_for_real_admins(): void
    {
        [$admin] = $this->activeTotpUser('admin');
        [$staff] = $this->activeTotpUser('staff');
        $customer = $this->user('user');

        $this->signIn($admin)->patchJson(
            route('admin.users.mfa.allowed-methods', ['user' => $staff->user_id]),
            ['allowed_methods' => 'both'],
        )->assertOk();
        $this->assertSame('both', $staff->fresh()->mfa_allowed_methods);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'privileged_mfa.allowed_methods_changed',
            'subject_id' => $staff->user_id,
        ]);

        $this->patchJson(
            route('admin.users.mfa.allowed-methods', ['user' => $staff->user_id]),
            ['allowed_methods' => 'passkey'],
        )->assertUnprocessable();
        $this->assertSame('both', $staff->fresh()->mfa_allowed_methods);

        $adminRows = $this->getJson(route('admin.users.index'))->assertOk()->json('users');
        $staffRow = collect($adminRows)->firstWhere('id', $staff->user_id);
        $customerRow = collect($adminRows)->firstWhere('id', $customer->user_id);
        $this->assertSame('Enabled', $staffRow['mfa_state_label']);
        $this->assertTrue($staffRow['mfa_actions']['allowed_methods']);
        $this->assertSame([], $customerRow['mfa_actions']);

        $owner = $this->user('owner');
        $ownerRows = $this->signIn($owner)->getJson(role_route('admin.users.index'))->assertOk()->json('users');
        $ownerStaffRow = collect($ownerRows)->firstWhere('id', $staff->user_id);
        $this->assertSame([], $ownerStaffRow['mfa_actions']);
    }

    public function test_strict_audit_failure_rolls_back_mfa_disable_and_notifications(): void
    {
        [$admin, $secret] = $this->activeTotpUser('admin');
        $owner = $this->user('owner');
        [$target] = $this->activeTotpUser('staff');

        Event::listen('eloquent.creating: '.ActivityLog::class, static function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->signIn($admin)->postJson(
                route('admin.users.mfa.disable', ['user' => $target->user_id]),
                ['code' => $this->totp($secret)],
            );
            $this->fail('The failed strict audit should abort the MFA change.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertTrue($target->fresh()->hasMfaEnabled());
        $this->assertNull($target->fresh()->mfa_admin_disabled_until);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $target->user_id,
            'type' => 'privileged_mfa_disabled',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_disabled',
        ]);
    }

    public function test_password_login_and_google_login_require_privileged_reenrollment(): void
    {
        foreach (['password', 'google'] as $loginMethod) {
            $target = $this->user('staff', [
                'mfa_admin_disabled_until' => now()->addHour(),
                'mfa_reenroll_required' => false,
                'mfa_enabled' => false,
                'mfa_secret' => null,
                'mfa_method' => null,
            ]);

            if ($loginMethod === 'password') {
                $this->postJson('/login', [
                    'username' => $target->username,
                    'password' => 'password',
                ])->assertOk()->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'setup');
            } else {
                $clientId = 'privileged-mfa-google-client';
                config(['services.google.client_id' => $clientId]);
                $token = $this->fakeGoogleToken($target->email, $target->name, $clientId);
                $this->postJson('/auth/google', ['id_token' => $token])
                    ->assertOk()->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'setup');
            }

            $this->assertNull($target->fresh()->mfa_admin_disabled_until);
            $this->assertTrue($target->fresh()->mfa_reenroll_required);
        }
    }

    public function test_expired_disable_forces_reenrollment_on_the_next_protected_request(): void
    {
        $admin = $this->user('admin', [
            'mfa_admin_disabled_until' => now()->subSecond(),
            'mfa_reenroll_required' => false,
            'mfa_enabled' => false,
        ]);
        $setupUrl = route(mfa_route_name('mfa.setup', config('auth.multi_guard') ? 'admin' : null));

        $this->signIn($admin)->get('/dashboard')->assertRedirect($setupUrl);
        $this->assertNull($admin->fresh()->mfa_admin_disabled_until);
        $this->assertTrue($admin->fresh()->mfa_reenroll_required);
    }

    public function test_pending_setup_login_remains_setup_and_mfa_setup_stores_recovery_hashes(): void
    {
        $admin = $this->user('admin');
        $this->postJson('/login', [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'setup');

        $setupInit = route(mfa_route_name('mfa.setup.init', config('auth.multi_guard') ? 'admin' : null));
        $enable = route(mfa_route_name('mfa.enable', config('auth.multi_guard') ? 'admin' : null));
        $init = $this->postJson($setupInit)->assertOk();
        $secret = $init->json('secret');
        $enabled = $this->postJson($enable, ['code' => $this->totp($secret)])->assertOk();

        $codes = $enabled->json('recovery_codes');
        $stored = $admin->fresh()->mfa_recovery_codes;
        $this->assertCount(8, $codes);
        foreach ($codes as $index => $code) {
            $this->assertStringStartsWith('$', $stored[$index]);
            $this->assertTrue(Hash::check($code, $stored[$index]));
        }

        $profileRecoveryUrl = role_route('admin.profile.mfa.recovery-codes');
        $newCodes = $this->post($profileRecoveryUrl, ['code' => $this->totp($secret)])
            ->assertRedirect()
            ->assertSessionHas('recovery_codes');
        $this->assertNotNull($newCodes);
        foreach ($admin->fresh()->mfa_recovery_codes as $hashedCode) {
            $this->assertStringStartsWith('$', $hashedCode);
        }
    }

    public function test_privileged_recovery_verifier_accepts_and_consumes_hashed_and_legacy_plaintext_codes(): void
    {
        $hashed = Hash::make('hashed-recovery');
        $plain = 'legacy-recovery';
        [$admin] = $this->activeTotpUser('admin', ['mfa_recovery_codes' => [$hashed, $plain]]);
        $verifyUrl = route(mfa_route_name('mfa.verify', config('auth.multi_guard') ? 'admin' : null));

        $this->signIn($admin)->postJson($verifyUrl, ['code' => 'hashed-recovery'])->assertOk();
        $this->assertSame([$plain], $admin->fresh()->mfa_recovery_codes);
        $this->postJson($verifyUrl, ['code' => 'hashed-recovery'])->assertUnprocessable();

        $this->postJson($verifyUrl, ['code' => $plain])->assertOk();
        $this->assertSame([], $admin->fresh()->mfa_recovery_codes);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'privileged_mfa.recovery_code_used',
            'subject_id' => $admin->user_id,
        ]);
    }

    private function fakeGoogleToken(string $email, string $name, string $clientId): string
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        foreach ([
            getenv('OPENSSL_CONF') ?: null,
            'C:\\xampp\\php\\extras\\openssl\\openssl.cnf',
            'C:\\xampp\\apache\\conf\\openssl.cnf',
        ] as $candidate) {
            if ($candidate && is_file($candidate)) {
                $config['config'] = $candidate;
                break;
            }
        }

        $key = openssl_pkey_new($config);
        $this->assertNotFalse($key, 'openssl_pkey_new failed: '.openssl_error_string());
        $details = openssl_pkey_get_details($key);
        $base64Url = static fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $base64Url(json_encode(['alg' => 'RS256', 'kid' => 'privileged-mfa-test', 'typ' => 'JWT']));
        $payload = $base64Url(json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => $clientId,
            'exp' => now()->addHour()->timestamp,
            'email' => $email,
            'email_verified' => true,
            'name' => $name,
            'jti' => bin2hex(random_bytes(8)),
        ]));
        openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);
        Http::fake([
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [[
                'kid' => 'privileged-mfa-test',
                'alg' => 'RS256',
                'kty' => 'RSA',
                'use' => 'sig',
                'n' => $base64Url($details['rsa']['n']),
                'e' => $base64Url($details['rsa']['e']),
            ]]]),
        ]);

        return $header.'.'.$payload.'.'.$base64Url($signature);
    }
}
