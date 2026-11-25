<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\LumiSuggestionDismissal;
use App\Services\LumiSuggestionService;
use App\Support\GuardSessionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LumiController extends Controller
{
    public function show(LumiSuggestionService $suggestions): View
    {
        session()->put(GuardSessionState::key('lumi_suggestions_seen'), true);

        return view('user.lumi', [
            'suggestions' => $suggestions->forCustomer(auth()->user()),
            'userName' => auth()->user()->name,
        ]);
    }

    public function suggestions(Request $request, LumiSuggestionService $suggestions): JsonResponse
    {
        $items = $suggestions->forCustomer($request->user());
        $loginPopoverPending = (bool) $request->session()->pull(
            GuardSessionState::key('lumi_login_popover_pending'),
            false,
        );
        $showLoginPopover = $loginPopoverPending
            && $items !== []
            && $request->path() !== 'lumi';

        if ($showLoginPopover) {
            $request->session()->put(GuardSessionState::key('lumi_login_popover_shown'), true);
        }

        return response()->json([
            'suggestions' => $items,
            'has_unseen' => ! $request->session()->get(GuardSessionState::key('lumi_suggestions_seen'), false) && $items !== [],
            'show_login_popover' => $showLoginPopover,
        ]);
    }

    public function dismiss(Request $request, LumiSuggestionService $suggestions): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:191'],
        ]);
        $user = $request->user();
        $visible = collect($suggestions->forCustomer($user))
            ->contains(fn (array $suggestion) => $suggestion['key'] === $validated['key']);

        abort_unless($visible, 404);

        LumiSuggestionDismissal::query()->updateOrCreate(
            [
                'user_id' => $user->user_id,
                'suggestion_key' => $validated['key'],
            ],
            [
                'dismissed_until' => now()->addDays(max(1, (int) config('lumi.dismissal_cooldown_days', 7))),
            ],
        );
        $suggestions->forgetForUser((int) $user->user_id);

        return response()->json(['dismissed' => true]);
    }

    public function whatsNew(): View
    {
        $updates = collect(config('whats_new', []))
            ->sortByDesc('date')
            ->values();

        return view('user.whats-new', [
            'updates' => $updates,
            'userName' => auth()->user()->name,
        ]);
    }
}
