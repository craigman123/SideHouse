<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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

    public function test_password_login_lanes_accept_only_their_stored_role(): void
    {
        foreach ([
            'customer' => ['/login', 'user'],
            'admin' => ['/admin/login', 'admin'],
            'owner' => ['/owner/login', 'owner'],
            'staff' => ['/staff/login', 'staff'],
        ] as $guard => [$path, $role]) {
            $user = $this->user($role);
            $response = $this->postJson($path, [
                'username' => $user->username,
                'password' => 'password',
            ])->assertOk();

            if ($guard === 'customer') {
                $response->assertJsonPath('redirect', '/my-dashboard');
            } else {
                $response->assertJsonPath('mfa_required', true);
            }

            $this->assertSame($user->user_id, Auth::guard($guard)->user()?->user_id, $path);
        }
    }

    public function test_wrong_role_credentials_return_the_generic_login_error(): void
    {
        $owner = $this->user('owner');

        $this->postJson('/admin/login', [
            'username' => $owner->username,
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertExactJson(['message' => 'Invalid username or password.']);

        $this->assertGuest('admin');
        $this->postJson('/login', [
            'username' => $owner->username,
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertExactJson(['message' => 'Invalid username or password.']);
    }

    public function test_google_lanes_check_roles_and_only_customer_lane_creates_accounts(): void
    {
        config(['services.google.client_id' => 'multi-guard-test-client']);
        $staff = $this->user('staff');
        $token = $this->fakeGoogleToken($staff->email, $staff->name);

        $this->postJson('/staff/auth/google', ['id_token' => $token])
            ->assertOk()
            ->assertJsonPath('redirect', '/staff/dashboard');
        $this->assertSame($staff->user_id, Auth::guard('staff')->user()?->user_id);

        $admin = $this->user('admin');
        $mismatchToken = $this->fakeGoogleToken($admin->email, $admin->name);
        $this->postJson('/owner/auth/google', ['id_token' => $mismatchToken])
            ->assertUnprocessable()
            ->assertExactJson(["message" => "We couldn't verify that Google sign-in. Please try again."]);
        $this->assertGuest('owner');

        $newEmail = 'new-google-customer@example.test';
        $privilegedToken = $this->fakeGoogleToken($newEmail, 'New Google Customer');
        $this->postJson('/admin/auth/google', ['id_token' => $privilegedToken])
            ->assertUnprocessable()
            ->assertExactJson(["message" => "We couldn't verify that Google sign-in. Please try again."]);
        $this->assertDatabaseMissing('users', ['email' => $newEmail]);

        $customerToken = $this->fakeGoogleToken($newEmail, 'New Google Customer');
        $customerResponse = $this->postJson('/auth/google', ['id_token' => $customerToken]);
        $this->assertSame(200, $customerResponse->getStatusCode(), $customerResponse->getContent());
        $customerResponse
            ->assertJsonPath('redirect', '/my-dashboard');
        $this->assertDatabaseHas('users', ['email' => $newEmail, 'role' => 'user']);
    }

    public function test_login_page_uses_lane_urls_and_registration_is_customer_only(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('action="'.route('admin.login.submit').'"', false)
            ->assertSee('data-google-auth-url="'.route('admin.auth.google').'"', false)
            ->assertSee('data-mfa-setup-init-url="'.route('admin.mfa.setup.init').'"', false)
            ->assertSee('data-mfa-enable-url="'.route('admin.mfa.enable').'"', false)
            ->assertSee('data-mfa-verify-url="'.route('admin.mfa.verify').'"', false)
            ->assertDontSee('Register');

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

    public function test_login_attempt_limits_are_independent_per_lane(): void
    {
        $user = $this->user('user');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/login', [
                'username' => $user->username,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/admin/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    public function test_logout_ends_only_the_selected_guard_session(): void
    {
        $customer = $this->user('user');
        $admin = $this->user('admin');

        $this->postJson('/login', [
            'username' => $customer->username,
            'password' => 'password',
        ])->assertOk();
        $this->postJson('/admin/login', [
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
