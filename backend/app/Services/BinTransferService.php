<?php

namespace App\Services;

use App\Models\Product;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BinTransferService
{
    public function __construct(
        private readonly StockMovementService $movements,
        private readonly InventoryIntegrityService $integrity,
    ) {}

    public function move(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $source = WarehouseLocation::query()->where('is_active', true)->findOrFail($data['source_location_id']);
            $destination = WarehouseLocation::query()->where('is_active', true)->findOrFail($data['destination_location_id']);
            if ((int) $source->warehouse_id !== (int) $destination->warehouse_id) {
                throw ValidationException::withMessages([
                    'destination_location_id' => ['Use a warehouse transfer when source and destination are in different warehouses.'],
                ]);
            }
            if ((int) $source->id === (int) $destination->id) {
                throw ValidationException::withMessages(['destination_location_id' => ['Source and destination bins must be different.']]);
            }

            $quantity = round((float) $data['quantity'], 3);
            $state = $data['stock_state'] ?? 'available';
            $reason = $data['reason'];
            $key = $data['idempotency_key'];
            $out = $this->movements->store([
                'product_id' => $product->id,
                'warehouse_id' => $source->warehouse_id,
                'location_id' => $source->id,
                'source_warehouse_id' => $source->warehouse_id,
                'destination_warehouse_id' => $destination->warehouse_id,
                'type' => 'out',
                'quantity' => $quantity,
                'stock_state' => $state,
                'affects_company_quantity' => false,
                'movement_code' => 'bin_transfer_out',
                'source_type' => 'bin_transfer',
                'reason' => $reason,
                'idempotency_key' => $key.'-out',
                'trace_allocations' => $data['trace_allocations'] ?? [],
                'metadata' => ['destination_location_id' => $destination->id],
            ]);
            $traceAllocations = $out->traceLines->map(fn ($line) => [
                'inventory_lot_id' => $line->inventory_lot_id,
                'quantity' => (float) $line->quantity,
            ])->all();
            $in = $this->movements->store([
                'product_id' => $product->id,
                'warehouse_id' => $destination->warehouse_id,
                'location_id' => $destination->id,
                'source_warehouse_id' => $source->warehouse_id,
                'destination_warehouse_id' => $destination->warehouse_id,
                'type' => 'in',
                'quantity' => $quantity,
                'stock_state' => $state,
                'affects_company_quantity' => false,
                'movement_code' => 'bin_transfer_in',
                'source_type' => 'bin_transfer',
                'source_id' => $out->id,
                'reason' => $reason,
                'idempotency_key' => $key.'-in',
                'trace_allocations' => $traceAllocations,
                'metadata' => ['source_location_id' => $source->id, 'out_movement_id' => $out->id],
            ]);
            $this->integrity->assertProductReconciled($product);

            return [
                'out_movement' => $out->fresh([
                    'warehouse:id,name,code', 'location:id,name,code,path', 'traceLines.lot',
                ]),
                'in_movement' => $in->fresh([
                    'warehouse:id,name,code', 'location:id,name,code,path', 'traceLines.lot',
                ]),
            ];
        });
    }
}
