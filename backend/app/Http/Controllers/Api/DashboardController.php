<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    private const LOW_STOCK_THRESHOLD = 5;

    public function index(): JsonResponse
    {
        $products = Product::with('category')->get();

        $totalUnits = $products->sum('quantity');
        $totalValue = $products->sum(fn (Product $product) => $product->quantity * $product->price);

        $lowStockProducts = $products
            ->filter(fn (Product $product) => $product->quantity <= self::LOW_STOCK_THRESHOLD)
            ->sortBy('quantity')
            ->values();

        return response()->json([
            'total_products' => $products->count(),
            'total_units' => $totalUnits,
            'total_value' => round($totalValue, 2),
            'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
            'low_stock_count' => $lowStockProducts->count(),
            'low_stock_products' => $lowStockProducts,
        ]);
    }
}
