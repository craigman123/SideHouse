<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMembershipPlanRequest;
use App\Models\MembershipPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MembershipPlanController extends Controller
{
    public function adminIndex(): View
    {
        return $this->index(
            'layouts.app',
            'admin.memberships.index',
            'admin.memberships.store',
            'css/shared/dashboard-base.css'
        );
    }

    public function ownerIndex(): View
    {
        return $this->index(
            'owner.layouts.app',
            'owner.memberships.index',
            'owner.memberships.store',
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

    private function index(
        string $layout,
        string $indexRoute,
        string $storeRoute,
        string $membershipModalStylesheet
    ): View
    {
        return view('admin.memberships.index', [
            'membershipLayout' => $layout,
            'membershipIndexRoute' => $indexRoute,
            'membershipStoreRoute' => $storeRoute,
            'membershipModalStylesheet' => $membershipModalStylesheet,
            'membershipPlans' => MembershipPlan::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        ]);
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
            'expiry_date' => $validated['expiry_date'],
        ]);

        return redirect()
            ->route($indexRoute)
            ->with('success', 'Membership added.');
    }
}
