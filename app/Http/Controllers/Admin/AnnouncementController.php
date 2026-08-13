<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\ActivityLogger;
use App\Support\NotificationService;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index()
    {
        $announcements = Announcement::latest()->paginate(15);

        return view('admin.announcements.announcements-index', compact('announcements'));
    }

    public function create()
    {
        return view('admin.announcements.announcements-create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body'  => ['required', 'string', 'max:2000'],
        ]);

        $recipientCount = NotificationService::announceToAll($validated['title'], $validated['body']);

        // Keep a record of the announcement itself (separate from the
        // per-user notifications announceToAll() fans out) so the index
        // page has something to list.
        $announcement = Announcement::create([
            'title' => $validated['title'],
            'body'  => $validated['body'],
        ]);

        ActivityLogger::log(
            'announcement.sent',
            auth()->user()->name . " sent an announcement (\"{$validated['title']}\") to {$recipientCount} user(s).",
            properties: ['title' => $validated['title'], 'recipient_count' => $recipientCount],
        );

        $message = "Announcement sent to {$recipientCount} user(s).";

        // The index page's modal submits via fetch with an Accept: application/json
        // header, so hand it back JSON to prepend to the table in place. A
        // plain form post (e.g. direct navigation to /announcements/create)
        // still gets the usual redirect-with-flash.
        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'announcement' => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'created_at' => $announcement->created_at->format('M d, Y g:i A'),
                ],
            ]);
        }

        return redirect()
            ->route('admin.announcements.index')
            ->with('success', $message);
    }
}