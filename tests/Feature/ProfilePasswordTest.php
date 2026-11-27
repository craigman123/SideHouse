<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\GuardSessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class ProfilePasswordTest extends TestCase
{
    use RefreshDatabase;
    use UsesMultiGuardEnvironment;

    private const CURRENT_PASSWORD = 'CurrentPassword123!';
    private const NEW_PASSWORD = 'UpdatedPassword456!';

    private const ROLE_ROUTES = [
        'user' => ['customer', 'user.profile', 'user.profile.password'],
        'admin' => ['admin', 'admin.profile', 'admin.profile.password'],
        'owner' => ['owner', 'owner.profile', 'owner.profile.password'],
        'staff' => ['staff', 'staff.profile', 'staff.profile.password'],
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

    public function test_each_role_can_change_password_without_changing_mfa_state(): void
    {
        foreach (self::ROLE_ROUTES as $role => [$guard, $profileRoute, $passwordRoute]) {
            $secret = (new Google2FA())->generateSecretKey();
            $user = $this->user($role, [
                'mfa_enabled' => true,
                'mfa_method' => 'totp',
                'mfa_secret' => $secret,
                'mfa_recovery_codes' => ['existing-recovery-code'],
            ]);
            $rememberToken = $user->remember_token;

            $this->signInAs($user, $guard)
                ->from(route($profileRoute, [], false))
                ->put(route($passwordRoute), $this->validPayload())
                ->assertRedirect(route($profileRoute, [], false))
                ->assertSessionHas('success', 'Password updated.');

            $updatedUser = $user->fresh();
            $this->assertTrue(Hash::check(self::NEW_PASSWORD, $updatedUser->password), "{$role} password was not updated.");
            $this->assertNotSame($rememberToken, $updatedUser->remember_token, "{$role} remember token was not rotated.");
            $this->assertTrue($updatedUser->hasMfaEnabled(), "{$role} MFA was disabled.");
            $this->assertSame($secret, $updatedUser->mfa_secret, "{$role} MFA secret changed.");
            $this->assertSame(['existing-recovery-code'], $updatedUser->mfa_recovery_codes, "{$role} recovery codes changed.");
        }
    }

    public function test_each_role_renders_the_shared_password_modal(): void
    {
        foreach (self::ROLE_ROUTES as $role => [$guard, $profileRoute, $passwordRoute]) {
            $user = $this->user($role);

            $response = $this->signInAs($user, $guard)
                ->get(route($profileRoute, [], false))
                ->assertOk()
                ->assertViewIs('user.profile.profile')
                ->assertSee('id="profilePasswordModal"', false)
                ->assertSee('role="dialog" aria-modal="true"', false)
                ->assertSee('aria-labelledby="profilePasswordModalTitle"', false)
                ->assertSee('action="'.route($passwordRoute).'"', false)
                ->assertSee('id="profileCurrentPassword" name="current_password" autocomplete="current-password"', false)
                ->assertSee('id="profileNewPassword" name="password" autocomplete="new-password"', false)
                ->assertSee('id="profilePasswordConfirmation" name="password_confirmation" autocomplete="new-password"', false)
                ->assertSee('id="profilePasswordCancel"', false)
                ->assertSee('id="profilePasswordSubmit"', false);

            $this->assertSame(
                3,
                substr_count($response->getContent(), 'data-password-visibility-toggle'),
                "{$role} profile should render one visibility toggle per password field."
            );
        }
    }

    public function test_each_role_rejects_wrong_current_mismatched_weak_and_unchanged_passwords(): void
    {
        $invalidPayloads = [
            'wrong current password' => [
                ['current_password' => 'IncorrectPassword123!', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD],
                'current_password',
            ],
            'mismatched confirmation' => [
                ['current_password' => self::CURRENT_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => 'DifferentPassword789!'],
                'password',
            ],
            'weak password' => [
                ['current_password' => self::CURRENT_PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'],
                'password',
            ],
            'same password' => [
                ['current_password' => self::CURRENT_PASSWORD, 'password' => self::CURRENT_PASSWORD, 'password_confirmation' => self::CURRENT_PASSWORD],
                'password',
            ],
        ];

        foreach (self::ROLE_ROUTES as $role => [$guard, $profileRoute, $passwordRoute]) {
            foreach ($invalidPayloads as $case => [$payload, $error]) {
                $user = $this->user($role);

                $this->signInAs($user, $guard)
                    ->from(route($profileRoute, [], false))
                    ->put(route($passwordRoute), $payload)
                    ->assertRedirect(route($profileRoute, [], false))
                    ->assertSessionHasErrors($error);

                $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->fresh()->password), "{$role} accepted {$case}.");

                if ($case === 'wrong current password') {
                    $this->get(route($profileRoute))
                        ->assertOk()
                        ->assertSee('class="modal-overlay wt-confirm-overlay open" id="profilePasswordModal"', false)
                        ->assertSee('id="profileCurrentPassword" name="current_password"', false)
                        ->assertSee('aria-invalid="true"', false);
                }
            }
        }
    }

    public function test_each_password_route_is_throttled(): void
    {
        foreach (self::ROLE_ROUTES as $role => [$guard, $profileRoute, $passwordRoute]) {
            $user = $this->user($role);
            $invalidPayload = [
                'current_password' => 'IncorrectPassword123!',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ];

            if ($role === 'staff') {
                $response = null;
                for ($attempt = 0; $attempt < 10; $attempt++) {
                    $response = $this->signInAs($user, $guard)->put(route($passwordRoute), $invalidPayload);
                    if ($response->status() === 429) {
                        break;
                    }
                }
                $this->assertNotNull($response);
                $this->assertSame(429, $response->status());
                continue;
            }

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->signInAs($user, $guard)->put(route($passwordRoute), $invalidPayload)->assertRedirect();
            }

            $this->signInAs($user, $guard)
                ->put(route($passwordRoute), $invalidPayload)
                ->assertTooManyRequests();
        }
    }

    public function test_mfa_must_be_passed_before_password_change(): void
    {
        foreach (self::ROLE_ROUTES as $role => [$guard, $profileRoute, $passwordRoute]) {
            $user = $this->user($role, [
                'mfa_enabled' => true,
                'mfa_method' => 'totp',
                'mfa_secret' => (new Google2FA())->generateSecretKey(),
            ]);

            $response = $this->actingAs($user, $guard)
                ->withSession($this->accessVersionSession($user, $guard))
                ->put(route($passwordRoute), $this->validPayload());

            $challengeRoute = $role === 'user' ? 'mfa.challenge' : $guard.'.mfa.challenge';
            $response->assertRedirect(route($challengeRoute, [], false));
            $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->fresh()->password), "{$role} changed password without passing MFA.");
        }
    }

    public function test_password_change_is_disabled_and_rejected_during_admin_view_as(): void
    {
        $admin = $this->user('admin');
        $session = $this->accessVersionSession($admin, 'admin') + [
            GuardSessionState::key('mfa_passed_at', 'admin') => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id', 'admin') => $admin->user_id,
            GuardSessionState::key('view_as', 'admin') => 'staff',
        ];

        $profile = $this->actingAs($admin, 'admin')
            ->withSession($session)
            ->get(route('staff.profile'))
            ->assertOk()
            ->assertSee('id="profilePasswordButton"', false)
            ->assertSee('Password changes are unavailable while switched access is active.');
        $this->assertMatchesRegularExpression('/id="profilePasswordButton"[^>]*\sdisabled(?:\s|>)/', $profile->getContent());

        $this->actingAs($admin, 'admin')
            ->withSession($session)
            ->put(route('staff.profile.password'), $this->validPayload())
            ->assertForbidden();

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $admin->fresh()->password));
    }

    private function validPayload(): array
    {
        return [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];
    }

    private function signInAs(User $user, string $guard): self
    {
        return $this->actingAs($user, $guard)->withSession($this->accessVersionSession($user, $guard) + [
            GuardSessionState::key('mfa_passed_at', $guard) => now()->timestamp,
            GuardSessionState::key('mfa_passed_user_id', $guard) => $user->user_id,
        ]);
    }

    private function accessVersionSession(User $user, string $guard): array
    {
        return [
            GuardSessionState::key('access_version_user_id', $guard) => $user->user_id,
            GuardSessionState::key('access_version', $guard) => $user->access_version,
        ];
    }

    private function user(string $role, array $attributes = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_replace([
            'username' => "profile-password-{$role}-{$sequence}",
            'name' => ucfirst($role).' Password Test',
            'email' => "profile-password-{$role}-{$sequence}@example.test",
            'password' => self::CURRENT_PASSWORD,
            'role' => $role,
            'access_version' => 1,
        ], $attributes));
    }
}
