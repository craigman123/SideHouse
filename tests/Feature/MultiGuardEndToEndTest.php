<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\User\LumiController;
use App\Models\User;
use App\Services\LumiSuggestionService;
use App\Support\GuardSessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class MultiGuardEndToEndTest extends TestCase
{
    use RefreshDatabase;
    use UsesMultiGuardEnvironment;

    protected function setUp(): void
    {
        $this->setMultiGuardEnvironment(true);
        parent::setUp();
        $this->assertTrue((bool) config('auth.multi_guard'));
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        parent::tearDown();
        $this->restoreMultiGuardEnvironment();
    }

    public function test_four_role_sessions_reach_their_dashboards_and_one_logout_preserves_the_other_three(): void
    {
        [$users] = $this->loginAllRoles();

        $adminHome = $this->get('/dashboard');
        $this->assertSame(200, $adminHome->getStatusCode(), 'Admin redirect: '.$adminHome->headers->get('Location'));
        $adminHome->assertSee('action="'.route('admin.logout').'"', false)
            ->assertSee('href="'.route('admin.user-access.page').'"', false);
        $ownerHome = $this->get('/owner/dashboard')->assertOk();
        $ownerHome->assertSee('action="'.route('owner.logout').'"', false)
            ->assertSee('href="'.route('owner.user-access').'"', false);
        $staffHome = $this->get('/staff/dashboard')->assertOk();
        $staffHome->assertSee('action="'.route('staff.logout').'"', false)
            ->assertSee('href="'.route('staff.bookings').'"', false);
        $customerHome = $this->get('/my-dashboard')->assertOk();
        $customerHome->assertSee('href="'.route('user.dashboard').'"', false)
            ->assertSee('data-suggestions-url="/lumi/suggestions"', false);

        $adminBookings = $this->get('/bookings?view=week')->assertOk();
        $adminBookings->assertSee('data-calendar-url="'.route('admin.bookings.calendar').'"', false);
        $ownerBookings = $this->get('/owner/bookings?view=week')->assertOk();
        $ownerBookings->assertSee('data-calendar-url="'.route('owner.bookings.calendar').'"', false);
        $staffBookings = $this->get('/staff/bookings?view=week')->assertOk();
        $staffBookings->assertSee('data-calendar-url="'.route('staff.bookings.calendar').'"', false);
        $customerProfile = $this->get('/profile')->assertOk();
        $customerProfile->assertSee('action="'.route('logout').'"', false);

        $this->post('/owner/logout')->assertRedirect(route('owner.login'));
        Auth::forgetGuards();
        $this->get('/owner/dashboard')->assertRedirect(route('owner.login'));
        $this->get('/dashboard')->assertOk();
        $this->get('/staff/dashboard')->assertOk();
        $this->get('/my-dashboard')->assertOk();

        $this->assertSame($users['admin']->user_id, Auth::guard('admin')->user()?->user_id);
        $this->assertNull(Auth::guard('owner')->user());
        $this->assertSame($users['staff']->user_id, Auth::guard('staff')->user()?->user_id);
        $this->assertSame($users['customer']->user_id, Auth::guard('customer')->user()?->user_id);
    }

    public function test_customer_account_deletion_logs_out_only_the_customer_guard(): void
    {
        [$users, $session] = $this->loginAllRoles();
        $session->put([
            GuardSessionState::key('lumi_suggestions_seen', 'admin') => true,
            GuardSessionState::key('lumi_suggestions_seen', 'customer') => true,
        ]);

        $this->delete('/profile', ['confirmation' => 'DELETE MY ACCOUNT'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['user_id' => $users['customer']->user_id]);
        $this->assertNull($session->get(Auth::guard('customer')->getName()));
        $this->assertNull($session->get(GuardSessionState::key('lumi_suggestions_seen', 'customer')));
        $this->assertTrue($session->get(GuardSessionState::key('lumi_suggestions_seen', 'admin')));

        Auth::forgetGuards();
        $this->get('/dashboard')->assertOk();
        $this->get('/owner/dashboard')->assertOk();
        $this->get('/staff/dashboard')->assertOk();
    }

    public function test_admin_profile_account_deletion_does_not_invalidate_other_role_sessions(): void
    {
        [$users, $session] = $this->loginAllRoles();
        $accountToDelete = $this->user('user');

        Auth::guard('admin')->login($accountToDelete);
        Auth::shouldUse('admin');
        $adminSessionKey = Auth::guard('admin')->getName();
        $session->put([
            GuardSessionState::key('access_version_user_id', 'admin') => $accountToDelete->user_id,
            GuardSessionState::key('lumi_suggestions_seen', 'admin') => true,
        ]);

        $request = Request::create('/admin/profile', 'DELETE', [
            'confirmation' => 'DELETE MY ACCOUNT',
        ]);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => Auth::guard('admin')->user());
        $response = (new AdminProfileController)->destroyAccount($request);

        $this->assertSame(route('admin.login'), $response->headers->get('Location'));
        $this->assertDatabaseMissing('users', ['user_id' => $accountToDelete->user_id]);
        $this->assertNull($session->get($adminSessionKey));
        $this->assertNull($session->get(GuardSessionState::key('access_version_user_id', 'admin')));
        $this->assertSame($users['owner']->user_id, $session->get(Auth::guard('owner')->getName()));
        $this->assertSame($users['staff']->user_id, $session->get(Auth::guard('staff')->getName()));
        $this->assertSame($users['customer']->user_id, $session->get(Auth::guard('customer')->getName()));

        Auth::forgetGuards();
        $this->get('/owner/dashboard')->assertOk();
        $this->get('/staff/dashboard')->assertOk();
        $this->get('/my-dashboard')->assertOk();
    }

    public function test_lumi_ui_state_is_scoped_to_the_customer_guard(): void
    {
        [$users, $session] = $this->loginAllRoles();
        $session->put([
            GuardSessionState::key('lumi_login_popover_pending', 'admin') => true,
            GuardSessionState::key('lumi_login_popover_shown', 'admin') => true,
            GuardSessionState::key('lumi_suggestions_seen', 'admin') => true,
            GuardSessionState::key('lumi_login_popover_pending', 'customer') => true,
        ]);
        Auth::shouldUse('customer');

        $request = Request::create('/lumi/suggestions', 'GET');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $users['customer']);
        $response = (new LumiController)->suggestions($request, app(LumiSuggestionService::class));
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['show_login_popover']);
        $this->assertNull($session->get(GuardSessionState::key('lumi_login_popover_pending', 'customer')));
        $this->assertTrue($session->get(GuardSessionState::key('lumi_login_popover_pending', 'admin')));
        $this->assertTrue($session->get(GuardSessionState::key('lumi_login_popover_shown', 'admin')));

        (new LumiController)->show(app(LumiSuggestionService::class));
        $this->assertTrue($session->get(GuardSessionState::key('lumi_suggestions_seen', 'customer')));
        $this->assertTrue($session->get(GuardSessionState::key('lumi_suggestions_seen', 'admin')));
    }

    public function test_unmapped_authenticated_request_fails_loudly_with_multi_guard_enabled(): void
    {
        Route::get('/test/unmapped-multi-guard-request', fn () => response()->noContent())
            ->middleware(['web', 'auth'])
            ->name('test.unmapped-multi-guard-request');

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('test.unmapped-multi-guard-request');
        $this->get('/test/unmapped-multi-guard-request');
    }

    private function loginAllRoles(): array
    {
        $users = [
            'admin' => $this->user('admin'),
            'owner' => $this->user('owner'),
            'staff' => $this->user('staff', ['bookings.view', 'customers.view', 'calendar_events.manage']),
            'customer' => $this->user('user'),
        ];
        $session = app('session.store');
        $session->start();

        foreach ($users as $guard => $user) {
            Auth::guard($guard)->login($user);
            $this->assertSame(
                $user->user_id,
                $session->get(Auth::guard($guard)->getName()),
                "{$guard} authentication state was not stored in the shared session.",
            );
            $session->put([
                GuardSessionState::key('access_version_user_id', $guard) => $user->user_id,
                GuardSessionState::key('access_version', $guard) => $user->access_version,
            ]);
            if ($guard !== 'customer') {
                $session->put([
                    GuardSessionState::key('mfa_passed_at', $guard) => now()->timestamp,
                    GuardSessionState::key('mfa_passed_user_id', $guard) => $user->user_id,
                ]);
            }
        }

        $this->withSession($session->all());

        return [$users, $session];
    }

    private function user(string $role, ?array $permissions = null): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => "multi-e2e-{$role}-{$sequence}",
            'name' => ucfirst($role).' E2E User',
            'email' => "multi-e2e-{$role}-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'permissions' => $permissions,
            'access_version' => 1,
        ]);
    }
}
