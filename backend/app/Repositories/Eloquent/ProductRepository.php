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

        $query = Product::with(['category', 'supplier', 'supplierCatalogue.supplier:id,name', 'defaultWarehouse:id,name,code', 'units', 'warehouseStock.warehouse:id,name,code', 'warehouseStock.location:id,warehouse_id,path,name,type,floor_level'])->orderBy($sort, $direction);

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
            $query->where(fn ($supplierQuery) => $supplierQuery
                ->where('supplier_id', $filters['supplier_id'])
                ->orWhereHas('supplierCatalogue', fn ($catalogue) => $catalogue
                    ->where('supplier_id', $filters['supplier_id'])
                    ->where('is_active', true)));
        }

        if (! empty($filters['low_stock'])) {
            $query->whereColumn('quantity', '<=', 'min_quantity');
        }

        if (! empty($filters['location_code'])) {
            $query->where('location_code', $filters['location_code']);
        }

        return $query->paginate($perPage);
    }

    public function allWithRelations(): Collection
    {
        return Product::with(['category', 'supplier', 'supplierCatalogue.supplier:id,name', 'defaultWarehouse:id,name,code', 'units', 'warehouseStock.warehouse:id,name,code', 'warehouseStock.location:id,warehouse_id,path,name,type,floor_level'])->orderBy('name')->get();
    }

    public function findBySku(string $sku): ?Product
    {
        return Product::with(['category', 'supplier', 'supplierCatalogue.supplier:id,name', 'defaultWarehouse:id,name,code', 'units', 'warehouseStock.warehouse:id,name,code', 'warehouseStock.location:id,warehouse_id,path,name,type,floor_level'])->where('sku', $sku)->first();
    }

    public function findById(int $id): ?Product
    {
        return Product::with(['category', 'supplier', 'supplierCatalogue.supplier:id,name', 'defaultWarehouse:id,name,code', 'units', 'warehouseStock.warehouse:id,name,code', 'warehouseStock.location:id,warehouse_id,path,name,type,floor_level'])->find($id);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        if (array_key_exists('quantity', $data)) {
            throw new \LogicException('Inventory quantity must be changed through StockMovementService.');
        }

        $product->update($data);

        return $product->fresh(['category', 'supplier', 'supplierCatalogue.supplier', 'defaultWarehouse', 'units', 'warehouseStock.warehouse', 'warehouseStock.location']);
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    public function byLocationCode(string $locationCode, int $companyId): Collection
    {
        return Product::with(['category', 'supplier', 'supplierCatalogue.supplier:id,name', 'defaultWarehouse:id,name,code', 'units', 'warehouseStock.warehouse:id,name,code', 'warehouseStock.location:id,warehouse_id,path,name,type,floor_level'])
            ->where('company_id', $companyId)
            ->where('location_code', $locationCode)
            ->orderBy('name')
            ->get();
    }

    public function searchGlobal(string $term, int $limit = 20): Collection
    {
        return Product::with(['category', 'supplier', 'supplierCatalogue.supplier:id,name', 'defaultWarehouse:id,name,code', 'units', 'warehouseStock.warehouse:id,name,code', 'warehouseStock.location:id,warehouse_id,path,name,type,floor_level'])
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
