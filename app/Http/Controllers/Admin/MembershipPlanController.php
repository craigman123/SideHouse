<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMembershipPlanRequest;
use App\Models\MembershipPlan;
use App\Support\QrCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class MembershipPlanController extends Controller
{
    public function adminIndex(): View
    {
        return $this->index(
            'layouts.app',
            'admin.memberships.index',
            'admin.memberships.store',
            'admin.memberships.summary',
            'css/shared/dashboard-base.css'
        );
    }

    public function ownerIndex(): View
    {
        return $this->index(
            'owner.layouts.app',
            'owner.memberships.index',
            'owner.memberships.store',
            'owner.memberships.summary',
            'css/owner/owner-base.css'
        );
    }

    public function adminStore(StoreMembershipPlanRequest $request): RedirectResponse
    {
        return $this->store($request, 'admin.memberships.index');
    }

    public function ownerStore(StoreMembershipPlanRequest $request): RedirectResponse
    {
        return $this->store($request, 'owner.memberships.index');
    }

    public function adminSummary(StoreMembershipPlanRequest $request): JsonResponse
    {
        return $this->summary($request);
    }

    public function ownerSummary(StoreMembershipPlanRequest $request): JsonResponse
    {
        return $this->summary($request);
    }

    private function index(
        string $layout,
        string $indexRoute,
        string $storeRoute,
        string $summaryRoute,
        string $membershipModalStylesheet
    ): View
    {
        return view('admin.memberships.index', [
            'membershipLayout' => $layout,
            'membershipIndexRoute' => $indexRoute,
            'membershipStoreRoute' => $storeRoute,
            'membershipSummaryRoute' => $summaryRoute,
            'membershipModalStylesheet' => $membershipModalStylesheet,
            'membershipPlans' => $this->membershipPlans(),
        ]);
    }

    private function membershipPlans(): Collection
    {
        $today = today()->toDateString();

        return MembershipPlan::query()
            ->withCount(['memberships as current_members_count' => function ($query) use ($today) {
                $query->where('status', 'active')
                    ->where('expiry_date', '>=', $today)
                    ->whereHas('user');
            }])
            ->with(['memberships' => function ($query) use ($today) {
                $query->select(['id', 'membership_plan_id', 'user_id', 'start_date', 'expiry_date', 'status'])
                    ->where('status', 'active')
                    ->where('expiry_date', '>=', $today)
                    ->whereHas('user')
                    ->with('user:user_id,name')
                    ->orderByDesc('start_date')
                    ->orderByDesc('id')
                    ->limit(4);
            }])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (MembershipPlan $membershipPlan) {
                $membershipPlan->setAttribute('qr_svg', QrCodeGenerator::svg($this->qrPayload([
                    'title' => $membershipPlan->name,
                    'description' => $membershipPlan->description ?? '',
                    'price' => $membershipPlan->price,
                    'discount_type' => $membershipPlan->discount_type,
                    'discount_value' => $membershipPlan->discount_value,
                    'players' => $membershipPlan->players,
                    'expiry_date' => $membershipPlan->expiry_date?->toDateString(),
                ]), 256));

                return $membershipPlan;
            });
    }

    private function summary(StoreMembershipPlanRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $expiryDate = $validated['expiry_date'] ?? null;
        $membership = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'price' => number_format((float) $validated['price'], 2, '.', ''),
            'discount_type' => $validated['discount_type'],
            'discount_value' => number_format((float) $validated['discount_value'], 2, '.', ''),
            'players' => (int) $validated['players'],
            'expiry_date' => $expiryDate,
            'created_at' => now()->format('M j, Y'),
        ];

        return response()->json([
            'membership' => $membership,
            'qr_svg' => QrCodeGenerator::svg($this->qrPayload($membership), 256),
        ]);
    }

    private function qrPayload(array $membership): string
    {
        return json_encode([
            'title' => $membership['title'],
            'description' => $membership['description'],
            'price' => $membership['price'],
            'discount_type' => $membership['discount_type'],
            'discount_value' => $membership['discount_value'],
            'players' => $membership['players'],
            'expiry_date' => $membership['expiry_date'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function store(StoreMembershipPlanRequest $request, string $indexRoute): RedirectResponse
    {
        $validated = $request->validated();

        MembershipPlan::create([
            'name' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_value'],
            'players' => $validated['players'],
            'expiry_date' => $validated['expiry_date'] ?? null,
        ]);

        return redirect()
            ->route($indexRoute)
            ->with('success', 'Membership added.');
    }
}
