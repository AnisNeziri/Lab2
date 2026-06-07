<?php

namespace App\Services;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductService
{
    public function __construct(
        private ProductRepositoryInterface $products
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->products->paginate($filters, $perPage);
    }

    public function lookup(string $sku): ?Product
    {
        return $this->products->findBySku($sku);
    }

    public function create(array $data): Product
    {
        if (empty($data['unit'])) {
            $data['unit'] = 'pcs';
        }

        return $this->products->create($data);
    }

    public function update(Product $product, array $data): Product
    {
        if (array_key_exists('unit', $data) && empty($data['unit'])) {
            $data['unit'] = 'pcs';
        }

        return $this->products->update($product, $data);
    }

    public function delete(Product $product): void
    {
        $this->products->delete($product);
    }

    public function exportCsv(): StreamedResponse
    {
        $products = $this->products->allWithRelations();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="products.csv"',
        ];

        $callback = function () use ($products) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Name', 'SKU', 'Barcode', 'Category', 'Supplier', 'Quantity', 'Unit', 'Min Quantity', 'Price', 'Description']);

            foreach ($products as $product) {
                fputcsv($handle, [
                    $product->name,
                    $product->sku,
                    $product->barcode ?? '',
                    $product->category?->name ?? '',
                    $product->supplier?->name ?? '',
                    $product->quantity,
                    $product->unit,
                    $product->min_quantity,
                    $product->price,
                    $product->description ?? '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function globalSearch(string $term): Collection
    {
        return $this->products->searchGlobal($term);
    }
}
