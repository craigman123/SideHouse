<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\UserAccessService;
use App\Support\ActivityLogger;
use App\Support\GuardSessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
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
            ->assertSessionHas(GuardSessionState::key('view_as', config('auth.multi_guard') ? 'admin' : null), 'staff');

        $log = ActivityLog::query()->where('action', 'view_as.switched')->firstOrFail();
        $this->assertSame($admin->user_id, $log->user_id);
        $this->assertSame(['from' => 'admin', 'to' => 'staff'], $log->properties);

        $this->post('/switch-access/reset')
            ->assertRedirect(config('auth.multi_guard') ? '/dashboard' : '/admin/dashboard')
            ->assertSessionMissing(GuardSessionState::key('view_as', config('auth.multi_guard') ? 'admin' : null));
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
            $denied = $this->signIn($this->user($role))->post('/switch-access', ['role' => 'admin']);
            if (config('auth.multi_guard')) {
                $denied->assertRedirect(route('admin.login'));
            } else {
                $denied->assertForbidden();
            }
            $this->get($page)->assertOk()->assertDontSee('data-switch-access');
        }

        $admin = $this->user('admin');
        $this->signIn($admin)->get('/dashboard')->assertOk()->assertSee('data-switch-access', false);
        $this->signIn($admin)->postJson('/switch-access', ['role' => 'superuser'])->assertUnprocessable();
        $this->postJson('/switch-access/reset')->assertRedirect(config('auth.multi_guard') ? '/dashboard' : '/admin/dashboard');
    }

    public function test_effective_role_and_permissions_follow_view_mode_and_panel_is_available_in_each_layout(): void
    {
        $admin = $this->user('admin');
        $access = app(UserAccessService::class);
        Auth::shouldUse('admin');
        $request = Request::create('/');
        $request->setUserResolver(fn () => $admin);
        $session = app('session')->driver();
        foreach ([
            'admin' => 'admin',
            'owner' => 'owner',
            'staff' => 'staff',
            'customer' => 'user',
        ] as $view => $effective) {
            $session->put(GuardSessionState::key('view_as', 'admin'), $view);
            $request->setLaravelSession($session);
            $this->assertSame($effective, $access->effectiveRole($admin, $request));
        }

        $adminHome = '/staff/profile';
        $this->signIn($admin, ['view_as' => 'staff'])->get($adminHome)->assertOk()
            ->assertSee('data-switch-access', false)->assertSee('Viewing as Staff');
        $this->assertTrue($access->allows($admin, 'calendar_events.open_play'));
        $this->assertFalse($access->allows($admin, 'bookings.create'));
        $this->assertFalse($access->allows($admin, 'calendar_events.manage'));
        ActivityLogger::log('view_as.test_action', 'Test action while viewing as staff.', actor: $admin);
        $this->assertSame(['view_as' => 'staff'], ActivityLog::query()->where('action', 'view_as.test_action')->firstOrFail()->properties);
        $this->get('/staff/dashboard')->assertOk()->assertSee('data-switch-access', false);
        $this->get('/reports/system')->assertForbidden();
        if (! config('auth.multi_guard')) {
            $this->get('/dashboard')->assertForbidden();
        }
        $this->postJson(role_route('admin.bookings.walk-in.store'), [])->assertForbidden();
        $this->postJson(role_route('admin.blocks.store'), ['type' => 'closed'])->assertForbidden();
        $this->postJson(role_route('admin.blocks.store'), ['type' => 'maintenance'])->assertForbidden();

        $this->signIn($admin, ['view_as' => 'owner']);
        $this->get('/owner/profile')
            ->assertOk()->assertSee('data-switch-access', false)->assertSee('Viewing as Owner');
        $this->post('/switch-access/reset')
            ->assertRedirect(config('auth.multi_guard') ? '/dashboard' : '/admin/dashboard')
            ->assertSessionMissing(GuardSessionState::key('view_as', config('auth.multi_guard') ? 'admin' : null));
        $this->signIn($admin, ['view_as' => 'owner']);
        $targetAdmin = $this->user('admin');
        $this->patchJson(role_route('admin.users.update', ['user' => $targetAdmin->user_id]), [
            'role' => 'staff', 'password' => 'password',
        ])->assertForbidden();
        $this->get('/reports/system')->assertForbidden();

        $this->signIn($admin, ['view_as' => 'customer']);
        $this->get('/user/dashboard')->assertOk()->assertSee('data-switch-access', false)
            ->assertSee('This is your own admin account.');
        $this->post('/switch-access/reset')
            ->assertRedirect(config('auth.multi_guard') ? '/dashboard' : '/admin/dashboard')
            ->assertSessionMissing(GuardSessionState::key('view_as', config('auth.multi_guard') ? 'admin' : null));
    }

    public function test_view_as_is_cleared_for_a_real_non_admin_and_on_logout(): void
    {
        $owner = $this->user('owner');
        $this->signIn($owner, ['view_as' => 'staff'])->get('/owner/profile')
            ->assertOk()->assertSessionMissing(GuardSessionState::key('view_as', config('auth.multi_guard') ? 'owner' : null));

        $admin = $this->user('admin');
        $this->signIn($admin, ['view_as' => 'owner'])->post(config('auth.multi_guard') ? '/admin/logout' : '/logout')
            ->assertRedirect(route(config('auth.multi_guard') ? 'admin.login' : 'login'))
            ->assertSessionMissing(GuardSessionState::key('view_as', config('auth.multi_guard') ? 'admin' : null));
        $this->assertGuest(config('auth.multi_guard') ? 'admin' : null);

        $customer = $this->user('user');
        $this->withSession(['view_as' => 'staff'])->post('/login', [
            'username' => $customer->username,
            'password' => 'password',
        ])->assertRedirect('/my-dashboard')
            ->assertSessionMissing(GuardSessionState::key('view_as', config('auth.multi_guard') ? 'customer' : null));

        $admin = $this->user('admin');
        $this->signIn($admin, ['view_as' => 'owner']);
        $admin->forceFill(['role' => 'owner', 'access_version' => 2])->save();
        $this->get(config('auth.multi_guard') ? '/dashboard' : '/owner/profile')
            ->assertRedirect(route(config('auth.multi_guard') ? 'admin.login' : 'login'))
            ->assertSessionMissing(GuardSessionState::key('view_as', config('auth.multi_guard') ? 'admin' : null));
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
