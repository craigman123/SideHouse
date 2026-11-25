<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class StaffPermissionLockdownTest extends TestCase
{
    use RefreshDatabase;

    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        BusinessSetting::query()->firstOrCreate([], [
            'open_hour' => 8, 'close_hour' => 7, 'step_minutes' => 30,
            'min_duration_hours' => 1, 'max_duration_hours' => 10, 'closed_weekdays' => [],
        ]);
        $this->court = Court::create([
            'name' => 'Staff Permission Court', 'width' => 10, 'length' => 20,
            'hourly_rate' => 500, 'status' => 'active',
        ]);
    }

    public function test_staff_can_only_create_edit_and_delete_open_play_blocks(): void
    {
        $staff = $this->user('staff', ['calendar_events.open_play']);
        $this->signIn($staff);

        $payload = $this->blockPayload('open_play');
        $created = $this->postJson(role_route('admin.blocks.store'), $payload)->assertCreated();
        $blockId = $created->json('block.id');
        $this->patchJson(role_route('admin.blocks.update', ['block' => $blockId]), ['notes' => 'Staff update'])->assertOk();
        $this->deleteJson(role_route('admin.blocks.destroy', ['block' => $blockId]))->assertOk();

        foreach (['closed', 'maintenance'] as $type) {
            $this->postJson(role_route('admin.blocks.store'), $this->blockPayload($type))->assertForbidden();
            $existing = CourtBlock::create([
                'court_id' => $this->court->id, 'type' => $type, 'title' => "Existing {$type}",
                'block_date' => '2030-05-15', 'start_time' => '13:00', 'end_time' => '14:00',
                'created_by' => $staff->user_id,
            ]);
            $this->patchJson(role_route('admin.blocks.update', ['block' => $existing->id]), ['notes' => 'Not permitted'])->assertForbidden();
            $this->deleteJson(role_route('admin.blocks.destroy', ['block' => $existing->id]))->assertForbidden();
        }

        $openPlay = CourtBlock::create([
            'court_id' => $this->court->id, 'type' => 'open_play', 'title' => 'Convert attempt',
            'block_date' => '2030-05-15', 'start_time' => '15:00', 'end_time' => '16:00',
            'max_players' => 8, 'price_per_player' => 100, 'created_by' => $staff->user_id,
        ]);
        $this->patchJson(role_route('admin.blocks.update', ['block' => $openPlay->id]), ['type' => 'closed'])->assertForbidden();
        $this->patchJson(role_route('admin.blocks.update', ['block' => $openPlay->id]), ['type' => 'maintenance'])->assertForbidden();

        if (config('auth.multi_guard')) {
            $this->postJson('/staff/configuration/closures', [])->assertNotFound();
            $this->postJson('/staff/specific-date-closure', [])->assertNotFound();
        } else {
            $this->postJson('/admin/configuration/closures', [])->assertForbidden();
            $this->postJson('/configuration/specific-date-closure', [])->assertForbidden();
        }
    }

    public function test_legacy_staff_walk_in_and_calendar_permissions_are_fixed_denied(): void
    {
        $staff = $this->user('staff', ['bookings.create', 'calendar_events.manage', 'calendar_events.open_play']);
        $this->signIn($staff);

        if (config('auth.multi_guard')) {
            $this->postJson('/staff/bookings', [])->assertMethodNotAllowed();
        } else {
            $this->postJson('/admin/bookings', [])->assertForbidden();
        }
        $this->postJson(role_route('admin.blocks.store'), $this->blockPayload('closed'))->assertForbidden();
        $this->postJson(role_route('admin.blocks.store'), $this->blockPayload('maintenance'))->assertForbidden();
        $this->assertFalse(app(\App\Services\UserAccessService::class)->allows($staff, 'bookings.create'));
        $this->assertFalse(app(\App\Services\UserAccessService::class)->allows($staff, 'calendar_events.manage'));
    }

    public function test_admin_and_owner_retain_all_calendar_block_types(): void
    {
        foreach (['admin', 'owner'] as $role) {
            $actor = $this->user($role, null);
            $this->signIn($actor);
            foreach (['open_play', 'closed', 'maintenance'] as $index => $type) {
                $payload = $this->blockPayload($type, 9 + $index * 2);
                if ($type === 'open_play') {
                    $payload['max_players'] = 8;
                    $payload['price_per_player'] = 100;
                }
                $created = $this->postJson(role_route('admin.blocks.store'), $payload)->assertCreated();
                $id = $created->json('block.id');
                $this->patchJson(role_route('admin.blocks.update', ['block' => $id]), ['notes' => "{$role} update"])->assertOk();
                $this->deleteJson(role_route('admin.blocks.destroy', ['block' => $id]))->assertOk();
            }
        }
    }

    public function test_admin_and_owner_retain_walk_in_creation(): void
    {
        foreach (['admin', 'owner'] as $index => $role) {
            $actor = $this->user($role, null);
            $this->signIn($actor);
            $hour = 17 + $index;
            $this->postJson(role_route('admin.bookings.walk-in.store'), [
                'customer_name' => "{$role} Walk-in",
                'contact_number' => '09171234567',
                'court_id' => $this->court->id,
                'date' => '2030-05-15',
                'start_time' => sprintf('%02d:00', $hour),
                'end_time' => sprintf('%02d:00', $hour + 1),
                'payment_method' => 'cash',
                'amount' => 1000,
            ])->assertCreated();
        }
    }

    public function test_permission_migration_converts_legacy_staff_permissions_idempotently(): void
    {
        $this->assertNotContains('bookings.create', config('user_access.staff_permissions'));
        $this->assertNotContains('calendar_events.manage', config('user_access.staff_permissions'));
        $this->assertContains('calendar_events.open_play', config('user_access.staff_permissions'));

        $staff = $this->user('staff', ['bookings.create', 'bookings.view', 'calendar_events.manage', 'customers.view']);
        $migration = require database_path('migrations/2026_11_23_000001_normalize_staff_permissions_for_open_play.php');

        $migration->up();
        $expected = ['bookings.view', 'customers.view', 'calendar_events.open_play'];
        $this->assertEqualsCanonicalizing($expected, $staff->fresh()->permissions);

        $migration->up();
        $this->assertEqualsCanonicalizing($expected, $staff->fresh()->permissions);
    }

    private function user(string $role, ?array $permissions): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => "staff-lock-{$role}-{$sequence}",
            'name' => ucfirst($role).' Lockdown',
            'email' => "staff-lock-{$role}-{$sequence}@example.test",
            'password' => 'current-password-123',
            'role' => $role,
            'permissions' => $permissions,
        ]);
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

    private function blockPayload(string $type, int $hour = 9): array
    {
        $payload = [
            'court_id' => $this->court->id, 'type' => $type, 'title' => "Test {$type}",
            'block_date' => '2030-05-15', 'start_time' => sprintf('%02d:00', $hour),
            'end_time' => sprintf('%02d:00', $hour + 1),
        ];
        if ($type === 'open_play') {
            $payload['max_players'] = 8;
            $payload['price_per_player'] = 100;
        }

        return $payload;
    }
}
