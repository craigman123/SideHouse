<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookingsCalendarDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookings_page_embeds_blocks_and_configured_step_size(): void
    {
        $admin = User::create([
            'username' => 'calendar-admin',
            'name' => 'Calendar Admin',
            'email' => 'calendar-admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $court = Court::create([
            'name' => 'Court A',
            'width' => 10,
            'length' => 20,
            'hourly_rate' => 500,
            'status' => 'active',
        ]);

        $settings = BusinessSetting::query()->firstOrFail();
        $settings->update(['step_minutes' => 15]);

        $block = CourtBlock::create([
            'court_id' => $court->id,
            'type' => 'maintenance',
            'title' => 'Court inspection',
            'notes' => 'Surface check',
            'block_date' => today()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'created_by' => $admin->user_id,
        ]);

        $response = $this->actingAs($admin)->withSession([
            'mfa_passed_at' => now()->timestamp,
            'mfa_passed_user_id' => $admin->user_id,
            'access_version_user_id' => $admin->user_id,
            'access_version' => 1,
        ])->get('/bookings')->assertOk();

        preg_match('/<script id="courtBoardData" type="application\/json">(.*?)<\/script>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'The embedded calendar data script was not rendered.');
        $data = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('blocks', $data);
        $this->assertSame(15, $data['businessSettings']['step_minutes']);
        $this->assertSame(now('Asia/Manila')->toDateString(), $data['businessSettings']['today']);
        $this->assertStringContainsString('+08:00', $data['businessSettings']['server_time']);
        $this->assertSame($block->id, $data['blocks'][0]['id']);
        $this->assertSame('maintenance', $data['blocks'][0]['type']);
        $this->assertSame('09:00', $data['blocks'][0]['start_time']);
    }
}
