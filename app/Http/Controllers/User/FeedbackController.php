<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    public function index(): View
    {
        $feedbacks = Feedback::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->get();

        return view('user.feedback.feedback', [
            'feedbacks' => $feedbacks,
            'userName'  => auth()->user()->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rating'   => ['required', 'integer', 'min:1', 'max:5'],
            'category' => ['nullable', 'string', 'max:50'],
            'message'  => ['nullable', 'string', 'max:2000'],
        ]);

        $feedback = Feedback::create([
            'user_id'  => auth()->id(),
            'rating'   => $validated['rating'],
            'category' => $validated['category'] ?? null,
            'message'  => $validated['message'] ?? null,
        ]);

        ActivityLogger::log(
            'feedback.submitted',
            auth()->user()->name . " submitted feedback ({$feedback->rating}/5).",
            subject: $feedback,
        );

        return back()->with('success', 'Thanks for your feedback! We really appreciate it.');
    }

    public function update(Request $request, Feedback $feedback): RedirectResponse
    {
        // Only the person who wrote it can edit it — this route is
        // reachable by any authenticated user, the id in the URL alone
        // doesn't prove ownership.
        abort_unless($feedback->user_id === auth()->id(), 403);

        $validated = $request->validate([
            'rating'   => ['required', 'integer', 'min:1', 'max:5'],
            'category' => ['nullable', 'string', 'max:50'],
            'message'  => ['nullable', 'string', 'max:2000'],
        ]);

        $feedback->update([
            'rating'   => $validated['rating'],
            'category' => $validated['category'] ?? null,
            'message'  => $validated['message'] ?? null,
        ]);

        ActivityLogger::log(
            'feedback.updated',
            auth()->user()->name . " updated their feedback ({$feedback->rating}/5).",
            subject: $feedback,
        );

        return back()->with('success', 'Your feedback has been updated.');
    }

    public function destroy(Feedback $feedback): RedirectResponse
    {
        abort_unless($feedback->user_id === auth()->id(), 403);

        // Logged before delete() so the subject still has a real id to
        // point at in the audit trail — ActivityLog rows persist even
        // after the thing they describe is gone, same as any other
        // "deleted" event elsewhere in this app.
        ActivityLogger::log(
            'feedback.deleted',
            auth()->user()->name . " deleted their feedback ({$feedback->rating}/5).",
            subject: $feedback,
        );

        $feedback->delete();

        return back()->with('success', 'Your feedback has been deleted.');
    }
}