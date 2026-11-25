<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\BusinessSetting;
use App\Models\Court;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLumiPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lumi_page_has_adjacent_topbar_link_and_whats_new_navigation(): void
    {
        $user = $this->makeCustomer();
        $response = $this->signIn($user)->get('/lumi')->assertOk();
        $html = $response->getContent();

        $themeToggle = strpos($html, 'data-theme-toggle');
        $themeButtonEnd = strpos($html, '</button>', $themeToggle);
        $lumiLink = strpos($html, 'aria-label="Open Lumi"');
        $overview = strpos($html, '<span>Overview</span>');
        $whatsNew = strpos($html, "<span>What's New?</span>");

        $this->assertNotFalse($themeToggle);
        $this->assertNotFalse($themeButtonEnd);
        $this->assertNotFalse($lumiLink);
        $this->assertNotFalse($overview);
        $this->assertNotFalse($whatsNew);
        $this->assertLessThan($lumiLink, $themeButtonEnd);
        $this->assertLessThan($whatsNew, $overview);
        $response->assertSee('data-user-lumi-button', false)
            ->assertSee('data-href="/lumi"', false);
        $response->assertSee('Your Artificial Assistant')->assertSee('aria-current="page"', false);
    }

    public function test_dashboard_has_no_algorithm_widget_and_whats_new_announces_lumi(): void
    {
        $user = $this->makeCustomer();
        $this->signIn($user)->get('/my-dashboard')->assertOk()->assertDontSee('Algorithm');

        $this->get('/whats-new')->assertOk()
            ->assertSee('Meet Lumi, your Artificial Assistant')
            ->assertSee('Lumi now has a dedicated page')
            ->assertSee('href="/lumi"', false)
            ->assertSee('Open Lumi');
    }

    public function test_suggestions_endpoint_replaces_chat_and_marks_suggestions_seen_on_lumi_page(): void
    {
        $user = $this->makeCustomer();

        BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8,
            'close_hour' => 22,
            'step_minutes' => 60,
            'min_duration_hours' => 1,
            'max_duration_hours' => 8,
            'closed_weekdays' => [],
        ]);
        Court::create([
            'name' => 'Lumi Page Court',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 500,
            'status' => 'active',
        ]);
        config(['lumi.cache_seconds' => 0]);

        $this->signIn($user)->getJson('/lumi/suggestions')
            ->assertOk()
            ->assertJsonStructure(['suggestions', 'has_unseen'])
            ->assertJsonPath('has_unseen', true);

        $this->get('/lumi')->assertOk();
        $this->getJson('/lumi/suggestions')->assertOk()->assertJsonPath('has_unseen', false);
        $this->postJson('/lumi/message', ['message' => 'How do I book a court?'])->assertNotFound();
    }

    public function test_dashboard_suggestion_card_is_rendered_once_per_session(): void
    {
        $user = $this->makeCustomer();
        $this->signIn($user)->get('/my-dashboard')
            ->assertOk()
            ->assertSee('data-lumi-login-card', false)
            ->assertSee('data-show-once="true"', false);

        $this->get('/my-dashboard')->assertOk()->assertDontSee('data-lumi-login-card', false);
    }

    public function test_customer_can_dismiss_a_visible_suggestion_for_the_cooldown(): void
    {
        $user = $this->makeCustomer();
        BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8,
            'close_hour' => 22,
            'step_minutes' => 60,
            'min_duration_hours' => 1,
            'max_duration_hours' => 8,
            'closed_weekdays' => [],
        ]);
        Court::create([
            'name' => 'Lumi Dismiss Court',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 500,
            'status' => 'active',
        ]);
        config(['lumi.cache_seconds' => 0]);

        $this->signIn($user);
        $suggestion = $this->getJson('/lumi/suggestions')->assertOk()->json('suggestions.0');
        $this->assertIsArray($suggestion);

        $this->postJson('/lumi/dismiss', ['key' => $suggestion['key']])
            ->assertOk()
            ->assertJsonPath('dismissed', true);
        $this->assertDatabaseHas('lumi_suggestion_dismissals', [
            'user_id' => $user->user_id,
            'suggestion_key' => $suggestion['key'],
        ]);
        $this->getJson('/lumi/suggestions')->assertOk()->assertJsonCount(0, 'suggestions');
    }

    public function test_lumi_and_whats_new_require_customer_authentication(): void
    {
        $this->get('/lumi')->assertRedirect(route('login'));
        $this->get('/whats-new')->assertRedirect(route('login'));

        $admin = User::create([
            'username' => 'lumi-page-admin',
            'name' => 'Lumi Page Admin',
            'email' => 'lumi-page-admin@example.test',
            'password' => 'password',
            'role' => 'admin',
            'access_version' => 1,
        ]);

        $this->actingAs($admin)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $admin->user_id,
            'access_version_user_id' => $admin->user_id,
            'access_version' => (int) $admin->access_version,
        ])->get('/lumi')->assertForbidden();
    }

    public function test_lumi_icon_uses_current_color_and_standalone_asset_uses_fixed_color(): void
    {
        $this->signIn($this->makeCustomer())->get('/lumi')->assertOk()
            ->assertSee('stroke="currentColor"', false)
            ->assertSee('fill="currentColor"', false);

        $asset = file_get_contents(public_path('assets/lumi-icon.svg'));
        $this->assertIsString($asset);
        $this->assertStringContainsString('stroke="#111"', $asset);
        $this->assertStringNotContainsString('currentColor', $asset);
    }

    private function makeCustomer(): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => "lumi-customer-{$sequence}",
            'name' => 'Lumi Customer',
            'email' => "lumi-customer-{$sequence}@example.test",
            'password' => 'password',
            'role' => 'user',
            'access_version' => 1,
        ]);
    }

    private function signIn(User $user): self
    {
        return $this->actingAs($user)->withSession([
            'access_version_user_id' => $user->user_id,
            'access_version' => (int) $user->access_version,
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $user->user_id,
        ]);
    }
}
