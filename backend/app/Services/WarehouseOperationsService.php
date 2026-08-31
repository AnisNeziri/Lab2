<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseOperationsService
{
    public function __construct(
        private readonly StockMovementService $movements,
        private readonly UnitConversionService $units,
        private readonly WarehouseLayoutService $layout,
        private readonly InventoryIntegrityService $integrity,
    ) {}

    public function warehouses(): array
    {
        return Warehouse::query()
            ->withCount([
                'stock',
                'locations',
                'stock as available_products_count' => fn ($query) => $query->where('available_quantity', '>', 0),
                'stock as reserved_products_count' => fn ($query) => $query->where('reserved_quantity', '>', 0),
                'stock as damaged_products_count' => fn ($query) => $query->where('damaged_quantity', '>', 0),
            ])
            ->withSum('stock as available_quantity', 'available_quantity')
            ->withSum('stock as reserved_quantity', 'reserved_quantity')
            ->withSum('stock as damaged_quantity', 'damaged_quantity')
            ->withSum('stock as quarantine_quantity', 'quarantine_quantity')
            ->withSum('stock as blocked_quantity', 'blocked_quantity')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $warehouse) => [
                ...$warehouse->toArray(),
                'available_quantity' => round((float) ($warehouse->available_quantity ?? 0), 3),
                'reserved_quantity' => round((float) ($warehouse->reserved_quantity ?? 0), 3),
                'damaged_quantity' => round((float) ($warehouse->damaged_quantity ?? 0), 3),
                'quarantine_quantity' => round((float) ($warehouse->quarantine_quantity ?? 0), 3),
                'blocked_quantity' => round((float) ($warehouse->blocked_quantity ?? 0), 3),
            ])->all();
    }

    public function createWarehouse(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            $companyId = (int) Auth::user()->company_id;
            $isFirst = ! Warehouse::query()->exists();
            $makeDefault = $isFirst || (bool) ($data['is_default'] ?? false);
            if ($makeDefault) {
                Warehouse::query()->update(['is_default' => false]);
            }

            return Warehouse::create([
                'company_id' => $companyId,
                'name' => trim($data['name']),
                'code' => strtoupper(trim($data['code'])),
                'address' => $data['address'] ?? null,
                'length_m' => $data['length_m'] ?? null,
                'width_m' => $data['width_m'] ?? null,
                'height_m' => $data['height_m'] ?? null,
                'floor_count' => $data['floor_count'] ?? 1,
                'is_active' => true,
                'is_default' => $makeDefault,
            ]);
        });
    }

    public function updateWarehouse(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data) {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            if (array_key_exists('is_default', $data) && ! $data['is_default'] && $warehouse->is_default) {
                throw ValidationException::withMessages(['is_default' => ['Set another warehouse as default before removing this designation.']]);
            }
            if ((bool) ($data['is_default'] ?? false)) {
                Warehouse::query()->where('id', '!=', $warehouse->id)->update(['is_default' => false]);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active'] && $warehouse->is_default) {
                throw ValidationException::withMessages(['is_active' => ['Choose another default warehouse before deactivating this one.']]);
            }
            if (($data['is_default'] ?? $warehouse->is_default) && ! ($data['is_active'] ?? $warehouse->is_active)) {
                throw ValidationException::withMessages(['is_active' => ['The default warehouse must remain active.']]);
            }
            $warehouse->update([
                'name' => isset($data['name']) ? trim($data['name']) : $warehouse->name,
                'code' => isset($data['code']) ? strtoupper(trim($data['code'])) : $warehouse->code,
                'address' => array_key_exists('address', $data) ? $data['address'] : $warehouse->address,
                'length_m' => array_key_exists('length_m', $data) ? $data['length_m'] : $warehouse->length_m,
                'width_m' => array_key_exists('width_m', $data) ? $data['width_m'] : $warehouse->width_m,
                'height_m' => array_key_exists('height_m', $data) ? $data['height_m'] : $warehouse->height_m,
                'floor_count' => array_key_exists('floor_count', $data) ? $data['floor_count'] : $warehouse->floor_count,
                'is_active' => $data['is_active'] ?? $warehouse->is_active,
                'is_default' => $data['is_default'] ?? $warehouse->is_default,
            ]);

            return $warehouse->fresh();
        });
    }

    public function deleteWarehouse(Warehouse $warehouse): void
    {
        DB::transaction(function () use ($warehouse): void {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            $this->integrity->assertWarehouseReconciled($warehouse);
            $hasStock = $warehouse->stock()->where(function ($query): void {
                foreach (['quantity', 'available_quantity', 'reserved_quantity', 'damaged_quantity', 'quarantine_quantity', 'blocked_quantity'] as $index => $column) {
                    $index === 0 ? $query->where($column, '!=', 0) : $query->orWhere($column, '!=', 0);
                }
            })->exists();
            $hasHistory = DB::table('stock_movements')->where(function ($query) use ($warehouse): void {
                $query->where('warehouse_id', $warehouse->id)
                    ->orWhere('source_warehouse_id', $warehouse->id)
                    ->orWhere('destination_warehouse_id', $warehouse->id);
            })->exists()
                || DB::table('purchase_orders')->where('warehouse_id', $warehouse->id)->exists()
                || DB::table('goods_receipts')->where('warehouse_id', $warehouse->id)->exists()
                || DB::table('inventory_trace_balances')->where('warehouse_id', $warehouse->id)->exists()
                || DB::table('inventory_count_sessions')->where('warehouse_id', $warehouse->id)->exists()
                || DB::table('inventory_return_items')->where('warehouse_id', $warehouse->id)->exists()
                || DB::table('daily_sale_items')->where('warehouse_id', $warehouse->id)->exists()
                || DB::table('invoice_items')->where('warehouse_id', $warehouse->id)->exists()
                || DB::table('shipments')->where('warehouse_id', $warehouse->id)->exists()
                || DB::table('stock_transfers')->where(function ($query) use ($warehouse): void {
                    $query->where('source_warehouse_id', $warehouse->id)
                        ->orWhere('destination_warehouse_id', $warehouse->id);
                })->exists();
            $isAssigned = Product::query()->where('default_warehouse_id', $warehouse->id)->exists();

            if ($warehouse->is_default || $hasStock || $hasHistory || $isAssigned) {
                throw ValidationException::withMessages([
                    'warehouse' => [
                        'A default, assigned, stocked, or historically referenced warehouse cannot be deleted. '
                        .'Move its stock, reassign products, and deactivate it to preserve the audit trail.',
                    ],
                ]);
            }

            $warehouse->delete();
        });
    }

    public function locations(?int $warehouseId = null): array
    {
        $warehouses = Warehouse::query()
            ->when($warehouseId, fn ($query) => $query->whereKey($warehouseId))
            ->get();
        $warehouses->each(fn (Warehouse $warehouse) => $this->layout->synchronizeWarehouse($warehouse));

        return WarehouseLocation::query()
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->with(['warehouse:id,name,code', 'section:id,warehouse_location_id,code,pos_x,pos_z,width,depth,floor_level'])
            ->orderBy('warehouse_id')->orderBy('path')->get()->toArray();
    }

    public function createLocation(array $data): WarehouseLocation
    {
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $parent = ! empty($data['parent_id']) ? WarehouseLocation::query()->findOrFail($data['parent_id']) : null;
        $this->assertLocationParent($warehouse, $parent, $data['type']);
        $code = strtoupper(trim($data['code']));
        $floor = (int) ($data['floor_level'] ?? $parent?->floor_level ?? 1);
        $path = $parent ? $parent->path.'/'.$code : $this->topLevelLocationPath($code, $floor);
        if ($parent && (int) $parent->floor_level !== $floor) {
            throw ValidationException::withMessages(['floor_level' => ['A child location must use the same level as its parent.']]);
        }

        return DB::transaction(function () use ($warehouse, $parent, $data, $code, $path, $floor) {
            if (WarehouseLocation::where('warehouse_id', $warehouse->id)->where('path', $path)->exists()) {
                throw ValidationException::withMessages(['code' => ['This location code is already used on the selected level.']]);
            }
            if ($floor > (int) $warehouse->floor_count) {
                $warehouse->update(['floor_count' => $floor]);
            }
            $location = WarehouseLocation::create([
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                'parent_id' => $parent?->id,
                'type' => $data['type'],
                'code' => $code,
                'name' => trim($data['name']),
                'path' => $path,
                'floor_level' => $floor,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            $this->layout->synchronizeLocation($location);

            return $location->fresh(['warehouse', 'section']);
        });
    }

    public function updateLocation(WarehouseLocation $location, array $data): WarehouseLocation
    {
        return DB::transaction(function () use ($location, $data) {
            $location = WarehouseLocation::query()->lockForUpdate()->findOrFail($location->id);
            $warehouse = Warehouse::query()->findOrFail($data['warehouse_id'] ?? $location->warehouse_id);
            if ((int) $warehouse->id !== (int) $location->warehouse_id
                && ($location->children()->exists() || $this->locationHasStock($location))) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['Move stock and child locations before moving this location to another warehouse.'],
                ]);
            }
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $location->parent_id;
            if ((int) $parentId === (int) $location->id) {
                throw ValidationException::withMessages(['parent_id' => ['A location cannot be its own parent.']]);
            }
            $parent = $parentId ? WarehouseLocation::query()->findOrFail($parentId) : null;
            $type = $data['type'] ?? $location->type;
            $this->assertLocationParent($warehouse, $parent, $type);
            $floor = (int) ($data['floor_level'] ?? $parent?->floor_level ?? $location->floor_level ?? 1);
            if ($parent && (int) $parent->floor_level !== $floor) {
                throw ValidationException::withMessages(['floor_level' => ['A child location must use the same level as its parent.']]);
            }
            $oldPath = $location->path;
            $code = isset($data['code']) ? strtoupper(trim($data['code'])) : $location->code;
            $newPath = $parent ? $parent->path.'/'.$code : $this->topLevelLocationPath($code, $floor);
            if ($parent && str_starts_with($parent->path.'/', $oldPath.'/')) {
                throw ValidationException::withMessages(['parent_id' => ['A location cannot be moved below one of its descendants.']]);
            }
            if (WarehouseLocation::where('warehouse_id', $warehouse->id)->where('path', $newPath)->whereKeyNot($location->id)->exists()) {
                throw ValidationException::withMessages(['code' => ['This location code is already used on the selected level.']]);
            }
            if ($floor > (int) $warehouse->floor_count) {
                $warehouse->update(['floor_count' => $floor]);
            }
            $location->update([
                'warehouse_id' => $warehouse->id,
                'parent_id' => $parent?->id,
                'type' => $type,
                'code' => $code,
                'name' => $data['name'] ?? $location->name,
                'path' => $newPath,
                'floor_level' => $floor,
                'is_active' => $data['is_active'] ?? $location->is_active,
                'sort_order' => $data['sort_order'] ?? $location->sort_order,
            ]);
            if ($oldPath !== $newPath) {
                WarehouseLocation::query()->where('path', 'like', $oldPath.'/%')->get()->each(function (WarehouseLocation $child) use ($oldPath, $newPath, $floor) {
                    $child->update(['path' => $newPath.substr($child->path, strlen($oldPath)), 'floor_level' => $floor]);
                    $this->layout->synchronizeLocation($child);
                });
                Product::where('company_id', $location->company_id)
                    ->where('location_code', $oldPath)
                    ->update(['location_code' => $newPath]);
            }

            $this->layout->synchronizeLocation($location);

            return $location->fresh(['warehouse', 'section']);
        });
    }

    public function deleteLocation(WarehouseLocation $location): void
    {
        DB::transaction(function () use ($location) {
            $location = WarehouseLocation::query()->lockForUpdate()->findOrFail($location->id);
            $location->loadMissing('section');
            $legacyCodes = array_filter([$location->path, $location->section?->code]);
            if ($location->children()->exists() || $this->locationHasStock($location)
                || Product::where('company_id', $location->company_id)->whereIn('location_code', $legacyCodes)->exists()
                || $this->locationHasHistory($location)) {
                throw ValidationException::withMessages([
                    'location' => [
                        'A stocked, assigned, child-containing, or historically referenced location cannot be deleted. '
                        .'Deactivate it after moving stock so prior receipts, sales, counts, returns, and transfers remain traceable.',
                    ],
                ]);
            }
            // Empty balance rows carry no inventory value. Removing them keeps
            // the bin identity key consistent and avoids merging several
            // deleted bins into one ambiguous legacy/unassigned row.
            WarehouseStock::withoutGlobalScopes()->where('location_id', $location->id)->delete();
            DB::table('inventory_trace_balances')->where('location_id', $location->id)->where('quantity', '<=', 0)->delete();
            $this->layout->deleteSectionForLocation($location);
            $location->delete();
        });
    }

    public function transfers(array $filters): LengthAwarePaginator
    {
        return StockTransfer::query()
            ->with(['sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code', 'items.product:id,name,sku,unit'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where(fn ($nested) => $nested->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId)))
            ->latest('id')->paginate($filters['per_page'] ?? 20);
    }

    public function findTransfer(StockTransfer $transfer): StockTransfer
    {
        return $transfer->load([
            'sourceWarehouse', 'destinationWarehouse', 'sourceLocation', 'destinationLocation',
            'items.product:id,name,sku,unit,quantity,default_warehouse_id',
        ]);
    }

    public function createTransfer(array $data): StockTransfer
    {
        return DB::transaction(function () use ($data) {
            $this->assertDifferentWarehouses((int) $data['source_warehouse_id'], (int) $data['destination_warehouse_id']);
            $this->assertTransferLocations($data);
            $companyId = (int) Auth::user()->company_id;
            $transfer = StockTransfer::create([
                'company_id' => $companyId,
                'transfer_number' => $this->nextTransferNumber($companyId),
                'source_warehouse_id' => $data['source_warehouse_id'],
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'source_location_id' => $data['source_location_id'] ?? null,
                'destination_location_id' => $data['destination_location_id'] ?? null,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $this->syncTransferItems($transfer, $data['items']);

            return $this->findTransfer($transfer->fresh());
        });
    }

    public function updateTransfer(StockTransfer $transfer, array $data): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data) {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only draft transfers can be edited.']]);
            }
            $source = (int) ($data['source_warehouse_id'] ?? $transfer->source_warehouse_id);
            $destination = (int) ($data['destination_warehouse_id'] ?? $transfer->destination_warehouse_id);
            $this->assertDifferentWarehouses($source, $destination);
            $this->assertTransferLocations([
                'source_warehouse_id' => $source,
                'destination_warehouse_id' => $destination,
                'source_location_id' => array_key_exists('source_location_id', $data) ? $data['source_location_id'] : $transfer->source_location_id,
                'destination_location_id' => array_key_exists('destination_location_id', $data) ? $data['destination_location_id'] : $transfer->destination_location_id,
            ]);
            $transfer->update([
                'source_warehouse_id' => $source,
                'destination_warehouse_id' => $destination,
                'source_location_id' => $data['source_location_id'] ?? $transfer->source_location_id,
                'destination_location_id' => $data['destination_location_id'] ?? $transfer->destination_location_id,
                'notes' => $data['notes'] ?? $transfer->notes,
            ]);
            if (isset($data['items'])) {
                $transfer->items()->delete();
                $this->syncTransferItems($transfer, $data['items']);
            }

            return $this->findTransfer($transfer->fresh());
        });
    }

    public function dispatch(StockTransfer $transfer, string $idempotencyKey): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $idempotencyKey) {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status === 'in_transit' && $transfer->dispatch_idempotency_key === $idempotencyKey) {
                return $this->findTransfer($transfer);
            }
            if ($transfer->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only a draft transfer can be dispatched.']]);
            }
            $transfer->load('items.product');
            foreach ($transfer->items as $item) {
                $dispatchPlan = $this->dispatchPlan($transfer, $item);
                $this->movements->storeOutboundAllocated([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $transfer->source_warehouse_id,
                    'location_id' => $dispatchPlan['location_id'],
                    'location_allocations' => $dispatchPlan['location_allocations'],
                    'source_warehouse_id' => $transfer->source_warehouse_id,
                    'destination_warehouse_id' => $transfer->destination_warehouse_id,
                    'type' => 'out',
                    'quantity' => (float) $item->quantity,
                    'stock_state' => 'available',
                    'affects_company_quantity' => false,
                    'movement_code' => 'transfer_out',
                    'source_type' => 'stock_transfer',
                    'source_id' => $transfer->id,
                    'reason' => "Dispatched {$transfer->transfer_number}",
                    'idempotency_key' => $idempotencyKey.'-out-'.$item->id,
                    'trace_allocations' => $dispatchPlan['trace_allocations'],
                    'metadata' => ['stock_transfer_item_id' => $item->id],
                ]);
            }
            $transfer->update([
                'status' => 'in_transit',
                'dispatched_at' => now(),
                'dispatched_by' => Auth::id(),
                'dispatch_idempotency_key' => $idempotencyKey,
            ]);
            $this->assertTransferIntegrity($transfer);

            return $this->findTransfer($transfer->fresh());
        });
    }

    public function receive(StockTransfer $transfer, array $data): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data) {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $payloadHash = hash('sha256', json_encode($data['items'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            $existingReceipt = DB::table('stock_transfer_receipts')
                ->where('company_id', $transfer->company_id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existingReceipt) {
                if ((int) $existingReceipt->stock_transfer_id !== (int) $transfer->id || ! hash_equals($existingReceipt->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was already used for a different transfer receipt.']]);
                }

                return $this->findTransfer($transfer);
            }
            if (! in_array($transfer->status, ['in_transit', 'partially_received'], true)) {
                throw ValidationException::withMessages(['status' => ['Only dispatched stock can be received.']]);
            }
            $transfer->load('items.product');
            foreach ($data['items'] as $input) {
                $item = $transfer->items->firstWhere('id', (int) $input['id']);
                if (! $item) {
                    throw ValidationException::withMessages(['items' => ['A received item does not belong to this transfer.']]);
                }
                $accepted = round((float) ($input['accepted_quantity'] ?? 0), 3);
                $damaged = round((float) ($input['damaged_quantity'] ?? 0), 3);
                $remaining = round((float) $item->quantity - (float) $item->received_quantity - (float) $item->damaged_quantity, 3);
                if ($accepted < 0 || $damaged < 0 || $accepted + $damaged <= 0 || $accepted + $damaged > $remaining + 0.0005) {
                    throw ValidationException::withMessages(['items' => ["Invalid received quantity for {$item->product->name}."]]);
                }
                $remainingTrace = $this->remainingTransferTrace($transfer, $item);
                foreach ([['quantity' => $accepted, 'state' => 'available'], ['quantity' => $damaged, 'state' => 'damaged']] as $portion) {
                    if ($portion['quantity'] <= 0) {
                        continue;
                    }
                    $this->movements->store([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $transfer->destination_warehouse_id,
                        'location_id' => $transfer->destination_location_id,
                        'source_warehouse_id' => $transfer->source_warehouse_id,
                        'destination_warehouse_id' => $transfer->destination_warehouse_id,
                        'type' => 'in',
                        'quantity' => $portion['quantity'],
                        'stock_state' => $portion['state'],
                        'affects_company_quantity' => false,
                        'movement_code' => 'transfer_in',
                        'source_type' => 'stock_transfer',
                        'source_id' => $transfer->id,
                        'reason' => "Received {$transfer->transfer_number}".($portion['state'] === 'damaged' ? ' as damaged' : ''),
                        'idempotency_key' => $data['idempotency_key'].'-'.$portion['state'].'-'.$item->id,
                        'trace_allocations' => $this->takeTransferTrace($item, $remainingTrace, (float) $portion['quantity']),
                        'metadata' => ['stock_transfer_item_id' => $item->id],
                    ]);
                }
                $item->update([
                    'received_quantity' => round((float) $item->received_quantity + $accepted, 3),
                    'damaged_quantity' => round((float) $item->damaged_quantity + $damaged, 3),
                ]);
            }
            $complete = $transfer->items()->get()->every(fn ($item) => (float) $item->received_quantity + (float) $item->damaged_quantity + 0.0005 >= (float) $item->quantity);
            $transfer->update([
                'status' => $complete ? 'received' : 'partially_received',
                'received_at' => $complete ? now() : null,
                'received_by' => Auth::id(),
                'receive_idempotency_key' => $data['idempotency_key'],
            ]);
            DB::table('stock_transfer_receipts')->insert([
                'company_id' => $transfer->company_id,
                'stock_transfer_id' => $transfer->id,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $payloadHash,
                'received_by' => Auth::id(),
                'received_at' => now(),
            ]);
            $this->assertTransferIntegrity($transfer);

            return $this->findTransfer($transfer->fresh());
        });
    }

    public function cancel(StockTransfer $transfer, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $reason) {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status === 'received' || $transfer->status === 'partially_received') {
                throw ValidationException::withMessages(['status' => ['A received transfer cannot be cancelled. Create a return transfer instead.']]);
            }
            if ($transfer->status === 'cancelled') {
                return $this->findTransfer($transfer);
            }
            if ($transfer->status === 'in_transit') {
                $transfer->load('items.product');
                foreach ($transfer->items as $item) {
                    foreach ($this->transferOutMovements($transfer, $item) as $outbound) {
                        $this->movements->store([
                            'product_id' => $item->product_id,
                            'warehouse_id' => $transfer->source_warehouse_id,
                            'location_id' => $outbound->location_id,
                            'source_warehouse_id' => $transfer->destination_warehouse_id,
                            'destination_warehouse_id' => $transfer->source_warehouse_id,
                            'type' => 'in',
                            'quantity' => (float) $outbound->quantity,
                            'stock_state' => 'available',
                            'affects_company_quantity' => false,
                            'movement_code' => 'transfer_return',
                            'source_type' => 'stock_transfer',
                            'source_id' => $transfer->id,
                            'reason' => "Cancelled {$transfer->transfer_number}: {$reason}",
                            'idempotency_key' => 'transfer-cancel-'.$transfer->id.'-'.$item->id.'-'.$outbound->id,
                            'trace_allocations' => $outbound->traceLines->map(fn ($line) => [
                                'inventory_lot_id' => (int) $line->inventory_lot_id,
                                'quantity' => (float) $line->quantity,
                            ])->values()->all(),
                            'metadata' => [
                                'stock_transfer_item_id' => $item->id,
                                'reverses_stock_movement_id' => $outbound->id,
                            ],
                        ]);
                    }
                }
            }
            $transfer->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => Auth::id(), 'cancellation_reason' => $reason]);
            $this->assertTransferIntegrity($transfer);

            return $this->findTransfer($transfer->fresh());
        });
    }

    private function syncTransferItems(StockTransfer $transfer, array $items): void
    {
        foreach ($items as $input) {
            $product = Product::query()->findOrFail($input['product_id']);
            if (($product->lifecycle_status ?? 'active') === 'archived') {
                throw ValidationException::withMessages([
                    'items' => ["Archived product {$product->name} cannot be added to a warehouse transfer."],
                ]);
            }
            $quantity = round((float) $input['quantity'], 3);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => ['Every transfer quantity must be greater than zero.']]);
            }
            $this->units->assertPrecision($quantity, $product->unit, 'items');
            $transfer->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'picked_quantity' => 0,
                'received_quantity' => 0,
                'damaged_quantity' => 0,
                'unit_snapshot' => $product->unit ?: 'pcs',
                'trace_allocations' => $input['trace_allocations'] ?? null,
                'notes' => $input['notes'] ?? null,
            ]);
        }
    }

    /**
     * Preserve scanned multi-bin picks when they exist. A normal browser
     * dispatch without pick events remains eligible for automatic allocation.
     */
    private function dispatchPlan(StockTransfer $transfer, StockTransferItem $item): array
    {
        $events = DB::table('stock_transfer_pick_events')
            ->where('company_id', $transfer->company_id)
            ->where('stock_transfer_id', $transfer->id)
            ->where('stock_transfer_item_id', $item->id)
            ->orderBy('id')
            ->get();
        if ($events->isEmpty()) {
            return [
                'location_id' => $transfer->source_location_id,
                'location_allocations' => [],
                'trace_allocations' => $item->trace_allocations ?? [],
            ];
        }

        $picked = round((float) $events->sum(fn ($event) => (float) $event->quantity), 3);
        if (abs($picked - (float) $item->quantity) >= 0.0005) {
            throw ValidationException::withMessages([
                'items' => ["{$item->product->name} has only {$picked} of {$item->quantity} picked. Complete the pick before dispatch."],
            ]);
        }

        $locationAllocations = $events->groupBy('location_id')->map(fn ($rows, $locationId) => [
            'location_id' => (int) $locationId,
            'quantity' => round((float) $rows->sum(fn ($event) => (float) $event->quantity), 3),
        ])->values()->all();
        $traceAllocations = $events->flatMap(function ($event): array {
            $trace = is_string($event->trace_allocations)
                ? json_decode($event->trace_allocations, true)
                : $event->trace_allocations;

            return collect(is_array($trace) ? $trace : [])->map(fn (array $allocation) => [
                ...$allocation,
                'location_id' => (int) $event->location_id,
            ])->all();
        })->values()->all();

        return [
            'location_id' => null,
            'location_allocations' => $locationAllocations,
            'trace_allocations' => $traceAllocations ?: ($item->trace_allocations ?? []),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, StockMovement> */
    private function transferOutMovements(StockTransfer $transfer, StockTransferItem $item): \Illuminate\Database\Eloquent\Collection
    {
        $movements = StockMovement::withoutGlobalScopes()
            ->with('traceLines')
            ->where('company_id', $transfer->company_id)
            ->where('source_type', 'stock_transfer')
            ->where('source_id', $transfer->id)
            ->where('movement_code', 'transfer_out')
            ->where('product_id', $item->product_id)
            ->orderBy('id')
            ->get();
        $itemMovements = $movements->filter(fn (StockMovement $movement) =>
            (int) ($movement->metadata['stock_transfer_item_id'] ?? 0) === (int) $item->id
        )->values();

        // Backward compatibility for transfers dispatched before movement
        // metadata identified the line. Transfer requests already enforce one
        // line per product, so the product fallback is unambiguous.
        return $itemMovements->isNotEmpty() ? $itemMovements : $movements;
    }

    private function dispatchedTrace(StockTransfer $transfer, StockTransferItem $item): array
    {
        return $this->transferOutMovements($transfer, $item)
            ->flatMap(fn ($movement) => $movement->traceLines)
            ->groupBy('inventory_lot_id')
            ->map(fn ($lines, $lotId) => [
                'inventory_lot_id' => (int) $lotId,
                'quantity' => round((float) $lines->sum('quantity'), 3),
            ])->values()->all();
    }

    private function remainingTransferTrace(StockTransfer $transfer, StockTransferItem $item): array
    {
        if (($item->product->tracking_mode ?: 'none') === 'none') {
            return [];
        }
        $dispatched = collect($this->dispatchedTrace($transfer, $item))->keyBy('inventory_lot_id');
        $received = StockMovement::withoutGlobalScopes()
            ->with('traceLines')
            ->where('company_id', $transfer->company_id)
            ->where('source_type', 'stock_transfer')
            ->where('source_id', $transfer->id)
            ->where('movement_code', 'transfer_in')
            ->where('product_id', $item->product_id)
            ->get()
            ->filter(fn (StockMovement $movement) => (int) ($movement->metadata['stock_transfer_item_id'] ?? $item->id) === (int) $item->id)
            ->flatMap(fn ($movement) => $movement->traceLines)
            ->groupBy('inventory_lot_id')
            ->map(fn ($lines) => round((float) $lines->sum('quantity'), 3));

        return $dispatched->map(function (array $allocation, $lotId) use ($received): array {
            $allocation['quantity'] = round((float) $allocation['quantity'] - (float) ($received[$lotId] ?? 0), 3);

            return $allocation;
        })->filter(fn (array $allocation) => $allocation['quantity'] >= 0.0005)->values()->all();
    }

    private function takeTransferTrace(StockTransferItem $item, array &$remaining, float $quantity): array
    {
        if (($item->product->tracking_mode ?: 'none') === 'none') {
            return [];
        }
        $needed = round($quantity, 3);
        $taken = [];
        foreach ($remaining as &$allocation) {
            if ($needed < 0.0005) {
                break;
            }
            $take = round(min($needed, (float) $allocation['quantity']), 3);
            if ($take > 0) {
                $taken[] = ['inventory_lot_id' => $allocation['inventory_lot_id'], 'quantity' => $take];
                $allocation['quantity'] = round((float) $allocation['quantity'] - $take, 3);
                $needed = round($needed - $take, 3);
            }
        }
        unset($allocation);
        if ($needed >= 0.0005) {
            throw ValidationException::withMessages([
                'items' => ["Trace allocations for {$item->product->name} do not cover the received quantity."],
            ]);
        }

        return $taken;
    }

    private function assertDifferentWarehouses(int $source, int $destination): void
    {
        if ($source === $destination) {
            throw ValidationException::withMessages(['destination_warehouse_id' => ['Source and destination warehouses must be different.']]);
        }
    }

    private function assertTransferLocations(array $data): void
    {
        foreach ([
            'source_location_id' => 'source_warehouse_id',
            'destination_location_id' => 'destination_warehouse_id',
        ] as $locationField => $warehouseField) {
            if (empty($data[$locationField])) {
                continue;
            }
            $belongsToWarehouse = WarehouseLocation::query()
                ->whereKey($data[$locationField])
                ->where('warehouse_id', $data[$warehouseField])
                ->where('is_active', true)
                ->exists();
            if (! $belongsToWarehouse) {
                throw ValidationException::withMessages([
                    $locationField => ['The selected location must be active and belong to its selected warehouse.'],
                ]);
            }
        }
    }

    private function assertTransferIntegrity(StockTransfer $transfer): void
    {
        $productIds = $transfer->items()->pluck('product_id')->unique();
        Product::withoutGlobalScopes()
            ->where('company_id', $transfer->company_id)
            ->whereIn('id', $productIds)
            ->get()
            ->each(fn (Product $product) => $this->integrity->assertProductReconciled($product));
    }

    private function assertLocationParent(Warehouse $warehouse, ?WarehouseLocation $parent, string $type): void
    {
        $allowedParent = ['zone' => null, 'rack' => 'zone', 'shelf' => 'rack', 'bin' => 'shelf'];
        if (! array_key_exists($type, $allowedParent)) {
            throw ValidationException::withMessages(['type' => ['Location type must be zone, rack, shelf, or bin.']]);
        }
        if ((int) ($parent?->warehouse_id ?? $warehouse->id) !== (int) $warehouse->id || $parent?->type !== $allowedParent[$type]) {
            $message = $type === 'zone' ? 'A zone cannot have a parent.' : "A {$type} must be placed inside a {$allowedParent[$type]}.";
            throw ValidationException::withMessages(['parent_id' => [$message]]);
        }
    }

    private function nextTransferNumber(int $companyId): string
    {
        $prefix = 'TR-'.now('Europe/Tirane')->format('Y').'-';
        $sequence = StockTransfer::withoutGlobalScopes()->where('company_id', $companyId)->where('transfer_number', 'like', $prefix.'%')->count() + 1;
        do {
            $number = $prefix.str_pad((string) $sequence++, 5, '0', STR_PAD_LEFT);
        } while (StockTransfer::withoutGlobalScopes()->where('company_id', $companyId)->where('transfer_number', $number)->exists());

        return $number;
    }

    private function topLevelLocationPath(string $code, int $floor): string
    {
        return $floor > 1 ? 'L'.$floor.'-'.$code : $code;
    }

    private function locationHasStock(WarehouseLocation $location): bool
    {
        return WarehouseStock::withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where(function ($query): void {
                foreach (['quantity', 'available_quantity', 'reserved_quantity', 'damaged_quantity', 'quarantine_quantity', 'blocked_quantity'] as $index => $column) {
                    $index === 0 ? $query->where($column, '!=', 0) : $query->orWhere($column, '!=', 0);
                }
            })->exists();
    }

    private function locationHasHistory(WarehouseLocation $location): bool
    {
        return DB::table('stock_movements')->where('location_id', $location->id)->exists()
            || DB::table('goods_receipts')->where('location_id', $location->id)->exists()
            || DB::table('inventory_trace_balances')->where('location_id', $location->id)->exists()
            || DB::table('stock_transfers')->where(function ($query) use ($location): void {
                $query->where('source_location_id', $location->id)
                    ->orWhere('destination_location_id', $location->id);
            })->exists()
            || DB::table('inventory_count_sessions')->where('location_id', $location->id)->exists()
            || DB::table('inventory_count_items')->where('location_id', $location->id)->exists()
            || DB::table('inventory_return_items')->where('location_id', $location->id)->exists()
            || DB::table('daily_sale_items')->where('location_id', $location->id)->exists()
            || DB::table('invoice_items')->where('location_id', $location->id)->exists()
            || DB::table('stock_transfer_pick_events')->where('location_id', $location->id)->exists();
    }
}
