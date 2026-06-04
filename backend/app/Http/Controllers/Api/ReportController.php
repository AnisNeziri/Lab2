<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(): JsonResponse
    {
        $categoryReports = Category::query()
            ->leftJoin('products', 'categories.id', '=', 'products.category_id')
            ->select('categories.id', 'categories.name')
            ->selectRaw('COUNT(products.id) as product_count')
            ->selectRaw('COALESCE(SUM(products.quantity), 0) as total_units')
            ->selectRaw('COALESCE(SUM(products.quantity * products.price), 0) as total_value')
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'product_count' => (int) $row->product_count,
                'total_units' => (int) $row->total_units,
                'total_value' => round((float) $row->total_value, 2),
            ]);

        $topProducts = Product::with('category')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'quantity' => $product->quantity,
                'price' => $product->price,
                'value' => round($product->quantity * $product->price, 2),
            ])
            ->sortByDesc('value')
            ->take(10)
            ->values();

        $stockSummary = [
            'total_stock_in' => (int) DB::table('stock_movements')->where('type', 'in')->sum('quantity'),
            'total_stock_out' => (int) DB::table('stock_movements')->where('type', 'out')->sum('quantity'),
            'movement_count' => (int) DB::table('stock_movements')->count(),
        ];

        $supplierReports = Supplier::query()
            ->leftJoin('products', 'suppliers.id', '=', 'products.supplier_id')
            ->select('suppliers.id', 'suppliers.name')
            ->selectRaw('COUNT(products.id) as product_count')
            ->selectRaw('COALESCE(SUM(products.quantity), 0) as total_units')
            ->selectRaw('COALESCE(SUM(products.quantity * products.price), 0) as total_value')
            ->groupBy('suppliers.id', 'suppliers.name')
            ->orderBy('suppliers.name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'product_count' => (int) $row->product_count,
                'total_units' => (int) $row->total_units,
                'total_value' => round((float) $row->total_value, 2),
            ]);

        return response()->json([
            'categories' => $categoryReports,
            'suppliers' => $supplierReports,
            'top_products' => $topProducts,
            'stock_summary' => $stockSummary,
        ]);
    }
}
