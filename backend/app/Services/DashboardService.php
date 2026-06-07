<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;

class DashboardService
{
    public function getMetrics(): array
    {
        $products = Product::with('category')->get();

        $totalUnits = $products->sum('quantity');
        $totalValue = $products->sum(fn (Product $product) => $product->quantity * $product->price);
        $inventoryValue = $products->sum(fn (Product $product) =>
            $product->quantity * ($product->purchase_price ?? $product->price)
        );
        $expectedNetProfit = $products->sum(fn (Product $product) =>
            $product->quantity * (($product->selling_price ?? $product->price) - ($product->purchase_price ?? $product->price))
        );

        $lowStockProducts = $products
            ->filter(fn (Product $product) => $product->quantity <= $product->min_quantity)
            ->sortBy('quantity')
            ->values();

        $outOfStockProducts = $products
            ->filter(fn (Product $product) => $product->quantity <= 0)
            ->sortBy('name')
            ->values();

        $recentMovements = StockMovement::with('product')
            ->latest()
            ->limit(5)
            ->get();

        $categoryValues = $products->groupBy(fn (Product $p) => $p->category->name ?? 'Uncategorized')
            ->map(function ($group, $name) {
                return [
                    'name' => $name,
                    'value' => round($group->sum(fn ($product) => $product->quantity * $product->price), 2),
                ];
            })->values();

        $movementsOverTime = StockMovement::selectRaw("DATE(created_at) as date, type, SUM(quantity) as total_qty")
            ->groupBy('date', 'type')
            ->orderBy('date', 'asc')
            ->get()
            ->groupBy('date')
            ->map(function ($items, $date) {
                $in = 0;
                $out = 0;
                foreach ($items as $item) {
                    if ($item->type === 'in') {
                        $in += $item->total_qty;
                    } elseif ($item->type === 'out') {
                        $out += $item->total_qty;
                    }
                }
                return [
                    'date' => date('d M', strtotime($date)),
                    'in' => (int) $in,
                    'out' => (int) $out,
                ];
            })
            ->values();

        $totalStockOut = (int) StockMovement::where('type', 'out')->sum('quantity');
        $stockTurnover = $totalUnits > 0 ? round($totalStockOut / $totalUnits, 2) : 0;

        return [
            'total_products' => $products->count(),
            'total_categories' => Category::count(),
            'total_suppliers' => Supplier::count(),
            'total_units' => $totalUnits,
            'total_value' => round($totalValue, 2),
            'inventory_value' => round($inventoryValue, 2),
            'expected_net_profit' => round($expectedNetProfit, 2),
            'stock_turnover' => $stockTurnover,
            'low_stock_count' => $lowStockProducts->count(),
            'low_stock_products' => $lowStockProducts,
            'out_of_stock_count' => $outOfStockProducts->count(),
            'out_of_stock_products' => $outOfStockProducts,
            'recent_movements' => $recentMovements,
            'category_values' => $categoryValues,
            'movements_over_time' => $movementsOverTime,
        ];
    }
}
