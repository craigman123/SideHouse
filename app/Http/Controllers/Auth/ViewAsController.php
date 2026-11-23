<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ViewAsController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::in(['admin', 'owner', 'staff', 'customer'])]]);
        $actor = User::query()->findOrFail($request->user()->user_id);
        abort_unless($actor->role === 'admin', 403, 'Only a real admin can switch access views.');

        $from = $request->session()->get('view_as', 'admin');
        $to = $validated['role'];
        ActivityLogger::log(
            'view_as.switched',
            "Admin #{$actor->user_id} switched access view from {$from} to {$to}.",
            actor: $actor,
            subject: $actor,
            properties: ['from' => $from, 'to' => $to],
        );
        $request->session()->put('view_as', $to);

        return redirect()->to($this->dashboardPath($to));
    }

    public function reset(Request $request): RedirectResponse
    {
        $actor = User::query()->findOrFail($request->user()->user_id);
        abort_unless($actor->role === 'admin', 403, 'Only a real admin can reset the access view.');

        $from = $request->session()->get('view_as', 'admin');
        ActivityLogger::log(
            'view_as.reset',
            "Admin #{$actor->user_id} returned from {$from} view to admin.",
            actor: $actor,
            subject: $actor,
            properties: ['from' => $from, 'to' => 'admin'],
        );
        $request->session()->forget('view_as');

        return redirect()->to($this->dashboardPath('admin'));
    }

    private function dashboardPath(string $role): string
    {
        return match ($role) {
            'owner' => route('owner.dashboard', absolute: false),
            'staff' => route('staff.dashboard', absolute: false),
            'customer' => route('user.dashboard.view-as', absolute: false),
            default => route('admin.dashboard.view-as', absolute: false),
        };
    }
}
