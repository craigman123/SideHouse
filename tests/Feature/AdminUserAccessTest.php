<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use App\Mail\AdminChangedAccountEmailNotice;
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

    public function test_user_access_page_and_sidebar_are_available_only_to_owners_and_admins(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $user = $this->makeUser($role);
            if ($role === 'owner') {
                $this->signedIn($user)->get('/admin/user-access')->assertRedirect(route('owner.user-access'));
                $response = $this->get('/owner/user-access')->assertOk();
            } else {
                $response = $this->signedIn($user)->get('/admin/user-access')->assertOk();
                $response->assertSee(route('admin.user-access.page'));
            }
            $response->assertSee('User access')->assertSee('user-access.css')->assertSee('shared/user-access.js');
        }

        foreach (['staff', 'user'] as $role) {
            $this->signedIn($this->makeUser($role))->get('/admin/user-access')->assertForbidden();
            $this->getJson('/admin/users')->assertForbidden();
        }
    }

    public function test_page_explains_how_to_bootstrap_the_first_owner(): void
    {
        $admin = $this->makeUser('admin');
        $response = $this->signedIn($admin)->get('/admin/user-access')->assertOk();

        $response->assertSee('data-owner-exists="false"', false)
            ->assertSee('No owner account has been set.')
            ->assertSee('php artisan users:make-owner &lt;email&gt;', false);
    }

    public function test_elevated_account_list_uses_search_and_role_filters_and_never_returns_customers(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser('owner', null, ['name' => 'A Owner']);
        $this->makeUser('staff', ['bookings.view'], ['name' => 'Searchable Staff']);
        $this->makeUser('admin', null, ['name' => 'Searchable Admin']);
        $this->makeUser('user', null, ['name' => 'Searchable Customer']);

        $all = $this->signedIn($admin)->getJson('/admin/users')->assertOk()->json('users');
        $this->assertCount(4, $all);
        $this->assertNotContains('user', array_column($all, 'role'));

        $staff = $this->getJson('/admin/users?q=searchable&role=staff')->assertOk()->json('users');
        $this->assertCount(1, $staff);
        $this->assertSame('Searchable Staff', $staff[0]['name']);
        $this->assertSame('staff', $staff[0]['role']);

        $admins = $this->getJson('/admin/users?q=searchable&role=admin')->assertOk()->json('users');
        $this->assertCount(1, $admins);
        $this->assertSame('Searchable Admin', $admins[0]['name']);
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

    public function test_only_role_user_accounts_can_be_promoted_and_staff_default_to_the_staff_preset(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser();
        $this->signedIn($admin)->postJson('/admin/users', [
            'user_id' => $customer->user_id, 'role' => 'staff', 'password' => 'password',
        ])->assertCreated()->assertJsonPath('user.role', 'staff');

        $staff = $customer->fresh();
        $this->assertSame(config('user_access.staff_permissions'), $staff->permissions);
        $this->assertContains('activity_logs.view', $staff->permissions);
        $this->assertSame(2, (int) $staff->access_version);

        $page = $this->signedIn($admin)->get('/admin/user-access')->assertOk();
        $page->assertSee('activity_logs.view')->assertSee('View activity logs');

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

    public function test_other_people_emails_are_masked_in_every_user_access_json_response(): void
    {
        $admin = $this->makeUser('admin', null, ['email' => 'access-admin@example.test']);
        $target = $this->makeUser('user', null, [
            'name' => 'Private Person', 'username' => 'private-person', 'email' => 'private.person@example.test',
        ]);

        $list = $this->signedIn($admin)->getJson('/admin/users')->assertOk();
        self::assertStringNotContainsString($target->email, $list->getContent());
        self::assertSame($admin->email, collect($list->json('users'))->firstWhere('id', $admin->user_id)['email']);
        $page = $this->get('/admin/user-access')->assertOk();
        self::assertStringNotContainsString($target->email, $page->getContent());

        $candidate = $this->getJson('/admin/users/candidates?q=Private+Person')->assertOk();
        self::assertStringNotContainsString($target->email, $candidate->getContent());
        self::assertSame('pri******@example.test', $candidate->json('candidates.0.email'));

        $promote = $this->postJson('/admin/users', [
            'user_id' => $target->user_id, 'role' => 'staff', 'permissions' => ['bookings.view'], 'password' => 'password',
        ])->assertCreated();
        self::assertStringNotContainsString($target->email, $promote->getContent());

        $update = $this->patchJson("/admin/users/{$target->user_id}", [
            'role' => 'staff', 'permissions' => ['bookings.view'], 'password' => 'password',
        ])->assertOk();
        self::assertStringNotContainsString($target->email, $update->getContent());

        $remove = $this->postJson("/admin/users/{$target->user_id}/remove-access", ['password' => 'password'])->assertOk();
        self::assertStringNotContainsString($target->email, $remove->getContent());
        foreach (\App\Models\ActivityLog::query()->pluck('description') as $description) {
            self::assertStringNotContainsString($target->email, (string) $description);
        }
    }

    public function test_email_search_requires_an_exact_address_and_is_case_insensitive(): void
    {
        $admin = $this->makeUser('admin', null, ['email' => 'search-admin@example.test']);
        $target = $this->makeUser('staff', ['bookings.view'], [
            'name' => 'Another Staff', 'username' => 'staff-with-private-email', 'email' => 'specific.person@example.test',
        ]);
        $candidateTarget = $this->makeUser('user', null, [
            'name' => 'Customer Candidate', 'username' => 'email-candidate', 'email' => 'candidate.address@example.test',
        ]);
        $this->signedIn($admin);

        $partial = $this->getJson('/admin/users?q=specific.person')->assertOk();
        self::assertNotContains($target->user_id, array_column($partial->json('users'), 'id'));

        $exact = $this->getJson('/admin/users?q=SPECIFIC.PERSON@EXAMPLE.TEST')->assertOk();
        self::assertSame($target->user_id, $exact->json('users.0.id'));
        self::assertStringNotContainsString($target->email, $exact->getContent());

        $partialCandidate = $this->getJson('/admin/users/candidates?q=candidate.address')->assertOk();
        self::assertSame([], $partialCandidate->json('candidates'));
        $candidate = $this->getJson('/admin/users/candidates?q=CANDIDATE.ADDRESS@EXAMPLE.TEST')->assertOk();
        self::assertSame($candidateTarget->user_id, $candidate->json('candidates.0.id'));
        self::assertStringNotContainsString($candidateTarget->email, $candidate->getContent());
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

    public function test_only_admin_can_view_and_edit_staff_or_owner_account_details(): void
    {
        $target = $this->makeUser('staff');
        $admin = $this->makeUser('admin');
        $response = $this->signedIn($admin)->getJson("/admin/users/{$target->user_id}/details")->assertOk();
        $response->assertJsonPath('details.email', $target->email)
            ->assertJsonPath('details.phone_number', null);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user_details.viewed',
            'user_id' => $admin->user_id,
            'subject_id' => $target->user_id,
        ]);

        foreach (['owner', 'staff', 'user'] as $role) {
            $actor = $this->makeUser($role);
            $this->signedIn($actor)->getJson("/admin/users/{$target->user_id}/details")->assertForbidden();
            $this->signedIn($actor)->patchJson("/admin/users/{$target->user_id}/details", [])->assertForbidden();
        }

        $adminTarget = $this->makeUser('admin');
        $this->signedIn($admin)->getJson("/admin/users/{$adminTarget->user_id}/details")->assertNotFound();
        $customerTarget = $this->makeUser('user');
        $this->signedIn($admin)->getJson("/admin/users/{$customerTarget->user_id}/details")->assertNotFound();
    }

    public function test_account_details_update_requires_password_and_fresh_timestamp_and_preserves_access_fields(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('staff', ['bookings.view'], ['phone_number' => '+63 917 123 4567']);
        $target->forceFill([
            'email_verified_at' => now(),
            'mfa_enabled' => true,
            'mfa_secret' => 'mfa-secret',
            'remember_token' => 'original-remember-token',
        ])->save();
        $before = $target->fresh();
        $url = "/admin/users/{$target->user_id}/details";
        $this->signedIn($admin)->patchJson($url, [
            'name' => 'Updated Staff', 'username' => $target->username, 'email' => 'updated.staff@example.test',
            'phone_number' => '+63 917 765 4321', 'expected_updated_at' => $before->updated_at->toISOString(),
        ])->assertUnprocessable();
        $this->signedIn($admin)->patchJson($url, [
            'name' => 'Updated Staff', 'username' => $target->username, 'email' => 'updated.staff@example.test',
            'phone_number' => '+63 917 765 4321', 'expected_updated_at' => $before->updated_at->toISOString(),
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $response = $this->signedIn($admin)->patchJson($url, [
            'name' => 'Updated Staff', 'username' => $target->username, 'email' => ' Updated.Staff@Example.Test ',
            'phone_number' => '+63 917 765 4321', 'expected_updated_at' => $before->updated_at->toISOString(),
            'password' => 'password', 'role' => 'admin', 'permissions' => [], 'mfa_enabled' => false,
        ])->assertOk();
        $response->assertJsonPath('user.email', 'upd******@example.test')
            ->assertJsonMissing(['email' => 'updated.staff@example.test']);

        $updated = $target->fresh();
        $this->assertSame('Updated Staff', $updated->name);
        $this->assertSame('updated.staff@example.test', $updated->email);
        $this->assertSame('staff', $updated->role);
        $this->assertSame(['bookings.view'], $updated->permissions);
        $this->assertTrue($updated->mfa_enabled);
        $this->assertSame('mfa-secret', $updated->mfa_secret);
        $this->assertNull($updated->email_verified_at);
        $this->assertSame((int) $before->access_version + 1, (int) $updated->access_version);
        $this->assertNotSame('original-remember-token', $updated->remember_token);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user_details.updated', 'subject_id' => $target->user_id]);
        $description = DB::table('activity_logs')->where('action', 'user_details.updated')->value('description');
        $this->assertStringNotContainsString('updated.staff@example.test', $description);

        $this->signedIn($admin)->patchJson($url, [
            'name' => 'Stale update', 'username' => $target->username, 'email' => $updated->email,
            'phone_number' => $updated->phone_number, 'expected_updated_at' => $before->updated_at->toISOString(),
            'password' => 'password',
        ])->assertStatus(409)->assertJsonPath('current.name', 'Updated Staff');
    }

    public function test_details_update_validates_unique_normalized_email_username_phone_and_notifies_old_email(): void
    {
        Mail::fake();
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('owner');
        $target->forceFill(['email_verified_at' => now()])->save();
        $other = $this->makeUser('user');
        $base = [
            'name' => $target->name, 'username' => $target->username, 'email' => $target->email,
            'phone_number' => $target->phone_number, 'expected_updated_at' => $target->updated_at->toISOString(),
            'password' => 'password',
        ];
        $this->signedIn($admin)->patchJson("/admin/users/{$target->user_id}/details", [...$base, 'email' => strtoupper($other->email)])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->signedIn($admin)->patchJson("/admin/users/{$target->user_id}/details", [...$base, 'username' => $other->username])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->signedIn($admin)->patchJson("/admin/users/{$target->user_id}/details", [...$base, 'phone_number' => 'bad phone!'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone_number');

        $oldEmail = $target->email;
        $response = $this->signedIn($admin)->patchJson("/admin/users/{$target->user_id}/details", [
            ...$base, 'email' => 'owner.new@example.test', 'phone_number' => '',
        ])->assertOk();
        $response->assertDontSee($oldEmail)->assertDontSee('owner.new@example.test');
        Mail::assertSent(AdminChangedAccountEmailNotice::class, fn ($mail) => $mail->hasTo($oldEmail));
        $this->assertNull($target->fresh()->phone_number);
    }
}
