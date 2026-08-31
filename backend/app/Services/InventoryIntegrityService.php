<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compares the three inventory balance sources without repairing any of them.
 *
 * Product.quantity is the company-owned quantity. WarehouseStock.quantity is
 * physical stock currently held in warehouses, while dispatched-but-unreceived
 * transfers remain company owned until they reach the destination warehouse.
 * Every warehouse row must equal the sum of its mutually exclusive states.
 */
class InventoryIntegrityService
{
    private const EPSILON = 0.0005;

    /**
     * @return array{
     *   product_quantity: float,
     *   warehouse_on_hand: float,
     *   warehouse_state_total: float,
     *   trace_total: float,
     *   in_transit: float,
     *   expected_company_quantity: float,
     *   issues: array<int, string>
     * }
     */
    public function productSnapshot(Product $product, bool $lock = false): array
    {
        $product = Product::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->findOrFail($product->id);

        $stockQuery = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->orderBy('id');
        if ($lock) {
            $stockQuery->lockForUpdate();
        }
        $stockRows = $stockQuery->get();
        $warehouseOnHand = $this->round($stockRows->sum('quantity'));
        $warehouseStateTotal = $this->round($stockRows->sum(
            fn (WarehouseStock $row) => $this->rowStateTotal($row)
        ));

        $traceQuery = DB::table('inventory_trace_balances as trace')
            ->join('inventory_lots as lot', 'lot.id', '=', 'trace.inventory_lot_id')
            ->where('trace.company_id', $product->company_id)
            ->where('lot.product_id', $product->id)
            ->select([
                'trace.id', 'trace.warehouse_id', 'trace.location_key',
                'trace.stock_state', 'trace.quantity',
            ])
            ->orderBy('trace.id');
        if ($lock) {
            $traceQuery->lockForUpdate();
        }
        $traceRows = $traceQuery->get();
        $traceTotal = $this->round($traceRows->sum('quantity'));

        $inTransitQuery = DB::table('stock_transfer_items as item')
            ->join('stock_transfers as transfer', 'transfer.id', '=', 'item.stock_transfer_id')
            ->where('transfer.company_id', $product->company_id)
            ->where('item.product_id', $product->id)
            ->whereIn('transfer.status', ['in_transit', 'partially_received'])
            ->select(['item.id', 'item.quantity', 'item.received_quantity', 'item.damaged_quantity'])
            ->orderBy('item.id');
        // Do not lock transfer rows here. Transfer dispatch/receipt holds the
        // transfer first and then locks the product; taking the inverse lock
        // order would deadlock. Once the product is locked, a transfer cannot
        // commit a stock mutation for this product, so a normal consistent
        // read is sufficient for the in-transit component.
        $inTransit = $this->round($inTransitQuery->get()->sum(
            fn ($item) => max(0, $this->round(
                (float) $item->quantity
                - (float) $item->received_quantity
                - (float) $item->damaged_quantity
            ))
        ));

        $productQuantity = $this->round($product->quantity);
        $expectedCompanyQuantity = $this->round($warehouseOnHand + $inTransit);
        $issues = [];

        foreach ($stockRows as $row) {
            $stateTotal = $this->rowStateTotal($row);
            if ($this->different((float) $row->quantity, $stateTotal)) {
                $issues[] = "Warehouse balance #{$row->id} on-hand does not equal its stock-state total.";
            }
            foreach (WarehouseInventoryService::STATES as $state) {
                if ((float) $row->{$state.'_quantity'} < -self::EPSILON) {
                    $issues[] = "Warehouse balance #{$row->id} has a negative {$state} quantity.";
                }
            }
            if ((float) $row->quantity < -self::EPSILON) {
                $issues[] = "Warehouse balance #{$row->id} has negative physical on-hand stock.";
            }
        }
        if ($productQuantity < -self::EPSILON) {
            $issues[] = 'The product company quantity is negative.';
        }
        if ($this->different($productQuantity, $expectedCompanyQuantity)) {
            $issues[] = 'Product quantity does not equal warehouse on-hand plus stock currently in transit.';
        }

        $trackingMode = $product->tracking_mode ?: 'none';
        if ($trackingMode === 'none') {
            if (abs($traceTotal) >= self::EPSILON) {
                $issues[] = 'An untracked product has a non-zero lot/serial trace balance.';
            }
        } else {
            $warehouseByState = $this->warehouseStateMap($stockRows);
            $traceByState = $traceRows->groupBy(fn ($row) => $this->balanceKey(
                (int) $row->warehouse_id,
                (int) $row->location_key,
                (string) $row->stock_state,
            ))->map(fn (Collection $rows) => $this->round($rows->sum('quantity')));

            foreach ($warehouseByState->keys()->merge($traceByState->keys())->unique() as $key) {
                if ($this->different(
                    (float) ($warehouseByState->get($key) ?? 0),
                    (float) ($traceByState->get($key) ?? 0),
                )) {
                    $issues[] = "Lot/serial trace stock does not reconcile with warehouse state {$key}.";
                }
            }
        }

        return [
            'product_quantity' => $productQuantity,
            'warehouse_on_hand' => $warehouseOnHand,
            'warehouse_state_total' => $warehouseStateTotal,
            'trace_total' => $traceTotal,
            'in_transit' => $inTransit,
            'expected_company_quantity' => $expectedCompanyQuantity,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    public function assertProductReconciled(Product $product, string $field = 'inventory'): array
    {
        $snapshot = $this->productSnapshot($product, true);
        if ($snapshot['issues'] !== []) {
            throw ValidationException::withMessages([
                $field => [
                    'Inventory sources disagree for this product. Reconciliation is required before continuing. '
                    .implode(' ', $snapshot['issues']),
                ],
            ]);
        }

        return $snapshot;
    }

    public function assertProductCanArchive(Product $product, string $field = 'product'): void
    {
        $snapshot = $this->assertProductReconciled($product, $field);
        if ($snapshot['product_quantity'] > self::EPSILON
            || $snapshot['warehouse_on_hand'] > self::EPSILON
            || $snapshot['trace_total'] > self::EPSILON
            || $snapshot['in_transit'] > self::EPSILON) {
            throw ValidationException::withMessages([
                $field => [
                    'A product with warehouse, lot/serial, or in-transit stock cannot be archived or deleted. '
                    .'Transfer, return, sell, or count its inventory to zero first.',
                ],
            ]);
        }
    }

    /**
     * Warehouse deletion is blocked when warehouse rows and trace rows do not
     * agree, even when a corrupted generic quantity happens to be zero.
     */
    public function assertWarehouseReconciled(Warehouse $warehouse): void
    {
        // Lock products before their warehouse/trace balances. Stock writes use
        // this same lock order, which avoids a warehouse deletion deadlocking
        // against a simultaneous sale, receipt, transfer, or adjustment.
        $productIds = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $warehouse->company_id)
            ->where('warehouse_id', $warehouse->id)
            ->pluck('product_id')
            ->merge(
                DB::table('inventory_trace_balances as trace')
                    ->join('inventory_lots as lot', 'lot.id', '=', 'trace.inventory_lot_id')
                    ->where('trace.company_id', $warehouse->company_id)
                    ->where('trace.warehouse_id', $warehouse->id)
                    ->pluck('lot.product_id')
            )
            ->unique()
            ->sort()
            ->values();

        $products = Product::withoutGlobalScopes()
            ->where('company_id', $warehouse->company_id)
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $products->each(fn (Product $product) => $this->assertProductReconciled($product, 'warehouse'));

        $stockRows = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $warehouse->company_id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->get();
        $issues = [];
        foreach ($stockRows as $row) {
            if ($this->different((float) $row->quantity, $this->rowStateTotal($row))) {
                $issues[] = "Warehouse balance #{$row->id} does not equal its state total.";
            }
        }

        // The portable schema intentionally has no product_id on trace rows;
        // build the comparison with the lot relation in a separate query.
        $traceRows = DB::table('inventory_trace_balances as trace')
            ->join('inventory_lots as lot', 'lot.id', '=', 'trace.inventory_lot_id')
            ->where('trace.company_id', $warehouse->company_id)
            ->where('trace.warehouse_id', $warehouse->id)
            ->select([
                'lot.product_id', 'trace.location_key', 'trace.stock_state', 'trace.quantity',
            ])
            ->lockForUpdate()
            ->get();
        $trackedModes = $products->pluck('tracking_mode', 'id');

        foreach ($productIds as $productId) {
            $productStock = $stockRows->where('product_id', $productId);
            $productTrace = $traceRows->where('product_id', $productId);
            if (($trackedModes[$productId] ?? 'none') === 'none') {
                if (abs((float) $productTrace->sum('quantity')) >= self::EPSILON) {
                    $issues[] = "Untracked product #{$productId} has a trace balance in this warehouse.";
                }
                continue;
            }
            $stockMap = $this->warehouseStateMap($productStock);
            $traceMap = $productTrace->groupBy(fn ($row) => $this->balanceKey(
                (int) $warehouse->id,
                (int) $row->location_key,
                (string) $row->stock_state,
            ))->map(fn (Collection $rows) => $this->round($rows->sum('quantity')));
            foreach ($stockMap->keys()->merge($traceMap->keys())->unique() as $key) {
                if ($this->different((float) ($stockMap->get($key) ?? 0), (float) ($traceMap->get($key) ?? 0))) {
                    $issues[] = "Tracked product #{$productId} disagrees at {$key}.";
                }
            }
        }

        if ($issues !== []) {
            throw ValidationException::withMessages([
                'warehouse' => [
                    'Warehouse and trace balances disagree. Reconciliation is required before deletion. '
                    .implode(' ', array_unique($issues)),
                ],
            ]);
        }
    }

    private function warehouseStateMap(Collection $stockRows): Collection
    {
        $map = collect();
        foreach ($stockRows as $row) {
            foreach (WarehouseInventoryService::STATES as $state) {
                $map->put(
                    $this->balanceKey((int) $row->warehouse_id, (int) $row->location_key, $state),
                    $this->round($row->{$state.'_quantity'}),
                );
            }
        }

        return $map;
    }

    private function rowStateTotal(WarehouseStock $row): float
    {
        return $this->round(collect(WarehouseInventoryService::STATES)->sum(
            fn (string $state) => (float) $row->{$state.'_quantity'}
        ));
    }

    private function balanceKey(int $warehouseId, int $locationKey, string $state): string
    {
        return "warehouse:{$warehouseId}/location:{$locationKey}/state:{$state}";
    }

    private function round(mixed $quantity): float
    {
        return round((float) $quantity, 3);
    }

    private function different(float $left, float $right): bool
    {
        return abs($left - $right) >= self::EPSILON;
    }
}
