<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request, ActivityLogQuery $activityLogs): View
    {
        $logs = $activityLogs->forAdmin($request);

        return view('admin.audits.activity_logs', compact('logs'));
    }
}
