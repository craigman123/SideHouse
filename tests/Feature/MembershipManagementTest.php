<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Carbon\Carbon;
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
        $this->assertContains($response->getStatusCode(), [302, 401, 403, 404]);
    }

    private function assertMembershipCardExpiryStatus(
        TestResponse $response,
        string $title,
        string $status
    ): void {
        $content = $response->getContent();
        $titlePosition = strpos($content, '<h2 class="membership-card-title">'.e($title).'</h2>');
        $this->assertNotFalse($titlePosition, "Membership card for {$title} was not rendered.");

        $cardStart = strrpos(substr($content, 0, $titlePosition), '<article class="membership-card">');
        $cardEnd = strpos($content, '</article>', $titlePosition);
        $this->assertNotFalse($cardStart);
        $this->assertNotFalse($cardEnd);
        $card = substr($content, $cardStart, $cardEnd - $cardStart);
        $labels = [
            'active' => 'Active',
            'expiring-soon' => 'Expiring soon',
            'expired' => 'Expired',
        ];
        $label = $labels[$status];

        $this->assertStringContainsString('class="membership-card-icon"', $card);
        $this->assertStringContainsString(
            'membership-card-status membership-card-status--'.$status,
            $card
        );
        $this->assertStringContainsString('aria-label="'.$label.'"', $card);
        $this->assertStringContainsString('title="'.$label.'"', $card);

        if ($status === 'expired') {
            $this->assertStringContainsString('class="membership-card-expired-overlay"', $card);
            $this->assertStringContainsString('aria-label="EXPIRED"', $card);
            $this->assertStringContainsString('>EXPIRED</span>', $card);
        }
    }

    private function assertMembershipModalIsClosedAndAssetsAreIncluded(
        TestResponse $response,
        string $baseStylesheet
    ): void {
        $response
            ->assertSee(asset($baseStylesheet).'?v='.filemtime(public_path($baseStylesheet)), false)
            ->assertSee(
                asset('css/admin/memberships.css').'?v='.filemtime(public_path('css/admin/memberships.css')),
                false
            )
            ->assertSee(
                asset('js/admin/memberships.js').'?v='.filemtime(public_path('js/admin/memberships.js')),
                false
            )
            ->assertSee('id="membershipSummaryModal"', false)
            ->assertSee('id="membershipSummaryQr"', false)
            ->assertDontSee('<table', false)
            ->assertSee('Summary')
            ->assertSee('id="membershipSummaryExpiry">No expiry</dd>', false)
            ->assertSee('Date created')
            ->assertDontSee('<h2 id="membershipsHeading">Memberships</h2>', false);

        $this->assertMatchesRegularExpression(
            '/<div\b(?=[^>]*\bid="membershipModal")(?=[^>]*\baria-hidden="true")(?=[^>]*\shidden(?:\s|>))[^>]*>/',
            $response->getContent()
        );
        $this->assertMatchesRegularExpression(
            '/<div\b(?=[^>]*\bid="membershipSummaryModal")(?=[^>]*\baria-hidden="true")(?=[^>]*\shidden(?:\s|>))[^>]*>/',
            $response->getContent()
        );
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
        $this->assertMembershipModalIsClosedAndAssetsAreIncluded(
            $adminPage,
            'css/shared/dashboard-base.css'
        );

        $this->post(route('admin.memberships.store'), $this->validPlan('Admin Plan'))
            ->assertRedirect(route('admin.memberships.index'))
            ->assertSessionHas('success', 'Membership added.');

        $adminPlan = MembershipPlan::query()->where('name', 'Admin Plan')->firstOrFail();
        $this->assertSame('1250.00', $adminPlan->price);
        $this->assertSame('percentage', $adminPlan->discount_type);
        $this->assertSame('15.00', $adminPlan->discount_value);
        $this->assertSame(0, $adminPlan->discount_percent);
        $this->assertSame(4, $adminPlan->players);

        $adminCards = $this->get(route('admin.memberships.index'))
            ->assertOk()
            ->assertSee('class="membership-card"', false)
            ->assertSee('class="membership-card-qr"', false)
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee('Admin Plan')
            ->assertSee('₱1,250.00')
            ->assertSee('15.00%')
            ->assertSee('Players')
            ->assertSee($adminPlan->expiry_date->format('M j, Y'))
            ->assertSee('No members yet');
        $adminCards->assertDontSee('<table', false);

        $owner = $this->user('owner');
        $ownerPage = $this->signIn($owner)
            ->get(route('owner.memberships.index'))
            ->assertOk()
            ->assertSee('Memberships')
            ->assertSee('Add membership')
            ->assertSee('href="'.route('owner.memberships.index').'"', false);
        $this->assertMembershipModalIsClosedAndAssetsAreIncluded(
            $ownerPage,
            'css/owner/owner-base.css'
        );

        $this->post(route('owner.memberships.store'), $this->validPlan('Owner Plan'))
            ->assertRedirect(route('owner.memberships.index'))
            ->assertSessionHas('success', 'Membership added.');

        $ownerPlan = MembershipPlan::query()->where('name', 'Owner Plan')->firstOrFail();
        $ownerPage->assertSee('Admin Plan');
        $this->assertSame('Owner Plan', $ownerPlan->name);

        $this->get(route('owner.memberships.index'))
            ->assertOk()
            ->assertSee('class="membership-card"', false)
            ->assertSee('class="membership-card-qr"', false)
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee('Owner Plan')
            ->assertSee('₱1,250.00')
            ->assertSee('15.00%')
            ->assertSee('Players')
            ->assertSee($ownerPlan->expiry_date->format('M j, Y'))
            ->assertSee('No members yet')
            ->assertDontSee('<table', false);
    }

    public function test_staff_and_customers_cannot_view_or_create_membership_plans(): void
    {
        foreach (['staff', 'user'] as $role) {
            $user = $this->user($role);
            $this->assertBlocked($this->signIn($user)->get(route('admin.memberships.index')));
            $this->assertBlocked($this->get(route('owner.memberships.index')));
            $this->assertBlocked($this->post(route('admin.memberships.store'), $this->validPlan()));
            $this->assertBlocked($this->post(route('owner.memberships.store'), $this->validPlan()));
            $this->assertBlocked($this->postJson(route('admin.memberships.summary'), $this->validPlan()));
            $this->assertBlocked($this->postJson(route('owner.memberships.summary'), $this->validPlan()));
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

            $errorPage = $this->get(route('admin.memberships.index'))->assertOk();
            $errorPage
                ->assertSee('class="modal-overlay memberships-modal open"', false)
                ->assertSee('aria-hidden="false"', false)
                ->assertSee('role="alert"', false);
        }

        $this->assertSame(0, MembershipPlan::query()->count());
    }

    public function test_membership_expiry_is_optional_and_null_is_shown_in_the_table(): void
    {
        $plan = $this->validPlan('No Expiry Plan');
        unset($plan['expiry_date']);

        $this->signIn($this->user('admin'))
            ->post(route('admin.memberships.store'), $plan)
            ->assertRedirect(route('admin.memberships.index'))
            ->assertSessionHas('success', 'Membership added.');

        $membership = MembershipPlan::query()->where('name', 'No Expiry Plan')->firstOrFail();
        $this->assertNull($membership->expiry_date);

        $this->get(route('admin.memberships.index'))
            ->assertOk()
            ->assertSee('class="membership-card"', false)
            ->assertSee('No expiry')
            ->assertSee('No members yet');
    }

    public function test_membership_cards_show_expiry_statuses_in_the_app_timezone(): void
    {
        $timezone = 'Asia/Manila';
        config(['app.timezone' => $timezone]);
        $this->travelTo(Carbon::parse('2026-11-27 00:30:00', $timezone));
        $today = now($timezone)->startOfDay();
        $plans = [
            ['No expiry status', null, 'active'],
            ['Far future status', $today->copy()->addYear()->toDateString(), 'active'],
            ['Seven days status', $today->copy()->addDays(7)->toDateString(), 'expiring-soon'],
            ['One day status', $today->copy()->addDay()->toDateString(), 'expiring-soon'],
            ['Today status', $today->toDateString(), 'expiring-soon'],
            ['Past status', $today->copy()->subDay()->toDateString(), 'expired'],
        ];

        foreach ($plans as [$title, $expiryDate]) {
            MembershipPlan::create([
                'name' => $title,
                'description' => 'Expiry status test plan.',
                'price' => '1250.00',
                'discount_type' => 'percentage',
                'discount_value' => '15.00',
                'players' => 4,
                'expiry_date' => $expiryDate,
            ]);
        }

        $response = $this->signIn($this->user('admin'))
            ->get(route('admin.memberships.index'))
            ->assertOk();

        foreach ($plans as [$title, $expiryDate, $status]) {
            $this->assertMembershipCardExpiryStatus($response, $title, $status);
        }
    }

    public function test_admin_and_owner_can_prepare_a_receipt_summary_without_saving(): void
    {
        foreach ([
            ['admin', 'admin.memberships.summary', 'admin.memberships.store', 'admin.memberships.index'],
            ['owner', 'owner.memberships.summary', 'owner.memberships.store', 'owner.memberships.index'],
        ] as [$role, $summaryRoute, $storeRoute, $indexRoute]) {
            $plan = $this->validPlan(ucfirst($role).' Receipt Plan');
            unset($plan['expiry_date']);
            $existingPlanCount = MembershipPlan::query()->count();

            $response = $this->signIn($this->user($role))
                ->postJson(route($summaryRoute), $plan)
                ->assertOk()
                ->assertJsonPath('membership.title', ucfirst($role).' Receipt Plan')
                ->assertJsonPath('membership.description', $plan['description'])
                ->assertJsonPath('membership.price', '1250.00')
                ->assertJsonPath('membership.discount_type', 'percentage')
                ->assertJsonPath('membership.discount_value', '15.00')
                ->assertJsonPath('membership.players', 4)
                ->assertJsonPath('membership.expiry_date', null)
                ->assertJsonPath('membership.created_at', now()->format('M j, Y'));

            $this->assertStringContainsString('<svg', $response->json('qr_svg'));
            $this->assertSame($existingPlanCount, MembershipPlan::query()->count());

            $this->post(route($storeRoute), $plan)
                ->assertRedirect(route($indexRoute));
            $cardResponse = $this->get(route($indexRoute))->assertOk();
            $this->assertSame(1, preg_match(
                '/<img\s+src="data:image\/svg\+xml;base64,([^"]+)"/',
                $cardResponse->getContent(),
                $matches
            ));
            $this->assertSame($response->json('qr_svg'), base64_decode($matches[1], true));
        }
    }

    public function test_membership_cards_show_current_members_with_a_capped_name_list(): void
    {
        $plan = $this->validPlan('Members Plan');
        unset($plan['expiry_date']);
        $this->signIn($this->user('owner'))
            ->post(route('owner.memberships.store'), $plan)
            ->assertRedirect(route('owner.memberships.index'));
        $membershipPlan = MembershipPlan::query()->where('name', 'Members Plan')->firstOrFail();

        for ($index = 1; $index <= 6; $index++) {
            $member = $this->user('user');
            $member->update([
                'name' => "Holder {$index}",
                'email' => "holder{$index}@example.test",
                'phone_number' => "555000{$index}",
            ]);

            Membership::create([
                'user_id' => $member->user_id,
                'membership_plan_id' => $membershipPlan->id,
                'start_date' => today()->subDays($index)->toDateString(),
                'expiry_date' => today()->addMonth()->toDateString(),
                'status' => 'active',
            ]);
        }

        $expiredMember = $this->user('user');
        $expiredMember->update(['name' => 'Expired Holder']);
        Membership::create([
            'user_id' => $expiredMember->user_id,
            'membership_plan_id' => $membershipPlan->id,
            'start_date' => today()->subMonths(2)->toDateString(),
            'expiry_date' => today()->subDay()->toDateString(),
            'status' => 'expired',
        ]);

        $page = $this->get(route('owner.memberships.index'))
            ->assertOk()
            ->assertSee('Members')
            ->assertSee('(6)')
            ->assertSee('Holder 1')
            ->assertSee('Holder 2')
            ->assertSee('Holder 3')
            ->assertSee('Holder 4')
            ->assertSee('+2 more')
            ->assertDontSee('Holder 5')
            ->assertDontSee('Holder 6')
            ->assertDontSee('Expired Holder')
            ->assertDontSee($member->email);

        $page->assertDontSee('phone_number');

        $this->signIn($this->user('admin'))
            ->get(route('admin.memberships.index'))
            ->assertOk()
            ->assertSee('Holder 1')
            ->assertSee('(6)')
            ->assertSee('+2 more')
            ->assertDontSee('Holder 5')
            ->assertDontSee($member->email);
    }

    public function test_membership_summary_validates_before_generating_a_receipt(): void
    {
        $plan = $this->validPlan();
        $plan['discount_value'] = '101';

        $this->signIn($this->user('admin'))
            ->postJson(route('admin.memberships.summary'), $plan)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('discount_value');

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
