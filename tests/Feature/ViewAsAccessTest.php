<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\UserAccessService;
use App\Support\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class ViewAsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_real_admin_can_switch_and_reset_with_audited_real_actor(): void
    {
        $admin = $this->user('admin');
        $this->signIn($admin)->post('/switch-access', ['role' => 'staff'])
            ->assertRedirect('/staff/dashboard')
            ->assertSessionHas('view_as', 'staff');

        $log = ActivityLog::query()->where('action', 'view_as.switched')->firstOrFail();
        $this->assertSame($admin->user_id, $log->user_id);
        $this->assertSame(['from' => 'admin', 'to' => 'staff'], $log->properties);

        $this->post('/switch-access/reset')->assertRedirect('/admin/dashboard')->assertSessionMissing('view_as');
        $reset = ActivityLog::query()->where('action', 'view_as.reset')->firstOrFail();
        $this->assertSame($admin->user_id, $reset->user_id);
        $this->assertSame(['from' => 'staff', 'to' => 'admin', 'view_as' => 'staff'], $reset->properties);
    }

    public function test_only_real_admin_can_switch_and_roles_are_validated(): void
    {
        foreach ([
            'owner' => '/owner/profile',
            'staff' => '/staff/profile',
            'user' => '/my-dashboard',
        ] as $role => $page) {
            $this->signIn($this->user($role))->post('/switch-access', ['role' => 'admin'])->assertForbidden();
            $this->get($page)->assertOk()->assertDontSee('data-switch-access');
        }

        $admin = $this->user('admin');
        $this->signIn($admin)->get('/dashboard')->assertOk()->assertSee('data-switch-access', false);
        $this->signIn($admin)->postJson('/switch-access', ['role' => 'superuser'])->assertUnprocessable();
        $this->postJson('/switch-access/reset')->assertRedirect('/admin/dashboard');
    }

    public function test_effective_role_and_permissions_follow_view_mode_and_panel_is_available_in_each_layout(): void
    {
        $admin = $this->user('admin');
        $access = app(UserAccessService::class);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $admin);
        $session = app('session')->driver();
        foreach ([
            'admin' => 'admin',
            'owner' => 'owner',
            'staff' => 'staff',
            'customer' => 'user',
        ] as $view => $effective) {
            $session->put('view_as', $view);
            $request->setLaravelSession($session);
            $this->assertSame($effective, $access->effectiveRole($admin, $request));
        }

        $this->signIn($admin, ['view_as' => 'staff'])->get('/staff/profile')->assertOk()
            ->assertSee('data-switch-access', false)->assertSee('Viewing as Staff');
        $this->assertTrue($access->allows($admin, 'calendar_events.open_play'));
        $this->assertFalse($access->allows($admin, 'bookings.create'));
        $this->assertFalse($access->allows($admin, 'calendar_events.manage'));
        ActivityLogger::log('view_as.test_action', 'Test action while viewing as staff.', actor: $admin);
        $this->assertSame(['view_as' => 'staff'], ActivityLog::query()->where('action', 'view_as.test_action')->firstOrFail()->properties);
        $this->get('/staff/dashboard')->assertOk()->assertSee('data-switch-access', false);
        $this->get('/reports/system')->assertForbidden();
        $this->get('/dashboard')->assertForbidden();
        $this->postJson('/admin/bookings', [])->assertForbidden();
        $this->postJson('/admin/blocks', ['type' => 'closed'])->assertForbidden();
        $this->postJson('/admin/blocks', ['type' => 'maintenance'])->assertForbidden();

        $this->signIn($admin, ['view_as' => 'owner']);
        $this->get('/owner/profile')->assertOk()->assertSee('data-switch-access', false)->assertSee('Viewing as Owner');
        $this->post('/switch-access/reset')->assertRedirect('/admin/dashboard')->assertSessionMissing('view_as');
        $this->signIn($admin, ['view_as' => 'owner']);
        $targetAdmin = $this->user('admin');
        $this->patchJson('/admin/users/'.$targetAdmin->user_id, [
            'role' => 'staff', 'password' => 'password',
        ])->assertForbidden();
        $this->get('/reports/system')->assertForbidden();

        $this->signIn($admin, ['view_as' => 'customer']);
        $this->get('/user/dashboard')->assertOk()->assertSee('data-switch-access', false)
            ->assertSee('This is your own admin account.');
        $this->post('/switch-access/reset')->assertRedirect('/admin/dashboard')->assertSessionMissing('view_as');
    }

    public function test_view_as_is_cleared_for_a_real_non_admin_and_on_logout(): void
    {
        $owner = $this->user('owner');
        $this->signIn($owner, ['view_as' => 'staff'])->get('/owner/profile')
            ->assertOk()->assertSessionMissing('view_as');

        $admin = $this->user('admin');
        $this->signIn($admin, ['view_as' => 'owner'])->post('/logout')
            ->assertRedirect('/login')->assertSessionMissing('view_as');
        $this->assertGuest();

        $customer = $this->user('user');
        $this->withSession(['view_as' => 'staff'])->post('/login', [
            'username' => $customer->username,
            'password' => 'password',
        ])->assertRedirect('/my-dashboard')->assertSessionMissing('view_as');

        $admin = $this->user('admin');
        $this->signIn($admin, ['view_as' => 'owner']);
        $admin->forceFill(['role' => 'owner', 'access_version' => 2])->save();
        $this->get('/owner/profile')->assertRedirect('/login')->assertSessionMissing('view_as');
    }

    private function signIn(User $user, array $extra = []): self
    {
        return $this->actingAs($user)->withSession([
            'access_version_user_id' => $user->user_id,
            'access_version' => (int) $user->access_version,
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $user->user_id,
            ...$extra,
        ]);
    }

    private function user(string $role): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => "view-as-{$role}-{$sequence}",
            'name' => ucfirst($role).' View As',
            'email' => "view-as-{$role}-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'permissions' => $role === 'staff' ? ['bookings.view', 'customers.view'] : null,
            'access_version' => 1,
        ]);
    }
}
