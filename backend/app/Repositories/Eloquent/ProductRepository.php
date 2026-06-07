<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ProductRepository implements ProductRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';

        $query = Product::with(['category', 'supplier'])->orderBy($sort, $direction);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['low_stock'])) {
            $query->whereColumn('quantity', '<=', 'min_quantity');
        }

        return $query->paginate($perPage);
    }

    public function allWithRelations(): Collection
    {
        return Product::with(['category', 'supplier'])->orderBy('name')->get();
    }

    public function findBySku(string $sku): ?Product
    {
        return Product::with(['category', 'supplier'])->where('sku', $sku)->first();
    }

    public function findById(int $id): ?Product
    {
        return Product::with(['category', 'supplier'])->find($id);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->fresh(['category', 'supplier']);
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    public function searchGlobal(string $term, int $limit = 20): Collection
    {
        return Product::with(['category', 'supplier'])
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            })
            ->limit($limit)
            ->get();
    }
}
