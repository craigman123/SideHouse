<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\UsesMultiGuardEnvironment;
use Tests\TestCase;

class AdminCardIconRenderingTest extends TestCase
{
    use RefreshDatabase;
    use UsesMultiGuardEnvironment;

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

    public function test_admin_reports_widgets_and_system_monitor_render_their_metric_icons(): void
    {
        $admin = User::create([
            'username' => 'admin-card-icons',
            'name' => 'Admin Card Icons',
            'email' => 'admin-card-icons@example.test',
            'password' => 'password',
            'role' => 'admin',
            'access_version' => 1,
        ]);

        $reports = $this->signInAs($admin)->get('/reports')->assertOk();
        foreach ([
            'Users Online' => 'people',
            'Requests Today' => 'bar-chart',
            'Requests / Hour' => 'trending-up',
            'Unique Visitors Today' => 'people',
            'Database' => 'database',
            'Server' => 'gauge',
        ] as $label => $icon) {
            $this->assertLabelHasIcon($reports, $label, $icon);
        }
        $this->assertMatchesRegularExpression(
            '/<div class="report-panel-title[^"]*">\s*<h2 id="trafficPanelTitle">Traffic \(Last 24 Hours\)<\/h2>\s*<span\b(?=[^>]*data-icon="bar-chart")[^>]*>/',
            $reports->getContent()
        );

        $widgets = $this->signInAs($admin)->get('/admin/widgets')->assertOk();
        foreach ($this->monitorMetrics() as $label => $icon) {
            $this->assertLabelHasIcon($widgets, $label, $icon);
        }

        $dashboard = $this->signInAs($admin)->get('/dashboard')->assertOk();
        foreach ($this->monitorMetrics() as $label => $icon) {
            $this->assertLabelHasIcon($dashboard, $label, $icon);
        }
    }

    private function monitorMetrics(): array
    {
        return [
            'DB connections' => 'database',
            'Queries / min' => 'bar-chart',
            'Avg query' => 'clock',
            'PHP memory' => 'gauge',
            'Server load' => 'gauge',
            'Avg response' => 'clock',
            'Requests / min' => 'trending-up',
            'Errors / 5 min' => 'alert',
        ];
    }

    private function assertLabelHasIcon(TestResponse $response, string $label, string $icon): void
    {
        $pattern = '/'.preg_quote($label, '/').'\s*<span\b(?=[^>]*data-icon="'.preg_quote($icon, '/').'")[^>]*>/';
        $this->assertMatchesRegularExpression($pattern, $response->getContent(), $label.' should render its '.$icon.' icon.');
    }

    private function signInAs(User $user): self
    {
        return $this->actingAs($user, 'admin')->withSession([
            'access_version_user_id_admin' => $user->user_id,
            'access_version_admin' => $user->access_version,
            'mfa_passed_at_admin' => now()->timestamp,
            'mfa_passed_user_id_admin' => $user->user_id,
        ]);
    }
}
