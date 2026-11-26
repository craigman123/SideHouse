<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MfaResetRequest;
use App\Models\User;
use App\Support\GuardSessionState;
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
            ['POST', 'admin.users.mfa.enable', ['code' => '000000']],
            ['POST', 'admin.users.mfa.force-reenrollment', ['code' => '000000']],
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

    public function test_disable_requires_fresh_totp_and_preserves_sessions_and_saved_factors(): void
    {
        [$admin, $secret] = $this->activeTotpUser('admin');
        $owner = $this->user('owner');
        [$target] = $this->activeTotpUser('staff', [
            'mfa_recovery_codes' => ['stored-recovery-code'],
        ]);
        [$secondTarget] = $this->activeTotpUser('admin');
        $savedSecret = $target->mfa_secret;
        $savedRecoveryCodes = $target->mfa_recovery_codes;
        $oldRememberToken = $target->remember_token;
        $oldAccessVersion = (int) $target->access_version;
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
        $this->assertTrue($target->fresh()->hasMfaEnabled());
        $this->assertSame($savedSecret, $target->fresh()->mfa_secret);
        $this->assertSame($savedRecoveryCodes, $target->fresh()->mfa_recovery_codes);
        $this->assertSame($oldAccessVersion, (int) $target->fresh()->access_version);
        $this->assertSame($oldRememberToken, $target->fresh()->remember_token);
        $this->assertEqualsWithDelta(
            now()->addHours(24)->timestamp,
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
        $this->assertMatchesRegularExpression('/^Disabled \([0-9]+h left\)$/', $disabledRow['mfa_state_label']);

        $this->postJson(
            route('admin.users.mfa.disable', ['user' => $secondTarget->user_id]),
            ['code' => $code],
        )->assertUnprocessable();
        $this->assertTrue($secondTarget->fresh()->hasMfaEnabled());
        $this->signIn($target)->get('/staff/dashboard')->assertOk();
    }

    public function test_enable_requires_fresh_totp_and_restores_the_saved_authenticator_challenge(): void
    {
        [$admin, $adminSecret] = $this->activeTotpUser('admin');
        $owner = $this->user('owner');
        [$target, $targetSecret] = $this->activeTotpUser('staff', [
            'mfa_admin_disabled_until' => now()->addHours(12),
            'mfa_recovery_codes' => ['stored-recovery-code'],
        ]);
        $oldAccessVersion = (int) $target->access_version;
        $oldRememberToken = $target->remember_token;
        [$secondTarget] = $this->activeTotpUser('staff', [
            'mfa_admin_disabled_until' => now()->addHours(12),
        ]);
        $savedRecoveryCodes = $target->mfa_recovery_codes;
        $code = $this->totp($adminSecret);

        $this->signIn($admin)->postJson(
            route('admin.users.mfa.enable', ['user' => $target->user_id]),
            ['code' => '000000'],
        )->assertUnprocessable();
        $this->assertNotNull($target->fresh()->mfa_admin_disabled_until);

        $this->postJson(
            route('admin.users.mfa.enable', ['user' => $target->user_id]),
            ['code' => $code],
        )->assertOk()->assertJsonPath('mfa_state.key', 'enabled');

        $this->assertTrue($target->fresh()->hasMfaEnabled());
        $this->assertSame($targetSecret, $target->fresh()->mfa_secret);
        $this->assertSame($savedRecoveryCodes, $target->fresh()->mfa_recovery_codes);
        $this->assertNull($target->fresh()->mfa_admin_disabled_until);
        $this->assertSame($oldAccessVersion + 1, (int) $target->fresh()->access_version);
        $this->assertNotSame($oldRememberToken, $target->fresh()->remember_token);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'privileged_mfa.enabled',
            'subject_id' => $target->user_id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $target->user_id,
            'type' => 'privileged_mfa_enabled',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_enabled',
        ]);

        $this->postJson(
            route('admin.users.mfa.enable', ['user' => $secondTarget->user_id]),
            ['code' => $code],
        )->assertUnprocessable();
        $this->assertNotNull($secondTarget->fresh()->mfa_admin_disabled_until);

        $this->postJson('/login', [
            'username' => $target->username,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'challenge');
    }

    public function test_force_reenrollment_erases_factors_invalidates_sessions_and_restricts_login(): void
    {
        [$admin, $adminSecret] = $this->activeTotpUser('admin');
        $owner = $this->user('owner');
        [$target] = $this->activeTotpUser('staff', [
            'mfa_recovery_codes' => ['stored-recovery-code'],
        ]);
        [$secondTarget] = $this->activeTotpUser('staff');
        $oldAccessVersion = (int) $target->access_version;
        $oldRememberToken = $target->remember_token;
        $code = $this->totp($adminSecret);

        $this->signIn($admin)->postJson(
            route('admin.users.mfa.force-reenrollment', ['user' => $target->user_id]),
            ['code' => '000000'],
        )->assertUnprocessable();
        $this->assertTrue($target->fresh()->hasMfaEnabled());

        $this->postJson(
            route('admin.users.mfa.force-reenrollment', ['user' => $target->user_id]),
            ['code' => $code],
        )->assertOk()->assertJsonPath('mfa_state.key', 'reenrollment_required');

        $this->assertFalse($target->fresh()->hasMfaEnabled());
        $this->assertNull($target->fresh()->mfa_secret);
        $this->assertNull($target->fresh()->mfa_recovery_codes);
        $this->assertTrue($target->fresh()->mfa_reenroll_required);
        $this->assertSame($oldAccessVersion + 1, (int) $target->fresh()->access_version);
        $this->assertNotSame($oldRememberToken, $target->fresh()->remember_token);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'privileged_mfa.reenrollment_required',
            'subject_id' => $target->user_id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $target->user_id,
            'type' => 'privileged_mfa_reenrollment',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_reenrollment',
        ]);

        $this->postJson(
            route('admin.users.mfa.force-reenrollment', ['user' => $secondTarget->user_id]),
            ['code' => $code],
        )->assertUnprocessable();
        $this->assertTrue($secondTarget->fresh()->hasMfaEnabled());

        $guard = config('auth.multi_guard') ? 'staff' : null;
        $session = [
            GuardSessionState::key('access_version_user_id', $guard) => $target->user_id,
            GuardSessionState::key('access_version', $guard) => $oldAccessVersion,
            GuardSessionState::key('mfa_passed_at', $guard) => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id', $guard) => $target->user_id,
        ];
        $this->actingAs($target, $guard ?? 'web')->withSession($session)->get('/staff/dashboard')
            ->assertRedirect(route(config('auth.multi_guard') ? 'staff.login' : 'login'));

        $this->postJson('/login', [
            'username' => $target->username,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'setup');
        $setupUrl = route(mfa_route_name('mfa.setup', $guard));
        $this->get('/staff/dashboard')->assertRedirect($setupUrl);
        $this->get(route(mfa_route_name('mfa.challenge', $guard)))->assertRedirect($setupUrl);
    }

    public function test_owner_mfa_change_needs_a_second_admin_and_fresh_approval(): void
    {
        [$requestingAdmin] = $this->activeTotpUser('admin');
        [$approvingAdmin, $approverSecret] = $this->activeTotpUser('admin');
        [$owner, $ownerSecret] = $this->activeTotpUser('owner', [
            'mfa_recovery_codes' => ['stored-recovery-code'],
        ]);
        $ownerRecoveryCodes = $owner->mfa_recovery_codes;
        $ownerAccessVersion = (int) $owner->access_version;

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
        $this->assertTrue($owner->fresh()->hasMfaEnabled());
        $this->assertSame($ownerSecret, $owner->fresh()->mfa_secret);
        $this->assertSame($ownerRecoveryCodes, $owner->fresh()->mfa_recovery_codes);
        $this->assertNotNull($owner->fresh()->mfa_admin_disabled_until);
        $this->assertSame($ownerAccessVersion, (int) $owner->fresh()->access_version);
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
        [$owner] = $this->activeTotpUser('owner', [
            'mfa_recovery_codes' => ['stored-recovery-code'],
        ]);
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
        [$owner] = $this->activeTotpUser('owner', [
            'mfa_recovery_codes' => ['stored-recovery-code'],
        ]);
        $ownerAccessVersion = (int) $owner->access_version;
        $requestId = $this->signIn($requestingAdmin)->postJson(
            route('admin.users.mfa.owner-request', ['user' => $owner->user_id]),
            ['action' => 'force_reenroll'],
        )->assertCreated()->json('request.id');

        $this->signIn($approvingAdmin)->postJson(
            route('admin.users.mfa.owner-approve', ['user' => $owner->user_id, 'requestId' => $requestId]),
            ['code' => $this->totp($approverSecret)],
        )->assertOk();

        $this->assertFalse($owner->fresh()->hasMfaEnabled());
        $this->assertNull($owner->fresh()->mfa_secret);
        $this->assertNull($owner->fresh()->mfa_recovery_codes);
        $this->assertTrue($owner->fresh()->mfa_reenroll_required);
        $this->assertNull($owner->fresh()->mfa_admin_disabled_until);
        $this->assertSame($ownerAccessVersion + 1, (int) $owner->fresh()->access_version);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_owner_executed',
        ]);
    }

    public function test_allowed_methods_preserve_a_usable_totp_and_render_only_for_real_admins(): void
    {
        [$admin] = $this->activeTotpUser('admin');
        [$staff] = $this->activeTotpUser('staff');
        [$disabledStaff] = $this->activeTotpUser('staff', [
            'mfa_admin_disabled_until' => now()->addHours(8),
        ]);
        $pendingStaff = $this->user('staff');
        [$reenrollmentStaff] = $this->activeTotpUser('staff', [
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
            'mfa_reenroll_required' => true,
        ]);
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
        $disabledRow = collect($adminRows)->firstWhere('id', $disabledStaff->user_id);
        $pendingRow = collect($adminRows)->firstWhere('id', $pendingStaff->user_id);
        $reenrollmentRow = collect($adminRows)->firstWhere('id', $reenrollmentStaff->user_id);
        $customerRow = collect($adminRows)->firstWhere('id', $customer->user_id);
        $this->assertSame('Enabled', $staffRow['mfa_state_label']);
        $this->assertTrue($staffRow['mfa_actions']['allowed_methods']);
        $this->assertSame('disabled', $disabledRow['mfa_state']);
        $this->assertFalse($disabledRow['mfa_actions']['disable']);
        $this->assertTrue($disabledRow['mfa_actions']['enable']);
        $this->assertTrue($disabledRow['mfa_actions']['force_reenroll']);
        $this->assertSame('Pending setup', $pendingRow['mfa_state_label']);
        $this->assertTrue($pendingRow['mfa_actions']['disable']);
        $this->assertSame('reenrollment_required', $reenrollmentRow['mfa_state']);
        $this->assertFalse($reenrollmentRow['mfa_actions']['force_reenroll']);
        $this->assertSame([], $customerRow['mfa_actions']);

        $owner = $this->user('owner');
        $ownerRows = $this->signIn($owner)->getJson(role_route('admin.users.index'))->assertOk()->json('users');
        $ownerStaffRow = collect($ownerRows)->firstWhere('id', $staff->user_id);
        $this->assertSame([], $ownerStaffRow['mfa_actions']);

        $page = $this->signIn($admin)->get(role_route('admin.user-access.page'))->assertOk();
        $page->assertSee('data-mfa-force-reenrollment-url-template', false);
        $page->assertSee(role_route('admin.users.mfa.force-reenrollment', ['user' => '__USER__']), false);
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

    public function test_strict_audit_failure_rolls_back_mfa_enable_and_notifications(): void
    {
        [$admin, $secret] = $this->activeTotpUser('admin');
        $owner = $this->user('owner');
        [$target] = $this->activeTotpUser('staff', [
            'mfa_admin_disabled_until' => now()->addHours(4),
        ]);
        $disabledUntil = $target->mfa_admin_disabled_until;

        Event::listen('eloquent.creating: '.ActivityLog::class, static function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->signIn($admin)->postJson(
                route('admin.users.mfa.enable', ['user' => $target->user_id]),
                ['code' => $this->totp($secret)],
            );
            $this->fail('The failed strict audit should abort the MFA change.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertTrue($target->fresh()->hasMfaEnabled());
        $this->assertEquals($disabledUntil, $target->fresh()->mfa_admin_disabled_until);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $target->user_id,
            'type' => 'privileged_mfa_enabled',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_enabled',
        ]);
    }

    public function test_strict_audit_failure_rolls_back_force_reenrollment_and_notifications(): void
    {
        [$admin, $secret] = $this->activeTotpUser('admin');
        $owner = $this->user('owner');
        [$target] = $this->activeTotpUser('staff', [
            'mfa_recovery_codes' => ['stored-recovery-code'],
        ]);
        $savedSecret = $target->mfa_secret;
        $savedRecoveryCodes = $target->mfa_recovery_codes;
        $accessVersion = (int) $target->access_version;

        Event::listen('eloquent.creating: '.ActivityLog::class, static function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->signIn($admin)->postJson(
                route('admin.users.mfa.force-reenrollment', ['user' => $target->user_id]),
                ['code' => $this->totp($secret)],
            );
            $this->fail('The failed strict audit should abort the MFA change.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertTrue($target->fresh()->hasMfaEnabled());
        $this->assertSame($savedSecret, $target->fresh()->mfa_secret);
        $this->assertSame($savedRecoveryCodes, $target->fresh()->mfa_recovery_codes);
        $this->assertSame($accessVersion, (int) $target->fresh()->access_version);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $target->user_id,
            'type' => 'privileged_mfa_reenrollment',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_reenrollment',
        ]);
    }

    public function test_strict_audit_failure_rolls_back_allowed_method_changes_and_notifications(): void
    {
        [$admin] = $this->activeTotpUser('admin');
        $owner = $this->user('owner');
        [$target] = $this->activeTotpUser('staff');

        Event::listen('eloquent.creating: '.ActivityLog::class, static function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->signIn($admin)->patchJson(
                route('admin.users.mfa.allowed-methods', ['user' => $target->user_id]),
                ['allowed_methods' => 'both'],
            );
            $this->fail('The failed strict audit should abort the allowed-method change.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertSame('totp', $target->fresh()->mfa_allowed_methods);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $target->user_id,
            'type' => 'privileged_mfa_methods',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_methods',
        ]);
    }

    public function test_strict_audit_failure_rolls_back_owner_request_and_approval(): void
    {
        [$requestingAdmin] = $this->activeTotpUser('admin');
        [$approvingAdmin, $approverSecret] = $this->activeTotpUser('admin');
        [$owner] = $this->activeTotpUser('owner');

        Event::listen('eloquent.creating: '.ActivityLog::class, static function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->signIn($requestingAdmin)->postJson(
                route('admin.users.mfa.owner-request', ['user' => $owner->user_id]),
                ['action' => 'disable'],
            );
            $this->fail('The failed strict audit should abort the owner MFA request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertSame(0, MfaResetRequest::where('target_user_id', $owner->user_id)->count());
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_owner_request',
        ]);
    }

    public function test_strict_audit_failure_rolls_back_owner_approval_and_execution(): void
    {
        [$requestingAdmin] = $this->activeTotpUser('admin');
        [$approvingAdmin, $approverSecret] = $this->activeTotpUser('admin');
        [$owner, $ownerSecret] = $this->activeTotpUser('owner');
        $requestId = $this->signIn($requestingAdmin)->postJson(
            route('admin.users.mfa.owner-request', ['user' => $owner->user_id]),
            ['action' => 'disable'],
        )->assertCreated()->json('request.id');

        Event::listen('eloquent.creating: '.ActivityLog::class, static function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->signIn($approvingAdmin)->postJson(
                route('admin.users.mfa.owner-approve', ['user' => $owner->user_id, 'requestId' => $requestId]),
                ['code' => $this->totp($approverSecret)],
            );
            $this->fail('The failed strict audit should abort owner MFA approval.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertSame('pending', MfaResetRequest::findOrFail($requestId)->status);
        $this->assertTrue($owner->fresh()->hasMfaEnabled());
        $this->assertSame($ownerSecret, $owner->fresh()->mfa_secret);
        $this->assertNull($owner->fresh()->mfa_admin_disabled_until);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'privileged_mfa_owner_executed',
        ]);
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'privileged_mfa.owner_request_approved',
            'subject_id' => $owner->user_id,
        ]);
    }

    public function test_disabled_accounts_bypass_password_and_google_mfa_without_losing_their_factors(): void
    {
        foreach (['password', 'google'] as $loginMethod) {
            [$target, $secret] = $this->activeTotpUser('staff', [
                'mfa_admin_disabled_until' => now()->addHours(23),
            ]);

            if ($loginMethod === 'password') {
                $response = $this->postJson('/login', [
                    'username' => $target->username,
                    'password' => 'password',
                ])->assertOk();
            } else {
                $clientId = 'privileged-mfa-google-client';
                config(['services.google.client_id' => $clientId]);
                $token = $this->fakeGoogleToken($target->email, $target->name, $clientId);
                $response = $this->postJson('/auth/google', ['id_token' => $token])->assertOk();
            }

            $response->assertJsonMissingPath('mfa_required')
                ->assertJsonPath('redirect', '/staff/dashboard');
            $this->get('/staff/dashboard')->assertOk();
            $this->assertTrue($target->fresh()->hasMfaEnabled());
            $this->assertSame($secret, $target->fresh()->mfa_secret);
            $this->assertNotNull($target->fresh()->mfa_admin_disabled_until);
            $this->assertFalse((bool) $target->fresh()->mfa_reenroll_required);
        }
    }

    public function test_disabled_accounts_cannot_create_or_assert_a_factor(): void
    {
        [$staff, $secret] = $this->activeTotpUser('staff', [
            'mfa_admin_disabled_until' => now()->addHours(6),
            'mfa_recovery_codes' => ['stored-recovery-code'],
        ]);
        $recoveryCodes = $staff->mfa_recovery_codes;
        $guard = config('auth.multi_guard') ? 'staff' : null;

        $this->signIn($staff)->get(route(mfa_route_name('mfa.setup', $guard)))
            ->assertRedirect(route('staff.dashboard'));
        $this->postJson(route(mfa_route_name('mfa.setup.init', $guard)))->assertStatus(409);
        $this->postJson(route(mfa_route_name('mfa.enable', $guard)), ['code' => $this->totp($secret)])
            ->assertStatus(409);
        $this->get(route(mfa_route_name('mfa.challenge', $guard)))
            ->assertRedirect(route('staff.dashboard'));
        $this->postJson(route(mfa_route_name('mfa.verify', $guard)), ['code' => $this->totp($secret)])
            ->assertStatus(409);
        $this->assertTrue($staff->fresh()->hasMfaEnabled());
        $this->assertSame($secret, $staff->fresh()->mfa_secret);
        $this->assertSame($recoveryCodes, $staff->fresh()->mfa_recovery_codes);
        $this->assertNotNull($staff->fresh()->mfa_admin_disabled_until);
    }

    public function test_disabled_accounts_cannot_regenerate_recovery_codes(): void
    {
        [$staff, $secret] = $this->activeTotpUser('staff', [
            'mfa_admin_disabled_until' => now()->addHours(6),
            'mfa_recovery_codes' => ['stored-recovery-code'],
        ]);
        $recoveryCodes = $staff->mfa_recovery_codes;

        $this->signIn($staff)
            ->postJson(role_route('admin.profile.mfa.recovery-codes'), ['code' => $this->totp($secret)])
            ->assertStatus(409);

        $this->assertSame($recoveryCodes, $staff->fresh()->mfa_recovery_codes);
    }

    public function test_expired_disable_restores_the_saved_authenticator_challenge(): void
    {
        foreach (['password', 'google'] as $loginMethod) {
            [$admin, $secret] = $this->activeTotpUser('admin', [
                'mfa_admin_disabled_until' => now()->subSecond(),
                'mfa_reenroll_required' => false,
            ]);

            if ($loginMethod === 'password') {
                $response = $this->postJson('/login', [
                    'username' => $admin->username,
                    'password' => 'password',
                ])->assertOk();
            } else {
                $clientId = 'expired-disable-google-client';
                config(['services.google.client_id' => $clientId]);
                $token = $this->fakeGoogleToken($admin->email, $admin->name, $clientId);
                $response = $this->postJson('/auth/google', ['id_token' => $token])->assertOk();
            }

            $response->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'challenge');
            $challengeUrl = route(mfa_route_name('mfa.challenge', config('auth.multi_guard') ? 'admin' : null));
            $this->get($challengeUrl)->assertOk();
            $this->assertNull($admin->fresh()->mfa_admin_disabled_until);
            $this->assertSame($secret, $admin->fresh()->mfa_secret);
            $this->assertFalse($admin->fresh()->mfa_reenroll_required);
        }
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
