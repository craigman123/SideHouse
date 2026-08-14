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
        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'announcement' => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'created_at' => $announcement->created_at->format('M d, Y g:i A'),
                    'delete_url' => route('admin.announcements.destroy', $announcement),
                ],
            ]);
        }
        return redirect()
            ->route('admin.announcements.index')
            ->with('success', $message);
    }

    /**
     * Deletes this announcement's row from the admin list only — it does
     * NOT touch the per-user Notification rows NotificationService::
     * announceToAll() already fanned out when this was sent. Those
     * already landed in every user's bell/notifications page and can't
     * be un-sent; this just cleans up your own record of having sent it.
     */
    public function destroy(Announcement $announcement)
    {
        $title = $announcement->title;
        $announcement->delete();

        ActivityLogger::log(
            'announcement.deleted',
            auth()->user()->name . " deleted the announcement record \"{$title}\" (the notification already sent to users is unaffected).",
            properties: ['title' => $title],
        );

        $message = 'Announcement deleted.';

        if (request()->wantsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('admin.announcements.index')->with('success', $message);
    }
}
