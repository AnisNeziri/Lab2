<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use Illuminate\Support\Facades\Auth;

class ReportService
{
    public function __construct(
        private StockMovementRepositoryInterface $movements,
        private InventorySnapshotService $inventorySnapshots,
    ) {}

    public function generate(): array
    {
        $companyId = Auth::user()?->company_id;

        $products = Product::with(['category', 'supplier'])->get();
        $snapshots = $this->inventorySnapshots->forProducts($products);
        $snapshot = fn (Product $product): array => $snapshots->get((int) $product->id, [
            'on_hand' => (float) $product->quantity,
            'available' => (float) $product->quantity,
        ]);

        $byCategory = $products->groupBy('category_id');
        $categoryReports = Category::query()->orderBy('name')->get()->map(function (Category $category) use ($byCategory, $snapshot): array {
            $items = $byCategory->get($category->id, collect());

            return [
                'id' => $category->id,
                'name' => $category->name,
                'product_count' => $items->count(),
                'total_units' => round($items->sum(fn (Product $product) => $snapshot($product)['on_hand']), 3),
                'total_value' => round($items->sum(fn (Product $product) => $snapshot($product)['on_hand'] * (float) $product->price), 2),
            ];
        });

        $topProducts = $products
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'quantity' => $snapshot($product)['on_hand'],
                'available_quantity' => $snapshot($product)['available'],
                'price' => $product->price,
                'value' => round($snapshot($product)['on_hand'] * (float) $product->price, 2),
            ])
            ->sortByDesc('value')
            ->take(10)
            ->values();

        $bySupplier = $products->groupBy('supplier_id');
        $supplierReports = Supplier::query()->orderBy('name')->get()->map(function (Supplier $supplier) use ($bySupplier, $snapshot): array {
            $items = $bySupplier->get($supplier->id, collect());

            return [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'product_count' => $items->count(),
                'total_units' => round($items->sum(fn (Product $product) => $snapshot($product)['on_hand']), 3),
                'total_value' => round($items->sum(fn (Product $product) => $snapshot($product)['on_hand'] * (float) $product->price), 2),
            ];
        });

        return [
            'categories' => $categoryReports,
            'suppliers' => $supplierReports,
            'top_products' => $topProducts,
            'stock_summary' => $this->movements->stockSummaryForCompany($companyId),
        ];
    }
}
