<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::with('category')->get();

        $totalUnits = $products->sum('quantity');
        $totalValue = $products->sum(fn (Product $product) => $product->quantity * $product->price);

        $lowStockProducts = $products
            ->filter(fn (Product $product) => $product->quantity <= $product->min_quantity)
            ->sortBy('quantity')
            ->values();

        $recentMovements = StockMovement::with('product')
            ->latest()
            ->limit(5)
            ->get();

        return response()->json([
            'total_products' => $products->count(),
            'total_suppliers' => Supplier::count(),
            'total_units' => $totalUnits,
            'total_value' => round($totalValue, 2),
            'low_stock_count' => $lowStockProducts->count(),
            'low_stock_products' => $lowStockProducts,
            'recent_movements' => $recentMovements,
        ]);
    }
}
