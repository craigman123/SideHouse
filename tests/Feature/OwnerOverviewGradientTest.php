<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerOverviewGradientTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_overview_panels_render_the_gradient_border_class(): void
    {
        $owner = User::create([
            'username' => 'overview-owner',
            'name' => 'Overview Owner',
            'email' => 'overview-owner@example.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        $response = $this->actingAs($owner)
            ->withSession([
                'access_version_user_id' => $owner->user_id,
                'access_version' => 1,
                'mfa_passed_at' => now()->timestamp,
                'mfa_passed_user_id' => $owner->user_id,
            ])
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('Total Income')
            ->assertSee("Today's Income", false)
            ->assertSee('Total Bookings')
            ->assertSee("Today's Bookings", false)
            ->assertSee('Upcoming Bookings');

        $this->assertSame(
            5,
            substr_count($response->getContent(), 'overview-gradient-border')
        );
        $this->assertStringContainsString(
            asset('css/shared/overview-border.css').'?v='.filemtime(public_path('css/shared/overview-border.css')),
            $response->getContent()
        );
    }
}
