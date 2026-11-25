<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\GuardSessionState;
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
            $this->signedIn($this->makeUser($role))->getJson(role_route('admin.users.index'))->assertOk();
            $this->getJson(role_route('admin.users.candidates').'?q=customer')->assertOk();
        }

        foreach (['staff', 'user'] as $role) {
            if (config('auth.multi_guard')) {
                $guard = $role === 'user' ? 'customer' : $role;
                $this->signedIn($this->makeUser($role))->getJson('/'.$guard.'/users')->assertNotFound();
            } else {
                $this->signedIn($this->makeUser($role))->getJson('/admin/users')->assertForbidden();
                $this->getJson('/admin/users/candidates?q=customer')->assertForbidden();
            }
        }
    }

    public function test_user_access_page_and_sidebar_are_available_only_to_owners_and_admins(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $user = $this->makeUser($role);
            if ($role === 'owner') {
                if (config('auth.multi_guard')) {
                    $response = $this->signedIn($user)->get(role_route('admin.user-access.page'))->assertOk();
                } else {
                    $this->signedIn($user)->get('/admin/user-access')->assertRedirect(route('owner.user-access'));
                    $response = $this->get('/owner/user-access')->assertOk();
                }
            } else {
                $response = $this->signedIn($user)->get(role_route('admin.user-access.page'))->assertOk();
                $response->assertSee(route('admin.user-access.page'));
            }
            $response->assertSee('User access')->assertSee('user-access.css')->assertSee('shared/user-access.js');
        }

        foreach (['staff', 'user'] as $role) {
            if (config('auth.multi_guard')) {
                $guard = $role === 'user' ? 'customer' : $role;
                $this->signedIn($this->makeUser($role))->get('/'.$guard.'/user-access')->assertNotFound();
            } else {
                $this->signedIn($this->makeUser($role))->get('/admin/user-access')->assertForbidden();
                $this->getJson('/admin/users')->assertForbidden();
            }
        }
    }

    public function test_grant_role_options_follow_the_actor_and_staff_denials_are_audited(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('owner');
        $adminPage = $this->signedIn($admin)->get(role_route('admin.user-access.page'))->assertOk();
        $adminPage->assertSee('data-grantable-roles="staff,owner,admin"', false);
        $ownerPage = $this->actingAs($owner)->withSession([
            'access_version_user_id' => $owner->user_id, 'access_version' => 1,
            'mfa_passed_at' => now()->timestamp, 'mfa_passed_user_id' => $owner->user_id,
        ])->get(config('auth.multi_guard') ? role_route('admin.user-access.page') : '/owner/user-access')->assertOk();
        $ownerPage->assertSee('data-grantable-roles="staff"', false)->assertDontSee('Edit details');

        $staff = $this->makeUser('staff');
        if (config('auth.multi_guard')) {
            $this->signedIn($staff)->getJson('/staff/users')->assertNotFound();
            $this->get('/staff/user-access')->assertNotFound();
        } else {
            $this->signedIn($staff)->getJson('/admin/users')->assertForbidden();
            $this->get('/owner/user-access')->assertForbidden();
        }
    }

    public function test_page_explains_admin_can_promote_the_first_owner(): void
    {
        $admin = $this->makeUser('admin');
        $response = $this->signedIn($admin)->get(role_route('admin.user-access.page'))->assertOk();

        $response->assertSee('data-owner-exists="false"', false)
            ->assertSee('No owner account has been set.')
            ->assertSee('Promote a registered customer to owner from this page');
    }

    public function test_admin_account_list_includes_customers_but_owner_list_remains_elevated_only(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser('owner', null, ['name' => 'A Owner']);
        $this->makeUser('staff', ['bookings.view'], ['name' => 'Searchable Staff']);
        $this->makeUser('admin', null, ['name' => 'Searchable Admin']);
        $this->makeUser('user', null, ['name' => 'Searchable Customer']);

        $all = $this->signedIn($admin)->getJson(role_route('admin.users.index'))->assertOk()->json('users');
        $this->assertCount(5, $all);
        $this->assertContains('user', array_column($all, 'role'));

        $staff = $this->getJson(role_route('admin.users.index').'?q=searchable&role=staff')->assertOk()->json('users');
        $this->assertCount(1, $staff);
        $this->assertSame('Searchable Staff', $staff[0]['name']);
        $this->assertSame('staff', $staff[0]['role']);

        $admins = $this->getJson(role_route('admin.users.index').'?q=searchable&role=admin')->assertOk()->json('users');
        $this->assertCount(1, $admins);
        $this->assertSame('Searchable Admin', $admins[0]['name']);
    }

    public function test_permission_middleware_reads_each_roles_current_permissions(): void
    {
        foreach (['admin', 'owner', 'staff', 'customer'] as $guard) {
            Route::middleware(['web', 'auth', 'can.access:bookings.view'])
                ->get('/_test/bookings-view/'.$guard, fn () => response()->json(['allowed' => true]))
                ->defaults('_role_guard', $guard);
        }

        foreach (['owner', 'admin', 'staff', 'user'] as $role) {
            $guard = $role === 'user' ? 'customer' : $role;
            $response = $this->signedIn($this->makeUser($role))->getJson('/_test/bookings-view/'.$guard);
            if ($role === 'staff') {
                $response->assertOk();
            } elseif ($role === 'user') {
                $response->assertForbidden();
            } else {
                $response->assertOk();
            }
        }

        $staffWithoutPermission = $this->makeUser('staff', []);
        $this->signedIn($staffWithoutPermission)->getJson('/_test/bookings-view/staff')->assertForbidden();
    }

    public function test_admins_can_manage_owners_and_grant_roles_in_the_hierarchy(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('owner');
        $customer = $this->makeUser();

        $this->signedIn($admin)->patchJson(role_route('admin.users.update', ['user' => $owner->user_id]), [
            'role' => 'admin', 'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', 'admin');

        $this->signedIn($admin)->postJson(role_route('admin.users.store'), [
            'user_id' => $customer->user_id,
            'role' => 'staff',
            'permissions' => ['user_access.manage_owners'],
            'password' => 'password',
        ])->assertUnprocessable();

        $ownerCandidate = $this->makeUser();
        $this->signedIn($admin)->postJson(role_route('admin.users.store'), [
            'user_id' => $ownerCandidate->user_id,
            'role' => 'owner',
            'password' => 'password',
        ])->assertCreated()->assertJsonPath('user.role', 'owner');

        $this->assertSame('user', $customer->fresh()->role);
    }

    public function test_self_role_permission_and_access_changes_are_blocked_and_last_owner_is_preserved(): void
    {
        $owner = $this->makeUser('owner');

        $this->signedIn($owner)->patchJson(role_route('admin.users.update', ['user' => $owner->user_id]), [
            'role' => 'admin', 'password' => 'password',
        ])->assertForbidden();

        $this->signedIn($owner)->postJson(role_route('admin.users.remove-access', ['user' => $owner->user_id]), [
            'password' => 'password',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['user_id' => $owner->user_id, 'role' => 'owner']);
        $this->assertSame(1, User::where('role', 'owner')->count());
    }

    public function test_admin_hierarchy_blocks_self_and_last_admin_but_allows_demoting_last_owner(): void
    {
        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('owner');
        $anotherAdmin = $this->makeUser('admin');

        $this->signedIn($admin)->patchJson(role_route('admin.users.update', ['user' => $admin->user_id]), ['role' => 'staff', 'password' => 'password'])->assertForbidden();
        $this->signedIn($admin)->patchJson(role_route('admin.users.update', ['user' => $anotherAdmin->user_id]), ['role' => 'staff', 'password' => 'password'])->assertOk();
        $this->signedIn($admin)->patchJson(role_route('admin.users.update', ['user' => $owner->user_id]), ['role' => 'staff', 'password' => 'password'])->assertOk();
        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->assertSame(0, User::where('role', 'owner')->count());

        $this->signedIn($admin)->postJson(role_route('admin.users.remove-access', ['user' => $admin->user_id]), ['password' => 'password'])->assertForbidden();
        $this->assertDatabaseHas('activity_logs', ['action' => 'user_access.denied', 'subject_id' => $admin->user_id]);
    }

    public function test_only_role_user_accounts_can_be_promoted_and_staff_default_to_the_staff_preset(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser();
        $this->signedIn($admin)->postJson(role_route('admin.users.store'), [
            'user_id' => $customer->user_id, 'role' => 'staff', 'password' => 'password',
        ])->assertCreated()->assertJsonPath('user.role', 'staff');

        $staff = $customer->fresh();
        $this->assertSame(config('user_access.staff_permissions'), $staff->permissions);
        $this->assertContains('activity_logs.view', $staff->permissions);
        $this->assertSame(2, (int) $staff->access_version);

        $page = $this->signedIn($admin)->get(role_route('admin.user-access.page'))->assertOk();
        $page->assertSee('activity_logs.view')->assertSee('View activity logs');

        $this->signedIn($admin)->postJson(role_route('admin.users.store'), [
            'user_id' => $staff->user_id, 'role' => 'admin', 'password' => 'password',
        ])->assertStatus(409);
    }

    public function test_sensitive_actions_require_the_current_password(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser();

        $this->signedIn($admin)->postJson(role_route('admin.users.store'), [
            'user_id' => $customer->user_id, 'role' => 'staff', 'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonPath('message', 'Password confirmation failed.');
        $this->assertSame('user', $customer->fresh()->role);
    }

    public function test_candidates_only_search_customers_require_three_characters_limit_to_eight_and_throttle(): void
    {
        $admin = $this->makeUser('admin');
        foreach (range(1, 10) as $i) {
            $this->makeUser('user', null, [
                'name' => "Searchable Customer {$i}",
                'email' => "searchable-customer-{$i}@example.test",
            ]);
        }
        $owner = $this->makeUser('owner', null, ['email' => 'searchable-owner@example.test']);
        $otherAdmin = $this->makeUser('admin', null, ['email' => 'searchable-admin@example.test']);
        $staff = $this->makeUser('staff', null, ['email' => 'searchable-staff@example.test']);

        $this->signedIn($admin)->getJson(role_route('admin.users.candidates').'?q=ab')->assertOk()->assertJsonCount(0, 'candidates');
        $matches = $this->getJson(role_route('admin.users.candidates').'?q=searchable')
            ->assertOk()->assertJsonCount(8, 'candidates')->json('candidates');
        self::assertSame(['id', 'email', 'name'], array_keys($matches[0]));
        self::assertNotContains($owner->user_id, array_column($matches, 'id'));
        self::assertNotContains($otherAdmin->user_id, array_column($matches, 'id'));
        self::assertNotContains($staff->user_id, array_column($matches, 'id'));

        for ($request = 0; $request < 48; $request++) {
            $this->getJson(role_route('admin.users.candidates').'?q=searchable')->assertOk();
        }
        $this->getJson(role_route('admin.users.candidates').'?q=searchable')->assertTooManyRequests();
    }

    public function test_candidate_lookup_returns_full_email_and_only_safe_fields(): void
    {
        $admin = $this->makeUser('admin', null, ['email' => 'access-admin@example.test']);
        $target = $this->makeUser('user', null, [
            'name' => 'Private Person', 'username' => 'private-person', 'email' => 'private.person@example.test',
        ]);

        $list = $this->signedIn($admin)->getJson(role_route('admin.users.index'))->assertOk();
        self::assertStringNotContainsString($target->email, $list->getContent());
        self::assertSame($admin->email, collect($list->json('users'))->firstWhere('id', $admin->user_id)['email']);
        $page = $this->get(role_route('admin.user-access.page'))->assertOk();
        self::assertStringNotContainsString($target->email, $page->getContent());

        $candidate = $this->getJson(role_route('admin.users.candidates').'?q=private.person')->assertOk();
        self::assertSame([[
            'id' => $target->user_id,
            'email' => $target->email,
            'name' => 'Private Person',
        ]], $candidate->json('candidates'));
        self::assertSame(['id', 'email', 'name'], array_keys($candidate->json('candidates.0')));
        self::assertStringNotContainsString($target->email, $list->getContent());

        $promote = $this->postJson(role_route('admin.users.store'), [
            'user_id' => $target->user_id, 'role' => 'staff', 'permissions' => ['bookings.view'], 'password' => 'password',
        ])->assertCreated();
        self::assertStringNotContainsString($target->email, $promote->getContent());

        $update = $this->patchJson(role_route('admin.users.update', ['user' => $target->user_id]), [
            'role' => 'staff', 'permissions' => ['bookings.view'], 'password' => 'password',
        ])->assertOk();
        self::assertStringNotContainsString($target->email, $update->getContent());

        $remove = $this->postJson(role_route('admin.users.remove-access', ['user' => $target->user_id]), ['password' => 'password'])->assertOk();
        self::assertStringNotContainsString($target->email, $remove->getContent());
        foreach (\App\Models\ActivityLog::query()->pluck('description') as $description) {
            self::assertStringNotContainsString($target->email, (string) $description);
        }
    }

    public function test_candidate_search_matches_email_fragments_case_insensitively_and_ignores_name(): void
    {
        $admin = $this->makeUser('admin', null, ['email' => 'search-admin@example.test']);
        $target = $this->makeUser('staff', ['bookings.view'], [
            'name' => 'Another Staff', 'username' => 'staff-with-private-email', 'email' => 'specific.person@example.test',
        ]);
        $candidateTarget = $this->makeUser('user', null, [
            'name' => 'Customer Candidate', 'username' => 'email-candidate', 'email' => 'candidate.address@example.test',
        ]);
        $this->signedIn($admin);

        $partial = $this->getJson(role_route('admin.users.index').'?q=specific.person')->assertOk();
        self::assertNotContains($target->user_id, array_column($partial->json('users'), 'id'));

        $exact = $this->getJson(role_route('admin.users.index').'?q=SPECIFIC.PERSON@EXAMPLE.TEST')->assertOk();
        self::assertSame($target->user_id, $exact->json('users.0.id'));
        self::assertStringNotContainsString($target->email, $exact->getContent());

        $partialCandidate = $this->getJson(role_route('admin.users.candidates').'?q=candidate.address')->assertOk();
        self::assertSame($candidateTarget->user_id, $partialCandidate->json('candidates.0.id'));
        self::assertSame($candidateTarget->email, $partialCandidate->json('candidates.0.email'));
        $nameOnlyCandidate = $this->getJson(role_route('admin.users.candidates').'?q=Customer+Candidate')->assertOk();
        self::assertSame([], $nameOnlyCandidate->json('candidates'));
        $candidate = $this->getJson(role_route('admin.users.candidates').'?q=CANDIDATE.ADDRESS@EXAMPLE.TEST')->assertOk();
        self::assertSame($candidateTarget->user_id, $candidate->json('candidates.0.id'));
        self::assertSame($candidateTarget->email, $candidate->json('candidates.0.email'));
    }

    public function test_customer_email_lookup_treats_like_metacharacters_as_literal_text(): void
    {
        $owner = $this->makeUser('owner');
        $literal = $this->makeUser('user', null, ['email' => 'literal%_\\segment@example.test']);
        $nearMatch = $this->makeUser('user', null, ['email' => 'literalXY\\segment@example.test']);
        $this->signedIn($owner);

        $term = 'literal%_\\segment';
        $response = $this->getJson(role_route('admin.users.candidates').'?q='.rawurlencode($term))->assertOk();
        self::assertSame([$literal->user_id], array_column($response->json('candidates'), 'id'));
        self::assertNotContains($nearMatch->user_id, array_column($response->json('candidates'), 'id'));
    }

    public function test_access_version_change_logs_out_an_existing_session(): void
    {
        $admin = $this->makeUser('admin');
        $staff = $this->makeUser('staff', ['bookings.view']);

        $this->signedIn($admin)->patchJson(role_route('admin.users.update', ['user' => $staff->user_id]), [
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

        $staffHome = config('auth.multi_guard') ? '/staff/dashboard' : '/my-dashboard';
        $this->signedIn($staff, mfaPassed: false)->get($staffHome)
            ->assertRedirect(route(mfa_route_name('mfa.setup', 'staff')));
        $adminHome = $this->signedIn($staff, mfaPassed: false)->get('/dashboard');
        if (config('auth.multi_guard')) {
            $adminHome->assertRedirect(route('admin.login'));
        } else {
            $adminHome->assertForbidden();
        }

        $passwordUser = $this->makeUser('staff', null, ['username' => 'mfa-staff-login']);
        $response = $this->postJson('/login', ['username' => $passwordUser->username, 'password' => 'password']);
        $response->assertOk()->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'setup');
        $this->assertNotNull($passwordUser->fresh()->last_login_at);
        $response->assertSessionHas(GuardSessionState::key('access_version', config('auth.multi_guard') ? 'staff' : null), 1);

        $registration = $this->post('/register', [
            'name' => 'New Customer',
            'username' => 'new-customer',
            'email' => 'new-customer@example.test',
            'password' => 'password-long-123',
            'password_confirmation' => 'password-long-123',
        ]);
        $registration->assertRedirect(route('user.dashboard'))
            ->assertSessionHas(GuardSessionState::key('access_version', config('auth.multi_guard') ? 'customer' : null), 1);
    }

    public function test_privileged_accounts_cannot_self_delete_but_customers_keep_existing_confirmation_flow(): void
    {
        foreach (['owner', 'admin', 'staff'] as $role) {
            $user = $this->makeUser($role);
            $profileResponse = $this->signedIn($user)->deleteJson('/profile', ['confirmation' => 'DELETE MY ACCOUNT']);
            if (config('auth.multi_guard')) {
                $profileResponse->assertUnauthorized();
            } else {
                $profileResponse->assertForbidden();
            }
            $this->assertDatabaseHas('users', ['user_id' => $user->user_id]);

            if ($role === 'admin' || (! config('auth.multi_guard') && $role === 'owner')) {
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

    public function test_owner_can_manage_staff_but_not_owner_or_admin_accounts(): void
    {
        $actor = $this->makeUser('owner');
        $staff = $this->makeUser('staff');
        $targetOwner = $this->makeUser('owner');
        $targetAdmin = $this->makeUser('admin');

        $this->signedIn($actor)->postJson(role_route('admin.users.remove-access', ['user' => $staff->user_id]), [
            'password' => 'password',
        ])->assertOk();
        $this->assertDatabaseHas('users', ['user_id' => $staff->user_id, 'role' => 'user']);
        foreach ([$targetOwner, $targetAdmin] as $protected) {
            $this->postJson(role_route('admin.users.remove-access', ['user' => $protected->user_id]), ['password' => 'password'])->assertForbidden();
        }
        $customer = $this->makeUser();
        $this->postJson(role_route('admin.users.store'), ['user_id' => $customer->user_id, 'role' => 'owner', 'password' => 'password'])->assertForbidden();
        if (config('auth.multi_guard')) {
            $this->patchJson("/owner/users/{$staff->user_id}/details", [])->assertNotFound();
        } else {
            $this->patchJson("/admin/users/{$staff->user_id}/details", [])->assertForbidden();
        }
    }

    public function test_only_admin_can_view_and_edit_any_non_self_account_details(): void
    {
        $target = $this->makeUser('staff');
        $admin = $this->makeUser('admin');
        $response = $this->signedIn($admin)->getJson(role_route('admin.users.details', ['user' => $target->user_id]))->assertOk();
        $response->assertJsonPath('details.email', $target->email)
            ->assertJsonPath('details.phone_number', null);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user_details.viewed',
            'user_id' => $admin->user_id,
            'subject_id' => $target->user_id,
        ]);

        foreach (['owner', 'staff', 'user'] as $role) {
            $actor = $this->makeUser($role);
            if (config('auth.multi_guard')) {
                $guard = $role === 'user' ? 'customer' : $role;
                $this->signedIn($actor)->getJson("/{$guard}/users/{$target->user_id}/details")->assertNotFound();
                $this->patchJson("/{$guard}/users/{$target->user_id}/details", [])->assertNotFound();
            } else {
                $this->signedIn($actor)->getJson("/admin/users/{$target->user_id}/details")->assertForbidden();
                $this->signedIn($actor)->patchJson("/admin/users/{$target->user_id}/details", [])->assertForbidden();
            }
        }

        $adminTarget = $this->makeUser('admin');
        $this->signedIn($admin)->getJson(role_route('admin.users.details', ['user' => $adminTarget->user_id]))->assertOk();
        $customerTarget = $this->makeUser('user');
        $this->signedIn($admin)->getJson(role_route('admin.users.details', ['user' => $customerTarget->user_id]))->assertOk();
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
        $this->signedIn($admin);
        $url = role_route('admin.users.details.update', ['user' => $target->user_id]);
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
            'password' => 'password', 'new_password' => 'unexpected-new-password', 'role' => 'admin', 'permissions' => [], 'mfa_enabled' => false,
        ])->assertOk();
        $response->assertJsonPath('user.email', 'upd******@example.test')
            ->assertJsonMissing(['email' => 'updated.staff@example.test']);

        $updated = $target->fresh();
        $this->assertSame('Updated Staff', $updated->name);
        $this->assertSame('updated.staff@example.test', $updated->email);
        $this->assertSame('staff', $updated->role);
        $this->assertSame(['bookings.view'], $updated->permissions);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $updated->password));
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
        $this->signedIn($admin)->patchJson(role_route('admin.users.details.update', ['user' => $target->user_id]), [...$base, 'email' => strtoupper($other->email)])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->signedIn($admin)->patchJson(role_route('admin.users.details.update', ['user' => $target->user_id]), [...$base, 'username' => $other->username])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->signedIn($admin)->patchJson(role_route('admin.users.details.update', ['user' => $target->user_id]), [...$base, 'phone_number' => 'bad phone!'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone_number');

        $oldEmail = $target->email;
        $response = $this->signedIn($admin)->patchJson(role_route('admin.users.details.update', ['user' => $target->user_id]), [
            ...$base, 'email' => 'owner.new@example.test', 'phone_number' => '',
        ])->assertOk();
        $response->assertDontSee($oldEmail)->assertDontSee('owner.new@example.test');
        Mail::assertSent(AdminChangedAccountEmailNotice::class, fn ($mail) => $mail->hasTo($oldEmail));
        $this->assertNull($target->fresh()->phone_number);
    }
}
