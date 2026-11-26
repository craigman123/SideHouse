<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class MultiGuardAuthLanesTest extends TestCase
{
    use RefreshDatabase;
    use UsesMultiGuardEnvironment;

    private $googlePrivateKey;

    private array $googlePublicDetails = [];

    protected function setUp(): void
    {
        $this->setMultiGuardEnvironment(true);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Auth::shouldUse('web');
        parent::tearDown();
        $this->restoreMultiGuardEnvironment();
    }

    public function test_password_login_selects_the_stored_role_and_completes_that_roles_mfa(): void
    {
        $google2fa = new Google2FA();
        $homePaths = [
            'customer' => '/my-dashboard',
            'admin' => '/dashboard',
            'owner' => '/owner/dashboard',
            'staff' => '/staff/dashboard',
        ];

        foreach (['user', 'admin', 'owner', 'staff'] as $role) {
            $guard = $role === 'user' ? 'customer' : $role;
            $user = $this->user($role);
            $secret = $google2fa->generateSecretKey();
            if ($role !== 'user') {
                $user->forceFill([
                    'mfa_enabled' => true,
                    'mfa_secret' => $secret,
                ])->save();
            }

            $response = $this->postJson('/login', [
                'username' => $user->username,
                'password' => 'password',
            ])->assertOk();

            if ($guard === 'customer') {
                $response->assertJsonPath('redirect', $homePaths[$guard]);
            } else {
                $response->assertJsonPath('mfa_required', true)
                    ->assertJsonPath('mfa_type', 'challenge')
                    ->assertJsonPath('mfa_urls.verify', '/'.$guard.'/mfa/verify');

                $this->postJson('/'.$guard.'/mfa/verify', [
                    'code' => $google2fa->getCurrentOtp($secret),
                ])->assertOk()->assertJsonPath('redirect', $homePaths[$guard]);
            }

            $this->assertSame($user->user_id, Auth::guard($guard)->user()?->user_id, $guard);
        }
    }

    public function test_customer_login_accepts_the_login_page_token_after_admin_login_rotates_the_session(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('user');

        $page = $this->get('/login')->assertOk();
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page->getContent(), $matches);
        $oldToken = html_entity_decode($matches[1], ENT_QUOTES);
        $sessionCookieName = config('session.cookie');
        $sessionCookie = $page->getCookie($sessionCookieName);
        $this->assertNotNull($sessionCookie);
        $this->withCookie($sessionCookieName, $sessionCookie->getValue())->withCredentials();
        $initialRefresh = $this->get('/login')->assertOk();
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $initialRefresh->getContent(), $initialMatches);
        $this->assertSame($oldToken, html_entity_decode($initialMatches[1], ENT_QUOTES));

        $adminLogin = $this->post('/login', [
            '_token' => $oldToken,
            'username' => $admin->username,
            'password' => 'password',
        ])->assertRedirect(route('admin.mfa.setup'))
            ->assertSessionHas('_token', $oldToken);

        $sessionCookie = $adminLogin->getCookie($sessionCookieName);
        $this->assertNotNull($sessionCookie);
        $this->withCookie($sessionCookieName, $sessionCookie->getValue());

        $refreshedPage = $this->get('/login')->assertOk();
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $refreshedPage->getContent(), $refreshedMatches);
        $this->assertSame($oldToken, html_entity_decode($refreshedMatches[1], ENT_QUOTES));

        $this->post('/login', [
            '_token' => $oldToken,
            'username' => $customer->username,
            'password' => 'password',
        ])->assertRedirect('/my-dashboard');

        Auth::forgetGuards();
        $this->assertSame($admin->user_id, Auth::guard('admin')->user()?->user_id);
        $this->assertSame($customer->user_id, Auth::guard('customer')->user()?->user_id);
    }

    public function test_csrf_mismatches_redirect_only_for_login_routes_when_multi_guard_is_enabled(): void
    {
        $session = app('session.store');
        $session->start();
        $session->put('_token', str_repeat('a', 40));

        foreach ([['/login', []], ['/login', ['_token' => str_repeat('b', 40)]]] as [$uri, $input]) {
            $response = $this->csrfMismatchResponse($uri, $input, $session);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame(route('login'), $response->headers->get('Location'));
            $this->assertSame(
                'Your page expired. Please try again.',
                $session->get('_flash.new') ? $session->get('errors')->first() : null,
            );
            $this->assertGuest('customer');
        }

        $this->assertSame(419, $this->csrfMismatchResponse('/register', [], $session)->getStatusCode());
    }

    public function test_wrong_username_and_password_return_the_same_generic_error(): void
    {
        $user = $this->user('owner');

        $wrongPassword = $this->postJson('/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertExactJson(['message' => 'Invalid username or password.']);

        $wrongUsername = $this->postJson('/login', [
            'username' => 'no-such-user',
            'password' => 'password',
        ])->assertUnprocessable()->assertExactJson(['message' => 'Invalid username or password.']);

        $this->assertSame($wrongPassword->json(), $wrongUsername->json());
        $this->assertGuest('owner');
    }

    public function test_admin_staff_and_customer_can_log_in_via_the_same_endpoint(): void
    {
        $admin = $this->user('admin');
        $staff = $this->user('staff');
        $customer = $this->user('user');

        foreach ([$admin, $staff, $customer] as $user) {
            $response = $this->postJson('/login', [
                'username' => $user->username,
                'password' => 'password',
            ])->assertOk();

            if ($user->role !== 'user') {
                $response->assertJsonPath('mfa_required', true);
            }
        }

        $this->assertSame($admin->user_id, Auth::guard('admin')->user()?->user_id);
        $this->assertSame($staff->user_id, Auth::guard('staff')->user()?->user_id);
        $this->assertSame($customer->user_id, Auth::guard('customer')->user()?->user_id);
    }

    public function test_relogging_the_same_account_redirects_without_restarting_mfa(): void
    {
        $user = $this->user('staff');

        $this->postJson('/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('mfa_required', true);

        $this->postJson('/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonMissingPath('mfa_required')
            ->assertJsonPath('redirect', '/staff/dashboard');
    }

    public function test_google_login_uses_the_matched_accounts_guard_and_creates_customers_only(): void
    {
        config(['services.google.client_id' => 'multi-guard-test-client']);
        $expectedUsers = [];
        foreach (['admin', 'owner', 'staff', 'user'] as $role) {
            $guard = $role === 'user' ? 'customer' : $role;
            $user = $this->user($role);
            $expectedUsers[$guard] = $user;
            $token = $this->fakeGoogleToken($user->email, $user->name);

            $this->postJson('/auth/google', ['id_token' => $token])
                ->assertOk()
                ->assertJsonPath('redirect', match ($guard) {
                    'admin' => '/dashboard',
                    'owner' => '/owner/dashboard',
                    'staff' => '/staff/dashboard',
                    default => '/my-dashboard',
                });
            $this->assertSame($user->user_id, Auth::guard($guard)->user()?->user_id);
        }

        foreach ($expectedUsers as $guard => $user) {
            $this->assertSame($user->user_id, Auth::guard($guard)->user()?->user_id, $guard);
        }

        $newEmail = 'new-google-customer@example.test';
        $customerToken = $this->fakeGoogleToken($newEmail, 'New Google Customer');
        $customerResponse = $this->postJson('/auth/google', ['id_token' => $customerToken]);
        $this->assertSame(200, $customerResponse->getStatusCode(), $customerResponse->getContent());
        $customerResponse->assertJsonPath('redirect', '/my-dashboard');
        $this->assertDatabaseHas('users', ['email' => $newEmail, 'role' => 'user']);
        $this->assertDatabaseMissing('users', ['email' => $newEmail, 'role' => 'admin']);
    }

    public function test_public_login_page_has_one_form_and_login_and_google_endpoints(): void
    {
        $page = $this->get('/login')->assertOk()
            ->assertSee('action="'.route('login.submit').'"', false)
            ->assertSee('data-google-auth-url="'.route('auth.google').'"', false)
            ->assertSee('data-mfa-setup-init-url="'.route('mfa.setup.init').'"', false)
            ->assertSee('data-mfa-enable-url="'.route('mfa.enable').'"', false)
            ->assertSee('data-mfa-verify-url="'.route('mfa.verify').'"', false)
            ->assertSee('Register');

        $this->assertSame(1, substr_count($page->getContent(), 'id="login-form"'));
        $this->assertSame(1, substr_count($page->getContent(), 'data-google-auth-url='));

        foreach (['admin', 'owner', 'staff'] as $role) {
            $this->get('/'.$role.'/login')->assertRedirect(route('login'));
            $this->post('/'.$role.'/login')->assertMethodNotAllowed();
            $this->post('/'.$role.'/auth/google')->assertNotFound();
        }

        $this->get('/register')->assertOk();
        $this->get('/admin/register')->assertNotFound();
        $this->post('/admin/register')->assertNotFound();
    }

    public function test_standalone_mfa_forms_post_to_the_selected_guard_lane(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'admin')
            ->withSession([
                'access_version_user_id_admin' => $admin->user_id,
                'access_version_admin' => 1,
            ])
            ->get('/admin/mfa/setup')
            ->assertOk()
            ->assertSee('action="'.route('admin.mfa.enable').'"', false);

        $owner = $this->user('owner');
        $owner->forceFill([
            'mfa_enabled' => true,
            'mfa_secret' => 'TESTSECRET',
        ])->save();

        $this->actingAs($owner, 'owner')
            ->withSession([
                'access_version_user_id_owner' => $owner->user_id,
                'access_version_owner' => 1,
            ])
            ->get('/owner/mfa/challenge')
            ->assertOk()
            ->assertSee('action="'.route('owner.mfa.verify').'"', false);
    }

    public function test_login_attempt_limit_still_applies_to_the_public_login_endpoint(): void
    {
        $user = $this->user('user');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', [
                'username' => $user->username,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_logout_ends_only_the_selected_guard_session(): void
    {
        $customer = $this->user('user');
        $admin = $this->user('admin');

        $this->postJson('/login', [
            'username' => $customer->username,
            'password' => 'password',
        ])->assertOk();
        $this->postJson('/login', [
            'username' => $admin->username,
            'password' => 'password',
        ])->assertOk();

        $this->post('/logout')->assertRedirect(route('login'));
        Auth::forgetGuards();
        $this->assertGuest('customer');
        $this->assertSame($admin->user_id, Auth::guard('admin')->user()?->user_id);

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        Auth::forgetGuards();
        $this->assertGuest('admin');
    }

    private function user(string $role): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => "multi-guard-{$role}-{$sequence}",
            'name' => ucfirst($role).' Multi Guard',
            'email' => "multi-guard-{$role}-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
            'access_version' => 1,
        ]);
    }

    private function csrfMismatchResponse(string $uri, array $input, $session)
    {
        $request = Request::create($uri, 'POST', $input);
        $request->setLaravelSession($session);
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $middleware = new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        try {
            $middleware->handle($request, fn () => response()->noContent());
            $this->fail('The invalid CSRF token was accepted.');
        } catch (TokenMismatchException $exception) {
            return $this->app
                ->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
                ->render($request, $exception);
        }
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
                'kid' => 'lane-test-kid',
                'alg' => 'RS256',
                'kty' => 'RSA',
                'use' => 'sig',
                'n' => $base64Url($this->googlePublicDetails['rsa']['n']),
                'e' => $base64Url($this->googlePublicDetails['rsa']['e']),
            ]]])]);
        }

        $base64Url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        $header = $base64Url(json_encode(['alg' => 'RS256', 'kid' => 'lane-test-kid', 'typ' => 'JWT']));
        $payload = $base64Url(json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'multi-guard-test-client',
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
