<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseStock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class WarehouseInventoryService
{
    public const STATES = ['available', 'reserved', 'damaged', 'quarantine', 'blocked'];

    public function __construct(private readonly WarehouseLayoutService $layout) {}

    public function resolveWarehouse(Product $product, ?int $warehouseId, string $type, float $quantity, string $state = 'available'): Warehouse
    {
        if ($warehouseId) {
            $warehouse = Warehouse::query()->whereKey($warehouseId)->first();
            if (! $warehouse || (int) $warehouse->company_id !== (int) $product->company_id || ! $warehouse->is_active) {
                throw ValidationException::withMessages(['warehouse_id' => ['Select an active warehouse owned by this company.']]);
            }

            return $warehouse;
        }

        if ($type === 'out') {
            $column = $this->stateColumn($state);
            $totals = WarehouseStock::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->where('product_id', $product->id)
                ->get()
                ->groupBy('warehouse_id')
                ->map(fn ($rows) => round((float) $rows->sum($column), 3));
            $eligible = $totals->filter(fn (float $total) => $total + 0.0005 >= $quantity);
            $warehouseId = $product->default_warehouse_id && $eligible->has($product->default_warehouse_id)
                ? (int) $product->default_warehouse_id
                : (int) ($eligible->sortDesc()->keys()->first() ?? 0);
            if ($warehouseId) {
                return Warehouse::withoutGlobalScopes()
                    ->where('company_id', $product->company_id)
                    ->where('is_active', true)
                    ->findOrFail($warehouseId);
            }
        }

        $warehouse = $product->default_warehouse_id
            ? Warehouse::withoutGlobalScopes()->where('company_id', $product->company_id)->whereKey($product->default_warehouse_id)->where('is_active', true)->first()
            : null;
        $warehouse ??= $this->layout->getOrCreatePrimaryWarehouse((int) $product->company_id);
        if (! $product->default_warehouse_id) {
            $product->update(['default_warehouse_id' => $warehouse->id]);
        }

        return $warehouse;
    }

    /**
     * Resolve a concrete bin for an outbound operation. Legacy callers that
     * omit a bin use the unassigned balance first and then one bin that can
     * satisfy the whole movement, so one ledger row always maps to one place.
     */
    public function resolveLocationId(
        Product $product,
        Warehouse $warehouse,
        string $type,
        float $quantity,
        string $state,
        ?int $locationId,
    ): ?int {
        if ($locationId !== null) {
            $this->assertLocation($product, $warehouse, $locationId);

            return $locationId;
        }
        if ($type !== 'out') {
            return null;
        }

        $column = $this->stateColumn($state);
        $balance = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where($column, '>=', $quantity)
            ->orderByRaw('CASE WHEN location_id IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc($column)
            ->first();
        if (! $balance) {
            $total = round((float) WarehouseStock::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->sum($column), 3);
            if ($total + 0.0005 >= $quantity) {
                throw ValidationException::withMessages([
                    'location_id' => ["Stock is split across bins. Select a specific source bin and move no more than its {$state} balance."],
                ]);
            }
            throw ValidationException::withMessages([
                'quantity' => ["Insufficient {$state} stock in {$warehouse->name}. Only {$total} {$product->unit} are available."],
            ]);
        }

        return $balance->location_id ? (int) $balance->location_id : null;
    }

    /**
     * Build a locked, location-specific outbound plan. A logical operation may
     * consume several bins, but every resulting ledger row must still point at
     * exactly one physical balance.
     *
     * @return array<int, array{location_id: int|null, quantity: float}>
     */
    public function outboundLocationAllocations(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        string $state = 'available',
        ?int $locationId = null,
    ): array {
        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
        }

        $column = $this->stateColumn($state);

        // Preserve the established legacy behaviour: an old Product.quantity
        // balance is seeded as unassigned stock, never silently assigned to a
        // caller-selected bin.
        $this->ensureBalance($product, $warehouse, null);
        if ($locationId !== null) {
            $this->assertLocation($product, $warehouse, $locationId);
        }

        $balances = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->when($locationId !== null, fn ($query) => $query->where('location_key', $locationId))
            ->where($column, '>', 0)
            ->orderByRaw('CASE WHEN location_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('location_key')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $allocations = [];
        foreach ($balances as $balance) {
            if ($remaining < 0.0005) {
                break;
            }
            $take = round(min($remaining, (float) $balance->{$column}), 3);
            if ($take <= 0) {
                continue;
            }
            $allocations[] = [
                'location_id' => $balance->location_id ? (int) $balance->location_id : null,
                'quantity' => $take,
            ];
            $remaining = round($remaining - $take, 3);
        }

        if ($remaining >= 0.0005) {
            $available = round($quantity - $remaining, 3);
            $place = $locationId === null ? $warehouse->name : $warehouse->name.' / selected bin';
            throw ValidationException::withMessages([
                'quantity' => ["Insufficient {$state} stock in {$place}. Only {$available} {$product->unit} are available."],
            ]);
        }

        return $allocations;
    }

    public function ensureBalance(Product $product, Warehouse|int|null $warehouse = null, ?int $locationId = null): WarehouseStock
    {
        $resolved = $warehouse instanceof Warehouse
            ? $warehouse
            : $this->resolveWarehouse($product, $warehouse, 'in', 0, 'available');
        if ($locationId !== null) {
            $this->assertLocation($product, $resolved, $locationId);
        }

        $hasAnyBalance = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->exists();
        $openingBalance = ! $hasAnyBalance ? round((float) $product->quantity, 3) : 0;

        return WarehouseStock::withoutGlobalScopes()->firstOrCreate(
            [
                'warehouse_id' => $resolved->id,
                'product_id' => $product->id,
                'location_key' => (int) ($locationId ?? 0),
            ],
            [
                'company_id' => $product->company_id,
                'location_id' => $locationId,
                'quantity' => $openingBalance,
                'available_quantity' => $openingBalance,
                'reserved_quantity' => 0,
                'damaged_quantity' => 0,
                'quarantine_quantity' => 0,
                'blocked_quantity' => 0,
            ],
        );
    }

    public function mutate(
        Product $product,
        Warehouse $warehouse,
        string $type,
        float $quantity,
        string $state = 'available',
        ?int $locationId = null,
    ): array {
        if (! in_array($type, ['in', 'out'], true)) {
            throw ValidationException::withMessages(['type' => ['Inventory movement type must be in or out.']]);
        }
        if (! in_array($state, self::STATES, true)) {
            throw ValidationException::withMessages(['stock_state' => ['The selected inventory state is invalid.']]);
        }
        if ($locationId !== null) {
            $this->assertLocation($product, $warehouse, $locationId);
        }

        $this->ensureBalance($product, $warehouse, $locationId);
        $balances = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->get();
        $locationKey = (int) ($locationId ?? 0);
        $balance = $balances->firstWhere('location_key', $locationKey);
        if (! $balance) {
            throw ValidationException::withMessages(['location_id' => ['The inventory balance for this location could not be established.']]);
        }

        $column = $this->stateColumn($state);
        $warehouseBefore = round((float) $balances->sum($column), 3);
        $locationBefore = round((float) $balance->{$column}, 3);
        if ($type === 'out' && $locationBefore + 0.0005 < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => ["Insufficient {$state} stock in {$warehouse->name}".($balance->location?->path ? " / {$balance->location->path}" : '').". Only {$locationBefore} {$product->unit} are available."],
            ]);
        }
        $locationAfter = round($type === 'in' ? $locationBefore + $quantity : $locationBefore - $quantity, 3);
        $balance->{$column} = $locationAfter;
        $balance->quantity = round(collect(self::STATES)->sum(
            fn (string $itemState) => (float) ($itemState === $state ? $locationAfter : $balance->{$this->stateColumn($itemState)})
        ), 3);
        $balance->save();
        $warehouseAfter = round($type === 'in' ? $warehouseBefore + $quantity : $warehouseBefore - $quantity, 3);

        return [
            'balance' => $balance->fresh(['warehouse', 'location']),
            'before' => $warehouseBefore,
            'after' => $warehouseAfter,
            'warehouse_before' => $warehouseBefore,
            'warehouse_after' => $warehouseAfter,
            'location_before' => $locationBefore,
            'location_after' => $locationAfter,
        ];
    }

    public function balances(Product $product, ?int $warehouseId = null, ?int $locationId = null): Collection
    {
        return WarehouseStock::query()
            ->with(['warehouse:id,name,code', 'location:id,warehouse_id,name,code,path,type,floor_level'])
            ->where('product_id', $product->id)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->orderBy('warehouse_id')
            ->orderByRaw('CASE WHEN location_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('location_id')
            ->get();
    }

    public function stateColumn(string $state): string
    {
        if (! in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException("Unsupported inventory state: {$state}");
        }

        return $state.'_quantity';
    }

    private function assertLocation(Product $product, Warehouse $warehouse, int $locationId): void
    {
        $valid = WarehouseLocation::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('warehouse_id', $warehouse->id)
            ->whereKey($locationId)
            ->where('is_active', true)
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['location_id' => ['The selected location does not belong to the warehouse.']]);
        }
    }
}
