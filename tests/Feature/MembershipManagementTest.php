<?php

namespace Tests\Feature;

use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MembershipManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'username' => "membership-{$role}-{$sequence}",
            'name' => ucfirst($role).' Membership User',
            'email' => "membership-{$role}-{$sequence}@example.test",
            'password' => 'password',
            'role' => $role,
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

    private function validPlan(string $title = 'Weekend Membership'): array
    {
        return [
            'title' => $title,
            'description' => 'A membership plan for weekend play.',
            'price' => '1250.00',
            'discount_type' => 'percentage',
            'discount_value' => '15',
            'players' => '4',
            'expiry_date' => now()->addMonth()->toDateString(),
        ];
    }

    private function assertBlocked(TestResponse $response): void
    {
        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }

    public function test_admin_and_owner_can_view_and_create_membership_plans(): void
    {
        $admin = $this->user('admin');
        $adminPage = $this->signIn($admin)
            ->get(route('admin.memberships.index'))
            ->assertOk()
            ->assertSee('Memberships')
            ->assertSee('Add membership')
            ->assertSee('membershipModal')
            ->assertSee('role="dialog" aria-modal="true"', false)
            ->assertSee(route('admin.memberships.index'));
        $adminPage->assertSee('href="'.route('admin.memberships.index').'"', false);

        $this->post(route('admin.memberships.store'), $this->validPlan('Admin Plan'))
            ->assertRedirect(route('admin.memberships.index'))
            ->assertSessionHas('success', 'Membership added.');

        $adminPlan = MembershipPlan::query()->where('name', 'Admin Plan')->firstOrFail();
        $this->assertSame('1250.00', $adminPlan->price);
        $this->assertSame('percentage', $adminPlan->discount_type);
        $this->assertSame('15.00', $adminPlan->discount_value);
        $this->assertSame(0, $adminPlan->discount_percent);
        $this->assertSame(4, $adminPlan->players);

        $owner = $this->user('owner');
        $ownerPage = $this->signIn($owner)
            ->get(route('owner.memberships.index'))
            ->assertOk()
            ->assertSee('Memberships')
            ->assertSee('Add membership')
            ->assertSee('href="'.route('owner.memberships.index').'"', false);

        $this->post(route('owner.memberships.store'), $this->validPlan('Owner Plan'))
            ->assertRedirect(route('owner.memberships.index'))
            ->assertSessionHas('success', 'Membership added.');

        $ownerPlan = MembershipPlan::query()->where('name', 'Owner Plan')->firstOrFail();
        $ownerPage->assertSee('Admin Plan');
        $this->assertSame('Owner Plan', $ownerPlan->name);
    }

    public function test_staff_and_customers_cannot_view_or_create_membership_plans(): void
    {
        foreach (['staff', 'user'] as $role) {
            $user = $this->user($role);
            $this->assertBlocked($this->signIn($user)->get(route('admin.memberships.index')));
            $this->assertBlocked($this->get(route('owner.memberships.index')));
            $this->assertBlocked($this->post(route('admin.memberships.store'), $this->validPlan()));
            $this->assertBlocked($this->post(route('owner.memberships.store'), $this->validPlan()));
        }

        $staffPage = $this->signIn($this->user('staff'))->get('/staff/profile')->assertOk();
        $staffPage->assertDontSee('href="'.route('admin.memberships.index').'"', false)
            ->assertDontSee('href="'.route('owner.memberships.index').'"', false);

        $customerPage = $this->signIn($this->user('user'))->get('/profile')->assertOk();
        $customerPage->assertDontSee('href="'.route('admin.memberships.index').'"', false)
            ->assertDontSee('href="'.route('owner.memberships.index').'"', false);
    }

    public function test_membership_validation_rejects_invalid_plan_fields(): void
    {
        $invalidPlans = [
            [['discount_type' => 'percentage', 'discount_value' => '101'], ['discount_value']],
            [['expiry_date' => now()->subDay()->toDateString()], ['expiry_date']],
            [['players' => 'four'], ['players']],
            [['title' => ''], ['title']],
            [['price' => ''], ['price']],
            [['discount_type' => 'fixed', 'discount_value' => '0'], ['discount_value']],
        ];

        foreach ($invalidPlans as [$overrides, $expectedErrors]) {
            $admin = $this->user('admin');
            $response = $this->signIn($admin)
                ->from(route('admin.memberships.index'))
                ->post(route('admin.memberships.store'), array_merge($this->validPlan(), $overrides));

            $response->assertRedirect(route('admin.memberships.index'));
            foreach ($expectedErrors as $field) {
                $response->assertSessionHasErrors($field);
            }
        }

        $this->assertSame(0, MembershipPlan::query()->count());
    }

    public function test_membership_store_is_throttled_to_five_requests_per_minute(): void
    {
        $admin = $this->user('admin');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = $attempt === 0
                ? $this->signIn($admin)->post(route('admin.memberships.store'), $this->validPlan("Plan {$attempt}"))
                : $this->post(route('admin.memberships.store'), $this->validPlan("Plan {$attempt}"));
            $response->assertRedirect(route('admin.memberships.index'));
        }

        $this->post(route('admin.memberships.store'), $this->validPlan('Plan six'))
            ->assertTooManyRequests();
        $this->assertSame(5, MembershipPlan::query()->count());
    }
}
