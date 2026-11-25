<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PasswordHasher;
use App\Support\PasswordPepperBootGuard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PasswordPepperTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_can_log_in_with_a_peppered_password(): void
    {
        $user = $this->makeUser('new-password-123');

        $this->postJson('/login', [
            'username' => $user->username,
            'password' => 'new-password-123',
        ])->assertOk();

        $this->assertSame('v1', $user->fresh()->password_pepper_version);
        $this->assertTrue(app(PasswordHasher::class)->check($user->fresh(), 'new-password-123'));
        $this->assertArrayNotHasKey('password', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('password_pepper_version', $user->fresh()->toArray());
    }

    public function test_legacy_user_is_upgraded_on_successful_login_and_remains_authenticated(): void
    {
        $user = $this->insertLegacyUser('legacy-password-123');

        $this->postJson('/login', [
            'username' => $user->username,
            'password' => 'legacy-password-123',
        ])->assertOk();

        $upgraded = $user->fresh();
        $this->assertSame('v1', $upgraded->password_pepper_version);
        $this->assertTrue(app(PasswordHasher::class)->check($upgraded, 'legacy-password-123'));

        $this->get('/my-dashboard')->assertOk();
        $this->postJson('/login', [
            'username' => $user->username,
            'password' => 'legacy-password-123',
        ])->assertOk();
    }

    public function test_wrong_password_fails(): void
    {
        $user = $this->makeUser('correct-password-123');

        $this->postJson('/login', [
            'username' => $user->username,
            'password' => 'wrong-password-123',
        ])->assertUnprocessable();

        $this->assertSame('v1', $user->fresh()->password_pepper_version);
    }

    public function test_user_on_old_pepper_version_logs_in_and_is_upgraded(): void
    {
        config([
            'password_pepper.current_version' => 'v0',
            'password_pepper.peppers.v0' => 'old-test-pepper-that-is-more-than-32-bytes',
        ]);
        $user = $this->makeUser('rotating-password-123');

        config([
            'password_pepper.current_version' => 'v1',
            'password_pepper.peppers.v1' => 'test-only-password-pepper-not-for-production-32-bytes',
        ]);

        $this->postJson('/login', [
            'username' => $user->username,
            'password' => 'rotating-password-123',
        ])->assertOk();

        $upgraded = $user->fresh();
        $this->assertSame('v1', $upgraded->password_pepper_version);
        $this->assertTrue(app(PasswordHasher::class)->check($upgraded, 'rotating-password-123'));
    }

    public function test_missing_pepper_fails_for_production_web_and_queue_but_not_build_commands(): void
    {
        config([
            'password_pepper.current_version' => 'v1',
            'password_pepper.peppers.v1' => null,
        ]);
        $guard = app(PasswordPepperBootGuard::class);

        foreach ([[false, null], [true, 'queue:work']] as [$runningInConsole, $command]) {
            try {
                $guard->assertConfiguredForRuntime(true, $runningInConsole, $command);
                $this->fail('Production runtime should require the current pepper.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_build_and_migration_commands_do_not_require_the_production_pepper(): void
    {
        config([
            'password_pepper.current_version' => 'v1',
            'password_pepper.peppers.v1' => null,
        ]);
        $guard = app(PasswordPepperBootGuard::class);

        $guard->assertConfiguredForRuntime(true, true, 'package:discover');
        $guard->assertConfiguredForRuntime(true, true, 'config:cache');
        $guard->assertConfiguredForRuntime(true, true, 'migrate');
        $guard->assertConfiguredForRuntime(true, true, 'composer');

        $this->expectException(RuntimeException::class);
        $guard->assertConfiguredForRuntime(true, true, 'queue:work');
    }

    public function test_production_seeder_requires_a_safe_explicit_password(): void
    {
        app()['env'] = 'production';

        foreach ([null, '', 'password', ' password '] as $seedPassword) {
            config(['password_pepper.seed_admin_password' => $seedPassword]);

            try {
                app(DatabaseSeeder::class)->run();
                $this->fail('Production seeding should reject an unsafe password.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('SEED_ADMIN_PASSWORD', $exception->getMessage());
            }
        }
    }

    public function test_development_seeder_uses_a_private_named_default(): void
    {
        config(['password_pepper.seed_admin_password' => null]);

        app(DatabaseSeeder::class)->run();

        $user = User::where('email', 'admin@sidehouse.com')->firstOrFail();
        $this->assertTrue(app(PasswordHasher::class)->check(
            $user,
            'sidehouse-local-only-seed-password'
        ));
    }

    public function test_no_direct_password_make_or_check_calls_remain_outside_the_password_layer(): void
    {
        $allowed = [
            'app/Support/PasswordHasher.php',
            'app/Auth/PepperedEloquentUserProvider.php',
            'app/Rules/CurrentPassword.php',
        ];
        $pattern = '/Hash::(?:make|check)\s*\(/';
        $violations = [];

        foreach (['app', 'database', 'routes', 'tests'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                if (in_array($relativePath, $allowed, true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if (is_string($contents) && preg_match($pattern, $contents)) {
                    $violations[] = $relativePath;
                }
            }
        }

        $this->assertSame([], $violations, 'Direct Hash::make/check calls found in: '.implode(', ', $violations));
    }

    private function makeUser(string $password): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => 'pepper-user-'.$sequence,
            'name' => 'Pepper User',
            'email' => 'pepper-user-'.$sequence.'@example.test',
            'password' => $password,
            'role' => 'user',
        ]);
    }

    private function insertLegacyUser(string $password): User
    {
        static $sequence = 0;
        $sequence++;
        $user = $this->makeUser('temporary-password-123');
        $legacyHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);

        DB::table('users')->where('user_id', $user->user_id)->update([
            'password' => $legacyHash,
            'password_pepper_version' => null,
        ]);

        return $user->fresh();
    }
}
