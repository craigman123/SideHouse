<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class ActivityLogsController extends Controller
{
    public function index(Request $request, ActivityLogQuery $activityLogs): View
    {
        return view('staff.activity-logs', [
            'logs' => $activityLogs->forStaff($request),
            'dataUrl' => route('staff.activity-logs.data'),
        ]);
    }

    public function data(Request $request, ActivityLogQuery $activityLogs): JsonResponse
    {
        $logs = $activityLogs->forStaff($request);

        return response()->json([
            'data' => $logs->getCollection()->map(fn ($log) => [
                'id' => (int) $log->id,
                'user_name' => $log->user_name ?? 'Guest',
                'action' => $log->action,
                'description' => $log->description,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => $this->pagination($logs),
        ]);
    }

    private function pagination(LengthAwarePaginator $logs): array
    {
        return [
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
            'from' => $logs->firstItem(),
            'to' => $logs->lastItem(),
        ];
    }
}
