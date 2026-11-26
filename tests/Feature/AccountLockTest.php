<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MfaResetRequest;
use App\Models\User;
use App\Support\GuardSessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

class AccountLockTest extends TestCase
{
    use RefreshDatabase;

    private $googlePrivateKey;
    private array $googlePublicDetails;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.brevo.key' => null]);
    }

    private function user(string $role, array $overrides = []): User
    {
        static $sequence = 0;
        $sequence++;
        $attributes = [
            'username' => "account-lock-{$role}-{$sequence}",
            'name' => ucfirst($role).' Account Lock '.$sequence,
            'email' => "account-lock-{$role}-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'permissions' => $role === 'staff' ? ['bookings.view'] : null,
            'access_version' => 1,
        ];
        if (in_array($role, ['admin', 'owner', 'staff'], true)) {
            $attributes += [
                'mfa_enabled' => true,
                'mfa_secret' => (new Google2FA())->generateSecretKey(),
                'mfa_method' => 'totp',
                'mfa_allowed_methods' => 'totp',
            ];
        }

        return User::create(array_merge($attributes, $overrides));
    }

    private function signIn(User $user): self
    {
        $fresh = $user->fresh();
        $guard = config('auth.multi_guard')
            ? ($fresh->role === 'user' ? 'customer' : $fresh->role)
            : null;
        $session = [
            GuardSessionState::key('access_version_user_id', $guard) => $fresh->user_id,
            GuardSessionState::key('access_version', $guard) => $fresh->access_version,
        ];
        if ($fresh->requiresMfa()) {
            $session[GuardSessionState::key('mfa_passed_at', $guard)] = now()->timestamp;
            $session[GuardSessionState::key('mfa_passed_user_id', $guard)] = $fresh->user_id;
        }

        return $this->actingAs($fresh, $guard ?? config('auth.defaults.guard'))->withSession($session);
    }

    private function totp(User $user): string
    {
        return (new Google2FA())->getCurrentOtp($user->fresh()->mfa_secret);
    }

    private function permitCurrentTotpForNextAction(User $user): void
    {
        $user->forceFill([
            'mfa_last_admin_action_step' => max(0, (new Google2FA())->getTimestamp() - 1),
        ])->save();
    }

    private function lockUrl(User $user): string
    {
        if (config('auth.multi_guard') && Auth::getDefaultDriver() === 'owner') {
            return route('owner.users.lock', ['user' => $user->user_id]);
        }

        return route('admin.users.lock', ['user' => $user->user_id]);
    }

    private function promoteUrl(User $user): string
    {
        if (config('auth.multi_guard') && Auth::getDefaultDriver() === 'owner') {
            return route('owner.users.promote-again', ['user' => $user->user_id]);
        }

        return route('admin.users.promote-again', ['user' => $user->user_id]);
    }

    public function test_lock_and_promote_reuse_edit_access_permissions_and_preserve_mfa(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('owner');
        $target = $this->user('staff', ['mfa_recovery_codes' => ['stored-recovery-code']]);
        $secret = $target->mfa_secret;
        $recoveryCodes = $target->mfa_recovery_codes;
        $accessVersion = (int) $target->access_version;
        $rememberToken = $target->remember_token;
        $code = $this->totp($admin);

        $this->signIn($admin)->postJson($this->lockUrl($target), [
            'code' => '000000',
            'reason' => 'Repeated unauthorized access attempts',
        ])->assertUnprocessable();
        $this->assertNull($target->fresh()->locked_at);

        $this->signIn($admin)->postJson($this->lockUrl($target), [
            'code' => $code,
            'reason' => 'Repeated unauthorized access attempts',
        ])->assertOk()->assertJsonPath('message', 'Account locked.');

        $locked = $target->fresh();
        $this->assertNotNull($locked->locked_at);
        $this->assertSame($admin->user_id, $locked->locked_by);
        $this->assertSame('Repeated unauthorized access attempts', $locked->locked_reason);
        $this->assertSame($accessVersion + 1, (int) $locked->access_version);
        $this->assertNotSame($rememberToken, $locked->remember_token);
        $this->assertTrue($locked->hasMfaEnabled());
        $this->assertSame($secret, $locked->mfa_secret);
        $this->assertSame($recoveryCodes, $locked->mfa_recovery_codes);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->user_id,
            'type' => 'account_locked',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'account_locked',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $target->user_id,
            'type' => 'account_locked',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user_access.account_lock.success',
            'user_id' => $admin->user_id,
            'subject_id' => $target->user_id,
        ]);

        $row = collect($this->getJson(route('admin.users.index'))->json('users'))
            ->firstWhere('id', $target->user_id);
        $this->assertTrue($row['locked']);
        $this->assertTrue($row['account_lock_actions']['promote']);
        $this->assertTrue($row['mfa_actions']['disable']);
        $this->assertTrue($row['mfa_actions']['force_reenroll']);

        $anotherTarget = $this->user('staff');
        $this->postJson($this->lockUrl($anotherTarget), ['code' => $code])->assertUnprocessable();
        $this->assertNull($anotherTarget->fresh()->locked_at);

        $this->permitCurrentTotpForNextAction($admin);
        $newCode = $this->totp($admin);
        $this->signIn($admin)->postJson($this->promoteUrl($target), ['code' => $newCode])
            ->assertOk()
            ->assertJsonPath('message', 'Account promoted again. Normal MFA requirements apply.');

        $promoted = $target->fresh();
        $this->assertNull($promoted->locked_at);
        $this->assertNull($promoted->locked_by);
        $this->assertNull($promoted->locked_reason);
        $this->assertSame($accessVersion + 2, (int) $promoted->access_version);
        $this->assertTrue($promoted->hasMfaEnabled());
        $this->assertSame($secret, $promoted->mfa_secret);
        $this->assertSame($recoveryCodes, $promoted->mfa_recovery_codes);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->user_id,
            'type' => 'account_promoted_again',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $target->user_id,
            'type' => 'account_promoted_again',
        ]);
    }

    public function test_lock_permissions_match_edit_access_for_each_actor_and_target_role(): void
    {
        $roles = ['admin', 'owner', 'staff', 'user'];

        foreach ($roles as $actorRole) {
            foreach ($roles as $targetRole) {
                $actor = $this->user($actorRole);
                $target = $this->user($targetRole);
                if ($targetRole === 'owner') {
                    $this->user('owner');
                }

                $response = $this->signIn($actor)->postJson($this->lockUrl($target), [
                    'code' => $actor->hasMfaEnabled() ? $this->totp($actor) : '000000',
                ]);
                $allowed = match ($actorRole) {
                    'admin' => in_array($targetRole, ['admin', 'owner', 'staff'], true),
                    'owner' => $targetRole === 'staff',
                    default => false,
                };
                if ($allowed && $targetRole === 'owner') {
                    $response->assertCreated();
                    $this->assertNull($target->fresh()->locked_at);
                    $this->assertDatabaseHas('mfa_reset_requests', [
                        'target_user_id' => $target->user_id,
                        'action' => 'account_lock',
                    ]);
                } elseif ($allowed) {
                    $response->assertOk();
                    $this->assertNotNull($target->fresh()->locked_at);
                } else {
                    $this->assertContains($response->getStatusCode(), [401, 403, 404], "{$actorRole} must not lock {$targetRole}.");
                    $this->assertNull($target->fresh()->locked_at);
                }
            }
        }

        foreach (['admin', 'owner'] as $role) {
            $actor = $this->user($role);
            $this->signIn($actor)->postJson($this->lockUrl($actor), [
                'code' => $this->totp($actor),
            ])->assertForbidden();
        }

        $admin = $this->user('admin');
        $customer = $this->user('user');
        $this->signIn($admin)->postJson($this->lockUrl($customer), [
            'code' => $this->totp($admin),
        ])->assertNotFound();

        $viewAsAdmin = $this->user('admin');
        $target = $this->user('staff');
        $this->signIn($viewAsAdmin)->withSession([
            GuardSessionState::key('access_version_user_id', config('auth.multi_guard') ? 'admin' : null) => $viewAsAdmin->user_id,
            GuardSessionState::key('access_version', config('auth.multi_guard') ? 'admin' : null) => $viewAsAdmin->access_version,
            GuardSessionState::key('mfa_passed_at', config('auth.multi_guard') ? 'admin' : null) => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id', config('auth.multi_guard') ? 'admin' : null) => $viewAsAdmin->user_id,
            GuardSessionState::key('view_as', config('auth.multi_guard') ? 'admin' : null) => 'owner',
        ]);
        $this->postJson($this->lockUrl($target), ['code' => $this->totp($viewAsAdmin)])
            ->assertForbidden();
        $this->assertNull($target->fresh()->locked_at);
    }

    public function test_owner_lock_and_promotion_use_two_distinct_admin_approval(): void
    {
        $requestingAdmin = $this->user('admin');
        $approvingAdmin = $this->user('admin');
        $owner = $this->user('owner');
        $otherOwner = $this->user('owner');
        $savedSecret = $owner->mfa_secret;

        $request = $this->signIn($requestingAdmin)->postJson($this->lockUrl($owner), [
            'code' => $this->totp($requestingAdmin),
            'reason' => 'Owner account requires review',
        ])->assertCreated();
        $requestId = $request->json('request.id');
        $this->assertDatabaseHas('mfa_reset_requests', [
            'id' => $requestId,
            'action' => 'account_lock',
            'reason' => 'Owner account requires review',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $requestingAdmin->user_id,
            'type' => 'account_lock_requested',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $otherOwner->user_id,
            'type' => 'account_lock_requested',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'account_lock_requested',
        ]);
        $this->assertNull($owner->fresh()->locked_at);

        $approveUrl = route('admin.users.lock-approve', [
            'user' => $owner->user_id,
            'requestId' => $requestId,
        ]);
        $this->signIn($requestingAdmin)->postJson($approveUrl, [
            'code' => $this->totp($requestingAdmin),
        ])->assertForbidden();

        $this->signIn($approvingAdmin)->postJson($approveUrl, [
            'code' => $this->totp($approvingAdmin),
        ])->assertOk();
        $this->assertNotNull($owner->fresh()->locked_at);
        $this->assertSame($approvingAdmin->user_id, $owner->fresh()->locked_by);
        $this->assertSame($savedSecret, $owner->fresh()->mfa_secret);
        $this->assertDatabaseHas('mfa_reset_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'approved_by_user_id' => $approvingAdmin->user_id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->user_id,
            'type' => 'account_locked',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $requestingAdmin->user_id,
            'type' => 'account_locked',
        ]);

        $this->permitCurrentTotpForNextAction($requestingAdmin);
        $promotionRequest = $this->signIn($requestingAdmin)->postJson($this->promoteUrl($owner), [
            'code' => $this->totp($requestingAdmin),
        ])->assertCreated();
        $promotionId = $promotionRequest->json('request.id');
        $this->permitCurrentTotpForNextAction($approvingAdmin);
        $this->signIn($approvingAdmin)->postJson(route('admin.users.lock-approve', [
            'user' => $owner->user_id,
            'requestId' => $promotionId,
        ]), [
            'code' => $this->totp($approvingAdmin),
        ])->assertOk();
        $this->assertNull($owner->fresh()->locked_at);
        $this->assertNull($owner->fresh()->locked_reason);
        $this->assertSame($savedSecret, $owner->fresh()->mfa_secret);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user_access.account_promote.success',
            'subject_id' => $owner->user_id,
        ]);
    }

    public function test_strict_audit_failure_rolls_back_lock_and_notifications(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('owner');
        $target = $this->user('staff');
        $accessVersion = (int) $target->access_version;

        Event::listen('eloquent.creating: '.ActivityLog::class, static function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->signIn($admin)->postJson($this->lockUrl($target), [
                'code' => $this->totp($admin),
            ]);
            $this->fail('A failed strict audit must abort locking.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertNull($target->fresh()->locked_at);
        $this->assertSame($accessVersion, (int) $target->fresh()->access_version);
        $this->assertDatabaseMissing('notifications', ['user_id' => $admin->user_id, 'type' => 'account_locked']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $owner->user_id, 'type' => 'account_locked']);
    }

    public function test_strict_audit_failure_rolls_back_promote_again_and_notifications(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('owner');
        $target = $this->user('staff', [
            'locked_at' => now(),
            'locked_by' => $admin->user_id,
            'locked_reason' => 'review',
            'access_version' => 2,
        ]);
        $rememberToken = $target->remember_token;

        Event::listen('eloquent.creating: '.ActivityLog::class, static function (): void {
            throw new RuntimeException('Audit storage unavailable.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->signIn($admin)->postJson($this->promoteUrl($target), [
                'code' => $this->totp($admin),
            ]);
            $this->fail('A failed strict audit must abort promotion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertNotNull($target->fresh()->locked_at);
        $this->assertSame(2, (int) $target->fresh()->access_version);
        $this->assertSame($rememberToken, $target->fresh()->remember_token);
        $this->assertDatabaseMissing('notifications', ['user_id' => $admin->user_id, 'type' => 'account_promoted_again']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $owner->user_id, 'type' => 'account_promoted_again']);
    }

    public function test_lock_sends_brevo_notifications_to_admin_and_owner_but_not_target(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response([], 201),
        ]);
        config(['services.brevo.key' => 'test-brevo-key']);

        $admin = $this->user('admin');
        $owner = $this->user('owner');
        $target = $this->user('staff');

        $this->signIn($admin)->postJson($this->lockUrl($target), [
            'code' => $this->totp($admin),
        ])->assertOk();

        $sentTo = Http::recorded()->map(
            static fn (array $recorded): string => $recorded[0]->data()['to'][0]['email'],
        )->all();
        $this->assertEqualsCanonicalizing([$admin->email, $owner->email], $sentTo);
        $this->assertNotContains($target->email, $sentTo);
    }

    public function test_locked_accounts_are_rejected_at_password_and_google_login(): void
    {
        $target = $this->user('staff', [
            'locked_at' => now(),
            'locked_reason' => 'Under review',
            'mfa_enabled' => true,
        ]);

        $this->postJson('/login', [
            'username' => $target->username,
            'password' => 'password',
        ])->assertForbidden()
            ->assertExactJson(['message' => 'This account has been demoted.']);
        $this->assertNull($target->fresh()->last_login_at);
        $this->assertTrue(Auth::guard(config('auth.multi_guard') ? 'staff' : config('auth.defaults.guard'))->guest());

        config(['services.google.client_id' => 'account-lock-google-client']);
        $token = $this->fakeGoogleToken($target->email, $target->name);
        $this->postJson('/auth/google', ['id_token' => $token])
            ->assertForbidden()
            ->assertExactJson(['message' => 'This account has been demoted.'])
            ->assertJsonMissingPath('mfa_required');
        $this->assertTrue(Auth::guard(config('auth.multi_guard') ? 'staff' : config('auth.defaults.guard'))->guest());
        $this->assertNull($target->fresh()->last_login_at);
    }

    public function test_locked_sessions_are_ended_and_promoted_accounts_return_to_normal_mfa_login(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('staff', [
            'locked_at' => now(),
            'locked_by' => $admin->user_id,
            'access_version' => 2,
        ]);
        $guard = config('auth.multi_guard') ? 'staff' : null;
        $session = [
            GuardSessionState::key('access_version_user_id', $guard) => $target->user_id,
            GuardSessionState::key('access_version', $guard) => 1,
            GuardSessionState::key('mfa_passed_at', $guard) => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id', $guard) => $target->user_id,
        ];

        $this->actingAs($target, $guard ?? config('auth.defaults.guard'))
            ->withSession($session)
            ->getJson(role_route('admin.dashboard'))
            ->assertForbidden()
            ->assertExactJson(['message' => 'This account has been demoted.']);
        $this->assertTrue(Auth::guard($guard ?? config('auth.defaults.guard'))->guest());

        $this->permitCurrentTotpForNextAction($admin);
        $this->signIn($admin)->postJson($this->promoteUrl($target), [
            'code' => $this->totp($admin),
        ])->assertOk();

        Auth::forgetGuards();
        $this->flushSession();
        $this->postJson('/login', [
            'username' => $target->username,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonPath('mfa_type', 'challenge');
    }

    public function test_last_active_owner_cannot_be_locked_and_access_page_reports_lock_actions(): void
    {
        $admin = $this->user('admin');
        $owner = $this->user('owner');
        $staff = $this->user('staff');
        $customer = $this->user('user');

        $this->signIn($admin)->postJson($this->lockUrl($owner), [
            'code' => $this->totp($admin),
        ])->assertConflict();
        $this->assertSame(0, MfaResetRequest::where('target_user_id', $owner->user_id)->count());

        $rows = collect($this->getJson(route('admin.users.index'))->json('users'));
        $staffRow = $rows->firstWhere('id', $staff->user_id);
        $customerRow = $rows->firstWhere('id', $customer->user_id);
        $this->assertTrue($staffRow['account_lock_actions']['allowed']);
        $this->assertTrue($staffRow['account_lock_actions']['lock']);
        $this->assertFalse($customerRow['account_lock_actions']['allowed']);

        $page = $this->get(role_route('admin.user-access.page'))->assertOk();
        $page->assertSee('id="accountLockSection"', false)
            ->assertSee('Lock account')
            ->assertSee('data-account-lock-url-template', false)
            ->assertSee('data-account-promote-again-url-template', false);

        $staff->forceFill([
            'locked_at' => now(),
            'locked_by' => $admin->user_id,
            'locked_reason' => 'Review',
        ])->save();
        $lockedRow = collect($this->getJson(route('admin.users.index'))->json('users'))
            ->firstWhere('id', $staff->user_id);
        $this->assertTrue($lockedRow['locked']);
        $this->assertSame('Review', $lockedRow['locked_reason']);
        $this->assertTrue($lockedRow['account_lock_actions']['promote']);
        $this->assertTrue($lockedRow['mfa_actions']['disable']);
        $this->assertTrue($lockedRow['mfa_actions']['force_reenroll']);
    }

    public function test_locking_an_admin_leaves_an_active_admin_account(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('admin');

        $this->signIn($admin)->postJson($this->lockUrl($target), [
            'code' => $this->totp($admin),
        ])->assertOk();

        $this->assertSame(
            1,
            User::query()->where('role', 'admin')->whereNull('locked_at')->count(),
        );
    }

    private function fakeGoogleToken(string $email, string $name): string
    {
        if (! isset($this->googlePrivateKey)) {
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

            $this->googlePrivateKey = openssl_pkey_new($config);
            $this->assertNotFalse($this->googlePrivateKey, 'openssl_pkey_new failed: '.openssl_error_string());
            $this->googlePublicDetails = openssl_pkey_get_details($this->googlePrivateKey);
            $base64Url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

            Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [[
                'kid' => 'account-lock-test-kid',
                'alg' => 'RS256',
                'kty' => 'RSA',
                'use' => 'sig',
                'n' => $base64Url($this->googlePublicDetails['rsa']['n']),
                'e' => $base64Url($this->googlePublicDetails['rsa']['e']),
            ]]])]);
        }

        $base64Url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $base64Url(json_encode(['alg' => 'RS256', 'kid' => 'account-lock-test-kid', 'typ' => 'JWT']));
        $payload = $base64Url(json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'account-lock-google-client',
            'exp' => now()->addHour()->timestamp,
            'email' => $email,
            'email_verified' => true,
            'name' => $name,
            'jti' => bin2hex(random_bytes(8)),
        ]));
        openssl_sign("$header.$payload", $signature, $this->googlePrivateKey, OPENSSL_ALGO_SHA256);

        return "$header.$payload.".$base64Url($signature);
    }
}
