<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    public function index(MetricsService $metrics): JsonResponse
    {
        return response()->json($metrics->combined());
    }
}
