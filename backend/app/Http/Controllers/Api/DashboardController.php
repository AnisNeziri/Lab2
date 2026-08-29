<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
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

    public function salesAnalytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:week,month,year'],
            'date' => ['nullable', 'date'],
        ]);

        return response()->json($this->dashboardService->getSalesAnalytics(
            $request->user()->company_id,
            $validated['period'] ?? 'week',
            $validated['date'] ?? null,
        ));
    }

    public function activityFeed(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $limit = min((int) $request->query('limit', 20), 100);

        return response()->json([
            'feed' => $this->redisStore->getRecentActivity($companyId, $limit),
        ]);
    }

    public function lowStockAlerts(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        // Redis is only an acceleration layer. Re-check the database so a
        // stale cache can never make the dashboard report healthy inventory.
        $cachedIds = $this->redisStore->getLowStockAlerts($companyId);
        $actualAlerts = Product::with('category')
            ->whereColumn('quantity', '<=', 'min_quantity')
            ->orderBy('quantity')
            ->get();
        $productIds = $actualAlerts->pluck('id')->map(fn ($id) => (string) $id)->all();
        $alerts = $actualAlerts->keyBy('id');
        if ($cachedIds) {
            Product::with('category')->whereIn('id', $cachedIds)->get()->each(function ($product) use ($alerts) {
                if ((float) $product->quantity <= (float) $product->min_quantity) {
                    $alerts->put($product->id, $product);
                }
            });
        }

        return response()->json([
            'product_ids' => $productIds,
            'alerts' => $alerts->sortBy('quantity')->values(),
        ]);
    }
}
