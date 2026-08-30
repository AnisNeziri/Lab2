<?php

namespace App\Repositories\Eloquent;

use App\Models\Supplier;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\ActivityLog;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        $supplier = Supplier::create([
            ...$data,
            'is_active' => true,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);
        $this->audit('supplier.created', $supplier, null, $supplier->toArray());

        return $supplier;
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        $old = $supplier->toArray();
        $supplier->update([...$data, 'updated_by' => Auth::id()]);
        $this->audit('supplier.updated', $supplier, $old, $supplier->fresh()->toArray());

        return $supplier->fresh();
    }

    public function delete(Supplier $supplier): void
    {
        DB::transaction(function () use ($supplier): void {
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $old = $locked->toArray();
            ProductSupplier::query()->where('supplier_id', $locked->id)->update([
                'is_active' => false,
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);
            $locked->update(['is_active' => false, 'updated_by' => Auth::id()]);
            $locked->delete();
            $this->audit('supplier.archived', $locked, $old, $locked->toArray());
        });
    }

    public function hasProducts(Supplier $supplier): bool
    {
        return $supplier->products()->exists();
    }

    private function audit(string $action, Supplier $supplier, ?array $old, array $new): void
    {
        ActivityLog::create([
            'company_id' => $supplier->company_id,
            'user_id' => Auth::id(),
            'action' => $action,
            'entity' => 'Supplier',
            'entity_id' => $supplier->id,
            'description' => str_replace('.', ' ', $action),
            'old_value' => $old,
            'new_value' => $new,
            'ip_address' => request()?->ip(),
        ]);
    }
}
