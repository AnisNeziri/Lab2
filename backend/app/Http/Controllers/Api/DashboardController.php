<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Services\RedisStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService,
        private RedisStoreService $redisStore
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        return response()->json($this->dashboardService->getMetrics($companyId));
    }

    public function activityFeed(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $limit     = min((int) $request->query('limit', 20), 100);

        return response()->json([
            'feed' => $this->redisStore->getRecentActivity($companyId, $limit),
        ]);
    }

    public function lowStockAlerts(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        return response()->json([
            'product_ids' => $this->redisStore->getLowStockAlerts($companyId),
        ]);
    }
}
