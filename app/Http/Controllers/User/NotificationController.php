<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = Notification::forUser(auth()->id())
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('user.notifications.notifications-index', [
            'notifications' => $notifications,
            'userName'      => auth()->user()->name,
        ]);
    }

    /**
     * Polled by the bell icon in the nav (see notification-bell.js) to
     * decide whether to show the red unread dot. Deliberately just a
     * count, not the notifications themselves — keeps this cheap enough
     * to poll on every page, not just the notifications page itself.
     */
    public function unreadCount()
    {
        return response()->json([
            'unread' => Notification::forUser(auth()->id())->unread()->count(),
        ]);
    }

    public function markRead(Notification $notification)
    {
        abort_unless($notification->user_id === auth()->id(), 403);

        $notification->markRead();

        return response()->json(['status' => 'ok']);
    }

    public function markAllRead(Request $request)
    {
        Notification::forUser(auth()->id())->unread()->update(['read_at' => now()]);

        if ($request->wantsJson()) {
            return response()->json(['status' => 'ok']);
        }

        return redirect()->route('user.notifications.index');
    }
}
