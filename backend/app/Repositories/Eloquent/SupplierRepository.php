<?php

namespace App\Repositories\Eloquent;

use App\Models\Supplier;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SupplierRepository implements SupplierRepositoryInterface
{
    public function allWithProductCount(): Collection
    {
        $suppliers = Supplier::orderBy('name')->get();
        $suppliers->each(function (Supplier $supplier): void {
            $legacyIds = Product::query()->where('supplier_id', $supplier->id)->pluck('id');
            $catalogueIds = ProductSupplier::query()
                ->where('supplier_id', $supplier->id)
                ->where('is_active', true)
                ->pluck('product_id');
            $supplier->setAttribute('products_count', $legacyIds->merge($catalogueIds)->unique()->count());
        });

        return $suppliers;
    }

    public function findById(int $id): ?Supplier
    {
        return Supplier::find($id);
    }

    public function create(array $data): Supplier
    {
        return Supplier::create($data);
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->update($data);

        return $supplier->fresh();
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }

    public function hasProducts(Supplier $supplier): bool
    {
        return $supplier->products()->exists();
    }
}
