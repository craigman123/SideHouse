<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = ActivityLog::query()
            ->when($request->filled('user'), fn ($q) => $q->where('user_name', 'like', '%' . $request->user . '%'))
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', '%' . $request->action . '%'))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.audits.activity_logs', compact('logs'));
    }
}