<?php

namespace App\Services;

use App\Models\InventoryCountItem;
use App\Models\InventoryCountSession;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileWarehouseService
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly BinTransferService $binTransfers,
        private readonly InventoryCountService $counts,
        private readonly WarehouseOperationsService $warehouseOperations,
    ) {}

    public function bootstrap(): array
    {
        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->with(['locations' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('floor_level')->orderBy('sort_order')->orderBy('path')])
            ->orderByDesc('is_default')->orderBy('name')->get();
        $counts = InventoryCountSession::query()
            ->whereIn('status', ['in_progress', 'recount_required'])
            ->with(['warehouse:id,name,code', 'location:id,name,code,path'])
            ->withCount([
                'items',
                'items as counted_items_count' => fn ($query) => $query->whereNotNull('counted_quantity'),
            ])
            ->latest('id')->limit(100)->get();
        $orders = PurchaseOrder::query()
            ->whereNotIn('status', ['received', 'completed', 'cancelled'])
            ->whereHas('items', fn ($query) => $query
                ->whereNotNull('product_id')
                ->whereColumn('received_quantity', '<', 'quantity'))
            ->with([
                'supplier:id,name', 'warehouse:id,name,code',
                'items' => fn ($query) => $query
                    ->whereNotNull('product_id')
                    ->whereColumn('received_quantity', '<', 'quantity')
                    ->with('product:id,name,sku,barcode,unit,tracking_mode'),
            ])
            ->latest('id')->limit(100)->get();
        $pickTransfers = StockTransfer::query()
            ->where('status', 'draft')
            ->whereHas('items', fn ($query) => $query->whereColumn('picked_quantity', '<', 'quantity'))
            ->with([
                'sourceWarehouse:id,name,code', 'destinationWarehouse:id,name,code',
                'sourceLocation:id,name,code,path', 'destinationLocation:id,name,code,path',
                'items' => fn ($query) => $query->whereColumn('picked_quantity', '<', 'quantity')
                    ->with('product:id,name,sku,barcode,unit,tracking_mode'),
            ])->latest('id')->limit(100)->get();

        return [
            'warehouses' => $warehouses,
            'active_counts' => $counts,
            'open_purchase_orders' => $orders,
            'open_pick_transfers' => $pickTransfers,
            'stock_states' => WarehouseInventoryService::STATES,
        ];
    }

    public function lookup(string $code): array
    {
        $code = trim($code);
        $normalized = mb_strtolower($code);
        $product = Product::query()
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(COALESCE(barcode, ?)) = ?', ['', $normalized])
                ->orWhereRaw('LOWER(COALESCE(sku, ?)) = ?', ['', $normalized]))
            ->first();

        if (! $product) {
            $matches = Product::query()
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$code}%")
                    ->orWhere('sku', 'like', "%{$code}%")
                    ->orWhere('barcode', 'like', "%{$code}%"))
                ->orderBy('name')->limit(12)
                ->get(['id', 'name', 'sku', 'barcode', 'unit', 'quantity']);

            return ['product' => null, 'matches' => $matches];
        }

        $product->load(['category:id,name', 'supplier:id,name', 'units']);
        $product->append('image_url');
        $balances = WarehouseStock::query()
            ->where('product_id', $product->id)
            ->with(['warehouse:id,name,code', 'location:id,warehouse_id,name,code,path,type,floor_level'])
            ->orderBy('warehouse_id')->orderBy('location_key')->get();
        $lots = InventoryLot::query()
            ->where('product_id', $product->id)
            ->where('status', 'active')
            ->with(['balances' => fn ($query) => $query
                ->where('quantity', '>', 0)
                ->with(['warehouse:id,name,code', 'location:id,name,code,path'])])
            ->withSum('balances as balances_sum_quantity', 'quantity')
            ->orderByRaw('CASE WHEN expiry_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_at')->get()
            ->filter(fn (InventoryLot $lot) => (float) $lot->balances_sum_quantity > 0)
            ->values();
        $receivableLines = PurchaseOrder::query()
            ->whereNotIn('status', ['received', 'completed', 'cancelled'])
            ->whereHas('items', fn ($query) => $query
                ->where('product_id', $product->id)
                ->whereColumn('received_quantity', '<', 'quantity'))
            ->with([
                'supplier:id,name', 'warehouse:id,name,code',
                'items' => fn ($query) => $query
                    ->where('product_id', $product->id)
                    ->whereColumn('received_quantity', '<', 'quantity'),
            ])->latest('id')->get()
            ->flatMap(fn (PurchaseOrder $order) => $order->items->map(fn ($item) => [
                'purchase_order_id' => $order->id,
                'purchase_order_item_id' => $item->id,
                'po_number' => $order->po_number,
                'supplier' => $order->supplier?->name,
                'warehouse_id' => $order->warehouse_id,
                'warehouse' => $order->warehouse?->name,
                'ordered_unit' => $item->unit,
                'remaining_quantity' => $item->remaining_quantity,
            ]))->values();
        $countItems = InventoryCountItem::query()
            ->where('product_id', $product->id)
            ->whereHas('session', fn ($query) => $query->whereIn('status', ['in_progress', 'recount_required']))
            ->with([
                'session:id,count_number,warehouse_id,location_id,status',
                'session.warehouse:id,name,code', 'location:id,name,code,path',
                'lot:id,lot_number,serial_number,expiry_at',
            ])->orderByDesc('id')->get();
        $pickLines = StockTransferItem::query()
            ->where('product_id', $product->id)
            ->whereColumn('picked_quantity', '<', 'quantity')
            ->whereHas('transfer', fn ($query) => $query->where('status', 'draft'))
            ->with([
                'transfer.sourceWarehouse:id,name,code', 'transfer.destinationWarehouse:id,name,code',
                'transfer.sourceLocation:id,name,code,path', 'transfer.destinationLocation:id,name,code,path',
            ])->latest('id')->get()->map(fn (StockTransferItem $item) => [
                'stock_transfer_item_id' => $item->id,
                'stock_transfer_id' => $item->stock_transfer_id,
                'transfer_number' => $item->transfer?->transfer_number,
                'source_warehouse_id' => $item->transfer?->source_warehouse_id,
                'source_warehouse' => $item->transfer?->sourceWarehouse?->name,
                'source_location_id' => $item->transfer?->source_location_id,
                'source_location' => $item->transfer?->sourceLocation?->path,
                'destination_warehouse' => $item->transfer?->destinationWarehouse?->name,
                'destination_location' => $item->transfer?->destinationLocation?->path,
                'requested_quantity' => (float) $item->quantity,
                'picked_quantity' => (float) $item->picked_quantity,
                'remaining_quantity' => round((float) $item->quantity - (float) $item->picked_quantity, 3),
            ])->values();

        return [
            'product' => $product,
            'matches' => [],
            'balances' => $balances,
            'lots' => $lots,
            'receivable_lines' => $receivableLines,
            'count_items' => $countItems,
            'pick_lines' => $pickLines,
        ];
    }

    public function receive(array $data): PurchaseOrder
    {
        $order = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);

        return $this->purchaseOrders->receive($order, [
            'warehouse_id' => $data['warehouse_id'],
            'location_id' => $data['location_id'] ?? null,
            'supplier_document_number' => $data['supplier_document_number'] ?? null,
            'received_at' => $data['received_at'] ?? now(),
            'reason' => $data['reason'] ?? 'Mobile warehouse receipt',
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $data['idempotency_key'],
            'items' => [[
                'id' => $data['purchase_order_item_id'],
                'accepted_quantity' => $data['accepted_quantity'] ?? 0,
                'damaged_quantity' => $data['damaged_quantity'] ?? 0,
                'rejected_quantity' => $data['rejected_quantity'] ?? 0,
                'accepted_base_quantity' => $data['accepted_base_quantity'] ?? null,
                'damaged_base_quantity' => $data['damaged_base_quantity'] ?? null,
                'trace_allocations' => $data['trace_allocations'] ?? [],
                'notes' => $data['notes'] ?? null,
            ]],
        ]);
    }

    public function move(array $data): array
    {
        return $this->binTransfers->move($data);
    }

    public function count(array $data): InventoryCountSession
    {
        $session = InventoryCountSession::query()->findOrFail($data['inventory_count_id']);
        $item = [
            'count_item_id' => $data['count_item_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'stock_state' => $data['stock_state'] ?? 'available',
            'inventory_lot_id' => $data['inventory_lot_id'] ?? null,
            'counted_quantity' => $data['counted_quantity'],
            'lot_number' => $data['lot_number'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'supplier_batch' => $data['supplier_batch'] ?? null,
            'manufactured_at' => $data['manufactured_at'] ?? null,
            'expiry_at' => $data['expiry_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        return $this->counts->record($session, ['items' => [$item]]);
    }

    public function pick(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $companyId = (int) Auth::user()->company_id;
            $existing = DB::table('stock_transfer_pick_events')
                ->where('company_id', $companyId)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $transfer = StockTransfer::query()->findOrFail($existing->stock_transfer_id);

                return ['transfer' => $this->warehouseOperations->findTransfer($transfer), 'already_recorded' => true];
            }

            $item = StockTransferItem::query()
                ->whereHas('transfer', fn ($query) => $query->where('company_id', $companyId))
                ->with(['product', 'transfer'])->lockForUpdate()->findOrFail($data['stock_transfer_item_id']);
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($item->stock_transfer_id);
            if ($transfer->status !== 'draft') {
                throw ValidationException::withMessages(['stock_transfer_item_id' => ['This transfer is no longer waiting to be picked.']]);
            }
            if ((int) $item->product_id !== (int) $data['product_id']) {
                throw ValidationException::withMessages(['product_id' => ['The scanned product does not match the selected transfer line.']]);
            }
            if ((int) $transfer->source_warehouse_id !== (int) $data['warehouse_id']) {
                throw ValidationException::withMessages(['warehouse_id' => ['Pick this line from its requested source warehouse.']]);
            }
            if ($transfer->source_location_id && (int) $transfer->source_location_id !== (int) $data['location_id']) {
                throw ValidationException::withMessages(['location_id' => ['The scanned bin does not match the transfer source bin.']]);
            }
            if (! $transfer->source_location_id) {
                $transfer->update(['source_location_id' => $data['location_id']]);
            }

            $quantity = round((float) $data['quantity'], 3);
            $remaining = round((float) $item->quantity - (float) $item->picked_quantity, 3);
            if ($quantity <= 0 || $quantity > $remaining + .0005) {
                throw ValidationException::withMessages(['quantity' => ['The picked quantity exceeds the quantity still requested.']]);
            }
            $available = (float) WarehouseStock::query()
                ->where('product_id', $item->product_id)->where('warehouse_id', $transfer->source_warehouse_id)
                ->where('location_key', (int) $data['location_id'])->value('available_quantity');
            if ($available + .0005 < $quantity) {
                throw ValidationException::withMessages(['quantity' => ['The scanned bin does not have enough available stock.']]);
            }

            $allocations = $this->mergeTraceAllocations($item->trace_allocations ?? [], $data['trace_allocations'] ?? []);
            $item->update([
                'picked_quantity' => round((float) $item->picked_quantity + $quantity, 3),
                'trace_allocations' => $allocations ?: null,
                'picked_by' => Auth::id(), 'picked_at' => now(),
            ]);
            DB::table('stock_transfer_pick_events')->insert([
                'company_id' => $companyId, 'stock_transfer_id' => $transfer->id,
                'stock_transfer_item_id' => $item->id, 'location_id' => $data['location_id'],
                'quantity' => $quantity, 'trace_allocations' => ! empty($data['trace_allocations']) ? json_encode($data['trace_allocations']) : null,
                'idempotency_key' => $data['idempotency_key'], 'picked_by' => Auth::id(),
                'picked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $complete = $transfer->items()->get()->every(fn (StockTransferItem $line) => (float) $line->picked_quantity + .0005 >= (float) $line->quantity);
            $result = $complete
                ? $this->warehouseOperations->dispatch($transfer, 'mobile-pick-dispatch-'.$transfer->id)
                : $this->warehouseOperations->findTransfer($transfer->fresh());

            return ['transfer' => $result, 'dispatch_completed' => $complete, 'already_recorded' => false];
        });
    }

    private function mergeTraceAllocations(array $existing, array $added): array
    {
        return collect([...$existing, ...$added])
            ->groupBy(fn (array $allocation) => (string) ($allocation['inventory_lot_id'] ?? $allocation['serial_number'] ?? $allocation['lot_number'] ?? ''))
            ->map(function ($rows): array {
                $first = $rows->first();
                $first['quantity'] = round((float) $rows->sum(fn ($row) => (float) ($row['quantity'] ?? 0)), 3);

                return $first;
            })->values()->all();
    }
}
