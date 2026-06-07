<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Repositories\Contracts\ProductRepositoryInterface;

class SearchService
{
    public function __construct(
        private ProductRepositoryInterface $products
    ) {}

    public function search(string $term): array
    {
        $products = $this->products->searchGlobal($term, 10);

        $categories = Category::where('name', 'like', "%{$term}%")
            ->limit(5)
            ->get(['id', 'name']);

        $suppliers = Supplier::where('name', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")
            ->limit(5)
            ->get(['id', 'name', 'email']);

        $invoices = Invoice::where('invoice_number', 'like', "%{$term}%")
            ->orWhere('customer_name', 'like', "%{$term}%")
            ->limit(5)
            ->get(['id', 'invoice_number', 'customer_name', 'status', 'total_amount']);

        return [
            'products' => $products,
            'categories' => $categories,
            'suppliers' => $suppliers,
            'invoices' => $invoices,
        ];
    }
}
