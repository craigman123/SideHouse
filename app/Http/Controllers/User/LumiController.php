<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LumiController extends Controller
{
    public function show(): View
    {
        return view('user.lumi', [
            'userName' => auth()->user()->name,
        ]);
    }

    public function reply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'reply' => $this->replyFromAlgorithm($validated['message']),
        ]);
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

    private function replyFromAlgorithm(string $message): string
    {
        // TODO Connect the existing assistant algorithm when it is available; none exists in this repo yet.
        return "I'm not connected to the venue's assistant service yet. For current booking details, use Book a Court or My Bookings.";
    }
}
