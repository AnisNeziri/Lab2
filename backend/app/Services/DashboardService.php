<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;

class DashboardService
{
    public function __construct(
        private RedisStoreService $redisStore
    ) {}

    public function getMetrics(int $companyId): array
    {
        // Try Redis first (NoSQL store)
        $cached = $this->redisStore->getDashboardStats($companyId);
        if ($cached) {
            return $cached;
        }

        $metrics = $this->buildMetrics();

        // Persist KPI counters to Redis as a Hash (NoSQL write)
        $this->redisStore->setDashboardStats($companyId, [
            'total_products'     => $metrics['total_products'],
            'total_categories'   => $metrics['total_categories'],
            'total_suppliers'    => $metrics['total_suppliers'],
            'total_units'        => $metrics['total_units'],
            'total_value'        => $metrics['total_value'],
            'inventory_value'    => $metrics['inventory_value'],
            'low_stock_count'    => $metrics['low_stock_count'],
            'out_of_stock_count' => $metrics['out_of_stock_count'],
            'stock_turnover'     => $metrics['stock_turnover'],
        ]);

        // Sync low-stock product IDs into Redis Set
        $lowStockIds = collect($metrics['low_stock_products'])->pluck('id')->toArray();
        $this->redisStore->setLowStockAlerts($companyId, $lowStockIds);

        return $metrics;
    }

    private function buildMetrics(): array
    {
        $products = Product::with('category')->get();

        $totalUnits  = $products->sum('quantity');
        $totalValue  = $products->sum(fn (Product $p) => $p->quantity * $p->price);
        $inventoryValue = $products->sum(fn (Product $p) =>
            $p->quantity * ($p->purchase_price ?? $p->price)
        );
        $expectedNetProfit = $products->sum(fn (Product $p) =>
            $p->quantity * (($p->selling_price ?? $p->price) - ($p->purchase_price ?? $p->price))
        );

        $lowStockProducts  = $products->filter(fn (Product $p) => $p->quantity <= $p->min_quantity)->sortBy('quantity')->values();
        $outOfStockProducts = $products->filter(fn (Product $p) => $p->quantity <= 0)->sortBy('name')->values();

        $recentMovements = StockMovement::with('product')->latest()->limit(5)->get();

        $categoryValues = $products->groupBy(fn (Product $p) => $p->category->name ?? 'Uncategorized')
            ->map(fn ($group, $name) => [
                'name'  => $name,
                'value' => round($group->sum(fn ($p) => $p->quantity * $p->price), 2),
            ])->values();

        $movementsOverTime = StockMovement::selectRaw("DATE(created_at) as date, type, SUM(quantity) as total_qty")
            ->groupBy('date', 'type')
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(function ($items, $date) {
                $in = 0; $out = 0;
                foreach ($items as $item) {
                    $item->type === 'in' ? ($in += $item->total_qty) : ($out += $item->total_qty);
                }
                return ['date' => date('d M', strtotime($date)), 'in' => (int) $in, 'out' => (int) $out];
            })->values();

        $totalStockOut = (int) StockMovement::where('type', 'out')->sum('quantity');
        $stockTurnover = $totalUnits > 0 ? round($totalStockOut / $totalUnits, 2) : 0;

        return [
            'total_products'       => $products->count(),
            'total_categories'     => Category::count(),
            'total_suppliers'      => Supplier::count(),
            'total_units'          => $totalUnits,
            'total_value'          => round($totalValue, 2),
            'inventory_value'      => round($inventoryValue, 2),
            'expected_net_profit'  => round($expectedNetProfit, 2),
            'stock_turnover'       => $stockTurnover,
            'low_stock_count'      => $lowStockProducts->count(),
            'low_stock_products'   => $lowStockProducts,
            'out_of_stock_count'   => $outOfStockProducts->count(),
            'out_of_stock_products'=> $outOfStockProducts,
            'recent_movements'     => $recentMovements,
            'category_values'      => $categoryValues,
            'movements_over_time'  => $movementsOverTime,
        ];
    }
}
