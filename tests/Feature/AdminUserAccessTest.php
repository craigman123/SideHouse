<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminUserAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'user', array|null $permissions = null, array $overrides = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_merge([
            'username' => "access-user-{$sequence}",
            'name' => "Access User {$sequence}",
            'email' => "access-user-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'permissions' => $permissions,
        ], $overrides));
    }

    private function signedIn(User $user, bool $mfaPassed = true): self
    {
        $session = [
            'access_version_user_id' => (int) $user->user_id,
            'access_version' => (int) ($user->fresh()->access_version ?? 1),
        ];
        if ($mfaPassed) {
            $session['mfa_passed_at'] = now()->timestamp;
            $session['mfa_passed_user_id'] = $user->user_id;
        }

        return $this->actingAs($user)->withSession($session);
    }

    public function test_owner_and_admin_pass_admin_role_middleware_but_staff_and_customers_do_not(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $this->signedIn($this->makeUser($role))->getJson('/admin/users')->assertOk();
        }

        foreach (['staff', 'user'] as $role) {
            $this->signedIn($this->makeUser($role))->getJson('/admin/users')->assertForbidden();
        }
    }

    public function test_permission_middleware_reads_each_roles_current_permissions(): void
    {
        Route::middleware(['web', 'auth', 'can.access:bookings.view'])
            ->get('/_test/bookings-view', fn () => response()->json(['allowed' => true]));

        foreach (['owner', 'admin'] as $role) {
            $this->signedIn($this->makeUser($role))->getJson('/_test/bookings-view')->assertOk();
        }

        $staffWithPermission = $this->makeUser('staff', ['bookings.view']);
        $staffWithoutPermission = $this->makeUser('staff', []);
        $customer = $this->makeUser();

        $this->signedIn($staffWithPermission)->getJson('/_test/bookings-view')->assertOk();
        $this->signedIn($staffWithoutPermission)->getJson('/_test/bookings-view')->assertForbidden();
        $this->signedIn($customer)->getJson('/_test/bookings-view')->assertForbidden();
    }

    public function test_admins_cannot_manage_owners_or_grant_permissions_they_lack(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('owner');
        $customer = $this->makeUser();

        $this->signedIn($admin)->patchJson("/admin/users/{$owner->user_id}", [
            'role' => 'admin', 'password' => 'password',
        ])->assertForbidden();

        $this->signedIn($admin)->postJson('/admin/users', [
            'user_id' => $customer->user_id,
            'role' => 'staff',
            'permissions' => ['user_access.manage_owners'],
            'password' => 'password',
        ])->assertForbidden();

        $this->signedIn($admin)->postJson('/admin/users', [
            'user_id' => $customer->user_id,
            'role' => 'owner',
            'password' => 'password',
        ])->assertForbidden();

        $this->assertSame('user', $customer->fresh()->role);
    }

    public function test_self_role_permission_and_access_changes_are_blocked_and_last_owner_is_preserved(): void
    {
        $owner = $this->makeUser('owner');

        $this->signedIn($owner)->patchJson("/admin/users/{$owner->user_id}", [
            'role' => 'admin', 'password' => 'password',
        ])->assertForbidden();

        $this->signedIn($owner)->postJson("/admin/users/{$owner->user_id}/remove-access", [
            'password' => 'password',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['user_id' => $owner->user_id, 'role' => 'owner']);
        $this->assertSame(1, User::where('role', 'owner')->count());
    }

    public function test_only_role_user_accounts_can_be_promoted_and_staff_default_to_all_five_permissions(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser();
        $this->signedIn($admin)->postJson('/admin/users', [
            'user_id' => $customer->user_id, 'role' => 'staff', 'password' => 'password',
        ])->assertCreated()->assertJsonPath('user.role', 'staff');

        $staff = $customer->fresh();
        $this->assertSame(config('user_access.staff_permissions'), $staff->permissions);
        $this->assertSame(2, (int) $staff->access_version);

        $this->signedIn($admin)->postJson('/admin/users', [
            'user_id' => $staff->user_id, 'role' => 'admin', 'password' => 'password',
        ])->assertStatus(409);
    }

    public function test_sensitive_actions_require_the_current_password(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser();

        $this->signedIn($admin)->postJson('/admin/users', [
            'user_id' => $customer->user_id, 'role' => 'staff', 'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonPath('message', 'Password confirmation failed.');
        $this->assertSame('user', $customer->fresh()->role);
    }

    public function test_candidates_only_search_customers_require_three_characters_limit_to_eight_and_throttle(): void
    {
        $admin = $this->makeUser('admin');
        foreach (range(1, 10) as $i) {
            $this->makeUser('user', null, ['name' => "Searchable Customer {$i}"]);
        }
        $this->makeUser('staff', null, ['name' => 'Searchable Staff']);

        $this->signedIn($admin)->getJson('/admin/users/candidates?q=ab')->assertOk()->assertJsonCount(0, 'candidates');
        $this->getJson('/admin/users/candidates?q=searchable')->assertOk()->assertJsonCount(8, 'candidates');

        for ($request = 0; $request < 19; $request++) {
            $response = $this->getJson('/admin/users/candidates?q=searchable');
        }
        $response->assertTooManyRequests();
    }

    public function test_access_version_change_logs_out_an_existing_session(): void
    {
        $admin = $this->makeUser('admin');
        $staff = $this->makeUser('staff', ['bookings.view']);

        $this->signedIn($admin)->patchJson("/admin/users/{$staff->user_id}", [
            'role' => 'staff', 'permissions' => [], 'password' => 'password',
        ])->assertOk();

        $this->assertSame(2, (int) $staff->fresh()->access_version);

        $this->actingAs($staff)->withSession([
            'access_version_user_id' => $staff->user_id,
            'access_version' => 1,
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $staff->user_id,
        ])->getJson('/my-dashboard')->assertUnauthorized();
    }

    public function test_staff_must_complete_mfa_before_customer_or_admin_pages(): void
    {
        $staff = $this->makeUser('staff');

        $this->signedIn($staff, mfaPassed: false)->get('/my-dashboard')->assertRedirect(route('mfa.setup'));
        $this->signedIn($staff, mfaPassed: false)->get('/dashboard')->assertForbidden();

        $passwordUser = $this->makeUser('staff', null, ['username' => 'mfa-staff-login']);
        $response = $this->postJson('/login', ['username' => $passwordUser->username, 'password' => 'password']);
        $response->assertOk()->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'setup');
        $this->assertNotNull($passwordUser->fresh()->last_login_at);
        $response->assertSessionHas('access_version', 1);

        $registration = $this->post('/register', [
            'name' => 'New Customer',
            'username' => 'new-customer',
            'email' => 'new-customer@example.test',
            'password' => 'password-long-123',
            'password_confirmation' => 'password-long-123',
        ]);
        $registration->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('access_version', 1);
    }

    public function test_privileged_accounts_cannot_self_delete_but_customers_keep_existing_confirmation_flow(): void
    {
        foreach (['owner', 'admin', 'staff'] as $role) {
            $user = $this->makeUser($role);
            $this->signedIn($user)->deleteJson('/profile', ['confirmation' => 'DELETE MY ACCOUNT'])->assertForbidden();
            $this->assertDatabaseHas('users', ['user_id' => $user->user_id]);

            if (in_array($role, ['owner', 'admin'], true)) {
                $this->signedIn($user)->deleteJson('/admin/profile', ['confirmation' => 'DELETE MY ACCOUNT'])->assertForbidden();
            }
        }

        $customer = $this->makeUser();
        $this->signedIn($customer)->deleteJson('/profile', ['confirmation' => 'not the confirmation'])
            ->assertRedirect();
        $this->assertDatabaseHas('users', ['user_id' => $customer->user_id]);
    }

    public function test_make_owner_command_promotes_an_existing_account_after_confirmation_and_audits_it(): void
    {
        $candidate = $this->makeUser('admin');
        $oldVersion = (int) $candidate->fresh()->access_version;

        $this->artisan('users:make-owner', ['email' => $candidate->email])
            ->expectsConfirmation('Promote this account to owner?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'user_id' => $candidate->user_id,
            'role' => 'owner',
            'access_version' => $oldVersion + 1,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user_access.owner_bootstrapped',
            'subject_id' => $candidate->user_id,
        ]);
    }

    public function test_owner_last_owner_guard_uses_locked_owner_set_in_a_transaction(): void
    {
        $actor = $this->makeUser('owner');
        $target = $this->makeUser('owner');

        $this->signedIn($actor)->postJson("/admin/users/{$target->user_id}/remove-access", [
            'password' => 'password',
        ])->assertOk();

        $this->assertSame(1, User::where('role', 'owner')->count());
        $this->assertDatabaseHas('users', ['user_id' => $target->user_id, 'role' => 'user']);

        // Once only the actor remains as owner, self-removal remains blocked.
        $this->signedIn($actor)->postJson("/admin/users/{$actor->user_id}/remove-access", [
            'password' => 'password',
        ])->assertForbidden();
        $this->assertSame(1, User::where('role', 'owner')->count());
    }
}
