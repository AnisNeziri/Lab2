<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\DashboardService;
use App\Services\InventorySnapshotService;
use App\Services\RedisStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService,
        private RedisStoreService $redisStore,
        private InventorySnapshotService $inventorySnapshots,
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
        // Redis is only an acceleration layer. Re-check the database so a
        // stale cache can never make the dashboard report healthy inventory.
        $products = Product::with('category')->get();
        $snapshots = $this->inventorySnapshots->forProducts($products);
        $alerts = $products->filter(function (Product $product) use ($snapshots): bool {
            return $snapshots->get((int) $product->id)['available'] <= (float) $product->min_quantity;
        })->map(function (Product $product) use ($snapshots): Product {
            $copy = clone $product;
            $totals = $snapshots->get((int) $product->id);
            $copy->setAttribute('quantity', $totals['available']);
            $copy->setAttribute('available_quantity', $totals['available']);
            $copy->setAttribute('on_hand_quantity', $totals['on_hand']);

            return $copy;
        })->sortBy('quantity')->values();
        $productIds = $alerts->pluck('id')->map(fn ($id) => (string) $id)->all();

        return response()->json([
            'product_ids' => $productIds,
            'alerts' => $alerts,
        ]);
    }
}
