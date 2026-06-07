<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Repositories\Contracts\ProductRepositoryInterface;

class SearchService
{
    public function __construct(
        private ProductRepositoryInterface $products
    ) {}

    public function search(string $term): array
    {
        $like = "%{$term}%";

        $products = $this->products->searchGlobal($term, 10);

        $categories = Category::where('name', 'like', $like)
            ->limit(5)
            ->get(['id', 'name']);

        $suppliers = Supplier::where('name', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->limit(5)
            ->get(['id', 'name', 'email']);

        $invoices = Invoice::where('invoice_number', 'like', $like)
            ->orWhere('customer_name', 'like', $like)
            ->limit(5)
            ->get(['id', 'invoice_number', 'customer_name', 'status', 'total_amount']);

        $stockMovements = StockMovement::with('product:id,name,sku')
            ->where(function ($query) use ($like) {
                $query->where('reason', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhereHas('product', function ($productQuery) use ($like) {
                        $productQuery->where('name', 'like', $like)->orWhere('sku', 'like', $like);
                    });
            })
            ->latest()
            ->limit(5)
            ->get(['id', 'product_id', 'type', 'quantity', 'reason', 'created_at']);

        return [
            'products' => $products,
            'categories' => $categories,
            'suppliers' => $suppliers,
            'invoices' => $invoices,
            'stock_movements' => $stockMovements,
        ];
    }
}
