<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Court;
use App\Services\UserAccessService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class OwnerAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, array|null $permissions = null, array $overrides = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_merge([
            'username' => "owner-area-{$sequence}",
            'name' => ucfirst($role).' Area User',
            'email' => "owner-area-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'permissions' => $permissions,
        ], $overrides));
    }

    private function signIn(User $user): self
    {
        return $this->actingAs($user)->withSession([
            'access_version_user_id' => $user->user_id,
            'access_version' => 1,
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $user->user_id,
        ]);
    }

    public function test_all_owner_pages_render_for_owners_and_return_403_for_admins_and_staff(): void
    {
        $owner = $this->user('owner');
        $admin = $this->user('admin');
        $staff = $this->user('staff', ['bookings.view']);
        $pages = [
            '/owner/dashboard', '/owner/bookings', '/owner/courts', '/owner/configuration',
            '/owner/profile', '/owner/equipment', '/owner/customers', '/owner/user-access',
            '/owner/announcements', '/owner/reports', '/owner/activity-logs',
        ];

        foreach ($pages as $path) {
            $response = $this->signIn($owner)->get($path)->assertOk();
            $response->assertSee('shared/dashboard-shell.css')->assertSee('shared/dashboard-shell.js');
            $this->assertStringNotContainsString('css/admin/admin-shell.css', $response->getContent());
            $this->assertStringNotContainsString('js/admin/admin-shell.js', $response->getContent());
            if (config('auth.multi_guard')) {
                $this->signIn($admin)->get($path)->assertRedirect(route('owner.login'));
                $this->signIn($staff)->get($path)->assertRedirect(route('owner.login'));
            } else {
                $this->signIn($admin)->get($path)->assertForbidden();
                $this->signIn($staff)->get($path)->assertForbidden();
            }
        }
    }

    public function test_owner_sidebar_has_the_complete_navigation_and_reports_omits_system_health(): void
    {
        $owner = $this->user('owner');
        $home = $this->signIn($owner)->get('/owner/dashboard')->assertOk();
        foreach (['Overview', 'Bookings', 'Courts', 'Configuration', 'Profile', 'Equipment Availability', 'Customers', 'User access', 'Announcements', 'Reports', 'Activity Logs'] as $label) {
            $home->assertSee($label);
        }

        $reports = $this->get('/owner/reports')->assertOk();
        $reports->assertSee('Income Trend')->assertSee('owner-reports.js')->assertDontSee('System Health');
        $reports->assertDontSee('trafficRangeSelect')->assertDontSee('data-system-url');
        $reports->assertDontSee('js/admin/admin-reports.js');
    }

    public function test_reports_system_capability_is_admin_only_and_owner_data_has_no_system_fields(): void
    {
        $owner = $this->user('owner');
        $admin = $this->user('admin');
        $staff = $this->user('staff');
        $access = app(UserAccessService::class);

        self::assertTrue($access->allows($owner, 'bookings.view'));
        self::assertFalse($access->allows($owner, 'reports.system'));
        self::assertTrue($access->allows($admin, 'reports.system'));
        self::assertFalse($access->allows($staff, 'reports.system'));

        if (config('auth.multi_guard')) {
            $this->signIn($owner)->getJson('/owner/reports/system')->assertNotFound();
        } else {
            $this->signIn($owner)->getJson('/reports/system')->assertForbidden();
        }
        $ownerData = $this->getJson(role_route('admin.reports.data'))->assertOk()->json();
        self::assertSame(['summary', 'income_split', 'by_payment_method', 'by_court', 'trend'], array_keys($ownerData));
        self::assertArrayNotHasKey('database', $ownerData);
        self::assertArrayNotHasKey('server', $ownerData);
        self::assertArrayNotHasKey('traffic', $ownerData);
        self::assertArrayNotHasKey('users_online', $ownerData);

        $adminPage = $this->signIn($admin)->get('/reports')->assertOk();
        $adminPage->assertSee('System Health')->assertSee('admin-reports.js');
        $this->getJson('/reports/system')->assertOk()->assertJsonStructure(['database', 'server', 'traffic', 'users_online']);
    }

    public function test_owner_is_redirected_from_admin_page_urls_to_the_owner_equivalent(): void
    {
        $owner = $this->user('owner');
        if (config('auth.multi_guard')) {
            $this->signIn($owner)->get('/dashboard')->assertRedirect(route('admin.login'));
            return;
        }

        foreach ([
            '/dashboard' => '/owner/dashboard',
            '/bookings' => '/owner/bookings',
            '/courts' => '/owner/courts',
            '/admin/configuration' => '/owner/configuration',
            '/admin/profile' => '/owner/profile',
            '/admin/equipment/availability' => '/owner/equipment',
            '/customers' => '/owner/customers',
            '/admin/user-access' => '/owner/user-access',
            '/announcements' => '/owner/announcements',
            '/reports' => '/owner/reports',
            '/activity_logs' => '/owner/activity-logs',
        ] as $adminPath => $ownerPath) {
            $this->signIn($owner)->get($adminPath)->assertRedirect($ownerPath);
        }
    }

    public function test_owner_user_access_can_promote_an_account_to_staff_but_not_owner_and_profile_can_be_updated(): void
    {
        $owner = $this->user('owner');
        $candidate = $this->user('user');
        $this->signIn($owner)->postJson(role_route('admin.users.store'), [
            'user_id' => $candidate->user_id,
            'role' => 'staff',
            'password' => 'password',
        ])->assertCreated()->assertJsonPath('user.role', 'staff');

        $otherCandidate = $this->user('user');
        $this->postJson(role_route('admin.users.store'), [
            'user_id' => $otherCandidate->user_id,
            'role' => 'owner',
            'password' => 'password',
        ])->assertForbidden();

        $this->put('/owner/profile', [
            'name' => 'Updated Owner',
            'username' => $owner->username,
            'email' => $owner->email,
            'phone_number' => '09171234567',
        ])->assertRedirect(route(config('auth.multi_guard') ? 'owner.profile' : 'admin.profile'));
        self::assertSame('Updated Owner', $owner->fresh()->name);
    }

    public function test_password_and_google_login_choose_the_owner_dashboard(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $owner = $this->user('owner', null, ['mfa_enabled' => true, 'mfa_secret' => $secret]);
        $login = $this->postJson('/login', ['username' => $owner->username, 'password' => 'password'])
            ->assertOk()->assertJsonPath('mfa_required', true)->assertJsonPath('mfa_type', 'challenge');
        $otp = (new Google2FA())->getCurrentOtp($secret);
        $verifyPath = config('auth.multi_guard') ? '/owner/mfa/verify' : '/mfa/verify';
        $this->postJson($verifyPath, ['code' => $otp])->assertOk()->assertJsonPath('redirect', '/owner/dashboard');

        config(['services.google.client_id' => 'owner-area-client']);
        $token = $this->fakeGoogleToken($owner->email, $owner->name, 'owner-area-client');
        $this->postJson('/auth/google', ['id_token' => $token])
            ->assertOk()->assertJsonPath('redirect', '/owner/dashboard');
    }

    public function test_owner_dashboard_excludes_cancelled_bookings_and_admin_pages_keep_their_layout(): void
    {
        $owner = $this->user('owner');
        $admin = $this->user('admin');
        BusinessSetting::current();
        $court = Court::create([
            'name' => 'Owner Court', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active',
        ]);
        foreach ([['Cancelled Customer', 'cancelled'], ['Upcoming Customer', 'paid']] as [$name, $status]) {
            Booking::create([
                'user_id' => null, 'customer_name' => $name, 'contact_number' => '09171234567',
                'court_id' => $court->id, 'date' => now()->addDay()->toDateString(),
                'start_time' => '09:00', 'end_time' => '10:00', 'status' => $status,
                'source' => 'online', 'amount' => 500,
            ]);
        }
        $ownerHome = $this->signIn($owner)->get('/owner/dashboard')->assertOk();
        $ownerHome->assertSee('shared/dashboard-shell.css')->assertSee('Overview')->assertSee('Upcoming Customer')->assertDontSee('Cancelled Customer');

        $adminHome = $this->signIn($admin)->get('/dashboard')->assertOk();
        $adminHome->assertSee('shared/dashboard-shell.css')->assertSee('Total Income')->assertSee('Upcoming Customer')->assertDontSee('Cancelled Customer');
    }

    public function test_staff_admin_and_owner_overview_counts_exclude_cancelled_and_expired_bookings(): void
    {
        $testNow = CarbonImmutable::parse(now('Asia/Manila')->toDateString().' 12:00:00', 'Asia/Manila');
        Carbon::setTestNow($testNow);
        CarbonImmutable::setTestNow($testNow);

        $staff = $this->user('staff', ['bookings.view']);
        $admin = $this->user('admin');
        $owner = $this->user('owner');
        BusinessSetting::current();
        $court = Court::create([
            'name' => 'Count Court', 'width' => 10, 'length' => 20, 'hourly_rate' => 500, 'status' => 'active',
        ]);

        foreach ([
            ['Active Customer', 'paid'],
            ['Pending Customer', 'pending'],
            ['Cancelled Customer', 'cancelled'],
            ['Expired Customer', 'expired'],
        ] as [$name, $status]) {
            Booking::create([
                'user_id' => null, 'customer_name' => $name, 'contact_number' => '09171234567',
                'court_id' => $court->id, 'date' => now('Asia/Manila')->toDateString(),
                'start_time' => '23:00', 'end_time' => '23:30', 'status' => $status,
                'source' => 'online', 'amount' => 250,
            ]);
        }

        $staffData = $this->signIn($staff)->getJson('/staff/overview/data')->assertOk();
        $staffData->assertJsonPath('booking_count', 2);
        $this->assertSame(['Active Customer', 'Pending Customer'], array_column($staffData->json('upcoming'), 'customer'));
        $this->assertSame(['Pending Customer'], array_column($staffData->json('needs_attention'), 'customer'));

        $adminPage = $this->signIn($admin)->get('/dashboard')->assertOk();
        $adminPage->assertViewHas('todayBookings', 2);
        $adminPage->assertViewHas('upcomingBookings', fn ($rows) => $rows->pluck('customer_name')->all() === ['Active Customer', 'Pending Customer']);

        $ownerPage = $this->signIn($owner)->get('/owner/dashboard')->assertOk();
        $ownerPage->assertViewHas('todayBookings', 2);
        $ownerPage->assertViewHas('upcomingBookings', fn ($rows) => $rows->pluck('customer_name')->all() === ['Active Customer', 'Pending Customer']);
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
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        $header = $b64(json_encode(['alg' => 'RS256', 'kid' => 'test-kid', 'typ' => 'JWT']));
        $payload = $b64(json_encode([
            'iss' => 'https://accounts.google.com', 'aud' => $clientId,
            'exp' => now()->addHour()->timestamp, 'email' => $email,
            'email_verified' => true, 'name' => $name, 'jti' => bin2hex(random_bytes(8)),
        ]));
        openssl_sign("$header.$payload", $sig, $key, OPENSSL_ALGO_SHA256);

        Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [[
            'kid' => 'test-kid', 'alg' => 'RS256', 'kty' => 'RSA', 'use' => 'sig',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]])]);

        return "$header.$payload.".$b64($sig);
    }

    public function test_google_login_requires_mfa_for_enabled_customer(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $customer = $this->user('user', null, [
            'mfa_enabled' => true,
            'mfa_method' => 'totp',
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => [],
        ]);

        config(['services.google.client_id' => 'customer-mfa-google']);
        $token = $this->fakeGoogleToken($customer->email, $customer->name, 'customer-mfa-google');
        $this->postJson('/auth/google', ['id_token' => $token])
            ->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonPath('mfa_type', 'challenge');

        $this->get('/my-dashboard')->assertRedirect(route('mfa.challenge'));
    }

    public function test_google_login_does_not_skip_mfa_for_owner_pages(): void
    {
        $secret = (new Google2FA())->generateSecretKey();
        $owner = $this->user('owner', null, ['mfa_enabled' => true, 'mfa_secret' => $secret]);

        config(['services.google.client_id' => 'owner-area-client']);
        $token = $this->fakeGoogleToken($owner->email, $owner->name, 'owner-area-client');
        $this->postJson('/auth/google', ['id_token' => $token])->assertOk();

        $response = $this->get('/owner/dashboard');
        $this->assertContains($response->getStatusCode(), [302, 403], 'Unexpected status: '.$response->getStatusCode());
        $this->assertNotSame(200, $response->getStatusCode());
    }
}
