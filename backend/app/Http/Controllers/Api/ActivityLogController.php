<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;

class ActivityLogController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLogs
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->activityLogs->list());
    }
}
