<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class InventorySnapshotService
{
    /**
     * Dashboard stock reads need on-hand/available only, without purchase schedules or order ATP.
     * There is no mutable-balance cache; this is one authoritative aggregate per read.
     */
    public function onHandForProducts(Collection $products): Collection
    {
        if ($products->isEmpty()) return collect();
        $balances = WarehouseStock::withoutGlobalScopes()
            ->whereIn('company_id', $products->pluck('company_id')->filter()->unique())
            ->whereIn('product_id', $products->pluck('id'))
            ->selectRaw('product_id, SUM(quantity) as on_hand, SUM(available_quantity) as available')
            ->groupBy('product_id')->get()->keyBy('product_id');
        return $products->mapWithKeys(fn (Product $product) => [(int) $product->id => [
            'on_hand' => round((float) ($balances->get($product->id)?->on_hand ?? $product->quantity), 3),
            'available' => round((float) ($balances->get($product->id)?->available ?? $product->quantity), 3),
        ]]);
    }

    /** Earliest scheduled availability for demand, using the same ATP snapshot.
     * Undated and overdue, unreceived purchase orders are not firm promises. */
    public function expectedAvailabilityDate(array $snapshot, mixed $quantity, mixed $ownUnreserved = 0): ?string
    {
        $q=fn($v)=>\App\Support\Money::normalizeDecimal($v,3);
        $required=\Brick\Math\BigDecimal::of($q($quantity));
        $other=\Brick\Math\BigDecimal::of($q($snapshot['committed_outgoing']??0))->minus($q($ownUnreserved));
        if($other->isLessThan(0))$other=\Brick\Math\BigDecimal::of('0');
        $available=\Brick\Math\BigDecimal::of($q($snapshot['available']??0))->minus($other);
        $today=\App\Support\CompanyClock::today()->toDateString();
        if($required->isLessThanOrEqualTo(0)||$available->isGreaterThanOrEqualTo($required))return $today;
        foreach($snapshot['incoming_schedule']??[] as $receipt){
            if(empty($receipt['expected_at'])||$receipt['expected_at']<$today)continue;
            $available=$available->plus($q($receipt['quantity']));
            if($available->isGreaterThanOrEqualTo($required))return $receipt['expected_at'];
        }
        return null;
    }
    private const INCOMING_PURCHASE_ORDER_STATUSES = ['confirmed', 'ordered', 'partially_received'];

    /**
     * Build one state-aware inventory snapshot per product without issuing an
     * extra query for every row. Products created before warehouse balances
     * existed retain their established quantity as a safe legacy fallback.
     *
     * @param  Collection<int, Product>  $products
     * Incoming is a planning value only: confirmed base-unit PO quantity less
     * accepted/received base-unit quantity. It is never added to physical
     * warehouse balances. Projected is available + incoming for future ATP and
     * replenishment decisions.
     *
     * @return Collection<int, array{on_hand: float, available: float, reserved: float, damaged: float, quarantine: float, blocked: float, incoming: float, projected: float, committed_outgoing: float, available_to_promise: float, expected_incoming_by_as_of: float, available_to_promise_by_as_of: float, incoming_schedule: array<int, array{expected_at: ?string, quantity: float, purchase_order_id: int}>}>
     */
    public function forProducts(Collection $products, ?string $asOf = null, ?int $warehouseId = null): Collection
    {
        if ($products->isEmpty()) {
            return collect();
        }
        if (DB::transactionLevel() === 0) {
            // One database read snapshot for balances, demand and supply evidence.
            return DB::transaction(fn () => $this->forProducts($products, $asOf, $warehouseId));
        }

        $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
        $companyIds = $products->pluck('company_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $balances = WarehouseStock::withoutGlobalScopes()
            ->whereIn('company_id', $companyIds)
            ->whereIn('product_id', $productIds)
            ->when($warehouseId, fn($q)=>$q->where('warehouse_id',$warehouseId))
            ->selectRaw('product_id, SUM(quantity) as on_hand, SUM(available_quantity) as available, SUM(reserved_quantity) as reserved, SUM(damaged_quantity) as damaged, SUM(quarantine_quantity) as quarantine, SUM(blocked_quantity) as blocked')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');
        // "Incoming" remains a planning total for backward compatibility.
        // ATP is deliberately stricter: a PO with no expected receipt date,
        // or one expected after the requested date, cannot be promised yet.
        $planningDate = CarbonImmutable::parse($asOf ?? \App\Support\CompanyClock::today()->toDateString(), \App\Support\CompanyClock::timezone())->startOfDay();
        $incoming = $this->incomingEvidence($products, $warehouseId);
        $allocated = DB::table('outbound_allocations as a')
            ->join('sales_order_items as i', 'i.id', '=', 'a.sales_order_item_id')
            ->whereIn('a.company_id', $companyIds)->whereIn('i.product_id', $productIds)
            ->whereColumn('a.company_id', 'i.company_id')
            ->where('a.status', '!=', 'released')->whereNotNull('a.pick_task_id')
            ->when($warehouseId, fn ($q) => $q->where('a.warehouse_id', $warehouseId))
            ->selectRaw('i.product_id, SUM(a.quantity - a.dispatched_quantity) as allocated, SUM(a.picked_quantity - a.dispatched_quantity) as picked')
            ->groupBy('i.product_id')->get()->keyBy('product_id');
        $internalTransit = DB::table('stock_transfer_items as i')->join('stock_transfers as t', 't.id', '=', 'i.stock_transfer_id')
            ->whereIn('t.company_id', $companyIds)->whereIn('i.product_id', $productIds)
            ->whereIn('t.status', ['in_transit', 'partially_received'])
            ->when($warehouseId, fn ($q) => $q->where('t.destination_warehouse_id', $warehouseId))
            ->selectRaw('i.product_id, SUM(i.quantity - i.received_quantity - i.damaged_quantity) as quantity')
            ->groupBy('i.product_id')->pluck('quantity', 'product_id');

        $demand=\App\Models\SalesOrderItem::query()->whereIn('company_id',$companyIds)->whereIn('product_id',$productIds)->whereHas('order',fn($q)=>$q->whereNotNull('confirmed_at')->whereNotIn('status',['cancelled','delivered'])->when($warehouseId,fn($q)=>$q->where('warehouse_id',$warehouseId)))
            ->selectRaw('product_id, SUM(base_quantity - dispatched_quantity - reserved_quantity) as unreserved')->groupBy('product_id')->pluck('unreserved','product_id');
        return $products->mapWithKeys(function (Product $product) use ($balances, $incoming, $planningDate, $demand, $warehouseId, $allocated, $internalTransit): array {
            $balance = $balances->get($product->id);
            $legacyQuantity = $warehouseId ? 0 : round((float) $product->quantity, 3);
            $available = round((float) ($balance?->available ?? $legacyQuantity), 3);
            $incomingPlan = $incoming->get($product->id, ['total' => 0.0, 'schedule' => [], 'in_transit' => 0.0, 'evidence' => [], 'quantity_complete' => true, 'lines' => [], 'not_shipped' => 0.0, 'arrived' => 0.0]);
            $incomingQuantity = round((float) $incomingPlan['total'], 3);
            $incomingByPlanningDate = round((float) collect($incomingPlan['schedule'])
                ->filter(fn (array $event) => $event['expected_at'] !== null
                    && CarbonImmutable::parse($event['expected_at'], \App\Support\CompanyClock::timezone())->lessThanOrEqualTo($planningDate))
                ->sum('quantity'), 3);
            $committedOutgoing = max(0.0, (float) $demand->get($product->id,0));
            $reserved = round((float) ($balance?->reserved ?? 0), 3);
            $allocatedQuantity = round((float) ($allocated->get($product->id)?->allocated ?? 0), 3);

            return [(int) $product->id => [
                'on_hand' => round((float) ($balance?->on_hand ?? $legacyQuantity), 3),
                'available' => $available,
                // Compatibility: reserved includes allocations. Position() exposes exclusive buckets.
                'reserved' => $reserved,
                'reserved_unallocated' => round($reserved - $allocatedQuantity, 3),
                'allocated' => $allocatedQuantity,
                'picked' => round((float) ($allocated->get($product->id)?->picked ?? 0), 3),
                'damaged' => round((float) ($balance?->damaged ?? 0), 3),
                'quarantine' => round((float) ($balance?->quarantine ?? 0), 3),
                'blocked' => round((float) ($balance?->blocked ?? 0), 3),
                'incoming' => $incomingQuantity,
                'incoming_quantity_complete' => $incomingPlan['quantity_complete'],
                'in_transit' => $incomingPlan['in_transit'],
                'not_yet_shipped' => $incomingPlan['not_shipped'],
                'arrived_awaiting_receipt' => $incomingPlan['arrived'],
                'incoming_lines' => array_slice($incomingPlan['lines'], 0, 50),
                'incoming_lines_truncated' => count($incomingPlan['lines']) > 50,
                'internal_in_transit' => round((float) $internalTransit->get($product->id, 0), 3),
                'incoming_evidence' => array_slice($incomingPlan['evidence'], 0, 50),
                'incoming_evidence_truncated' => count($incomingPlan['evidence']) > 50,
                'projected' => round($available + $incomingQuantity, 3),
                'committed_outgoing' => $committedOutgoing,
                'available_to_promise' => round(max(0, $available - $committedOutgoing), 3),
                'as_of' => $planningDate->toDateString(),
                'expected_incoming_by_as_of' => $incomingByPlanningDate,
                'available_to_promise_by_as_of' => round(max(0, $available + $incomingByPlanningDate - $committedOutgoing), 3),
                'incoming_schedule' => $incomingPlan['schedule'],
                'commitment_limitations' => 'Incoming is outstanding PO supply; in transit is a subset, never added again. Dates are estimates, not delivery promises. Reserved includes picking allocations. Unreserved confirmed demand is subtracted only in ATP.',
            ]];
        });
    }

    /** Read projection only. No stock balance, reservation or purchasing record is mutated. */
    private function incomingEvidence(Collection $products, ?int $warehouseId): Collection
    {
        $items = PurchaseOrderItem::query()
            ->with('purchaseOrder:id,po_number,expected_at,status,warehouse_id')
            ->whereIn('product_id', $products->pluck('id'))
            ->whereHas('purchaseOrder', fn ($query) => $query
                ->withoutGlobalScopes()
                ->whereIn('company_id', $products->pluck('company_id')->unique())
                ->whereNull('deleted_at')
                ->when($warehouseId, fn($q)=>$q->where('warehouse_id',$warehouseId))
                ->whereIn('status', self::INCOMING_PURCHASE_ORDER_STATUSES))
            ->get();
        $cargo = \App\Models\ShipmentItem::query()->with('shipment:id,tracking_number,status,eta,warehouse_id')
            ->whereIn('company_id', $products->pluck('company_id')->unique())
            ->where(fn ($q) => $q->whereIn('purchase_order_item_id', $items->pluck('id'))
                ->orWhere(fn ($q) => $q->whereNull('purchase_order_item_id')->whereIn('product_id', $products->pluck('id'))))->get();
        $unlinkedCargo = $cargo->whereNull('purchase_order_item_id')
            ->filter(fn ($line) => $line->shipment && $line->shipment->status !== 'cancelled'
                && (! $warehouseId || (int) $line->shipment->warehouse_id === $warehouseId))->groupBy('product_id');
        $arrived = DB::table('goods_receipt_items as i')->join('goods_receipts as r', 'r.id', '=', 'i.goods_receipt_id')
            ->whereIn('r.company_id', $products->pluck('company_id')->unique())->where('r.status', 'posted')
            ->whereIn('i.purchase_order_item_id', $items->pluck('id'))->whereNotNull('i.shipment_item_id')
            ->selectRaw('i.shipment_item_id, SUM(i.shipment_received_base_quantity) as quantity')->groupBy('i.shipment_item_id')->pluck('quantity', 'shipment_item_id');
        $unattributed = DB::table('goods_receipt_items as i')->join('goods_receipts as r', 'r.id', '=', 'i.goods_receipt_id')
            ->whereIn('r.company_id', $products->pluck('company_id')->unique())->where('r.status', 'posted')
            ->whereIn('i.purchase_order_item_id', $items->pluck('id'))->whereNull('i.shipment_item_id')
            ->whereRaw('i.accepted_base_quantity + i.damaged_base_quantity > 0')->distinct()->pluck('i.purchase_order_item_id');
        $cargo = $cargo->groupBy('purchase_order_item_id');
        $plans = $items->groupBy('product_id')->map(function (Collection $items) use ($cargo, $arrived, $unattributed): array {
                $transit = 0.0; $complete = true; $evidence = []; $lines = []; $notShipped = 0.0; $arrivedQuantity = 0.0;
                $quantityComplete = ! $items->contains(fn ($item) => $item->conversion_mode === 'variable' && $item->base_quantity === null);
                $events = $items->map(function (PurchaseOrderItem $item): array {
                    // A box/pallet quantity is not metres when its actual base quantity is unknown.
                    if ($item->conversion_mode === 'variable' && $item->base_quantity === null) return ['expected_at' => null, 'quantity' => 0, 'purchase_order_id' => $item->purchase_order_id];
                    $quantity = max(0, round(
                        (float) ($item->base_quantity ?? $item->quantity)
                        - (float) ($item->received_base_quantity ?? $item->received_quantity),
                        3,
                    ));

                    return [
                        'expected_at' => $item->purchaseOrder?->expected_at?->toDateString(),
                        'quantity' => $quantity,
                        'purchase_order_id' => (int) $item->purchase_order_id,
                    ];
                })->filter(fn (array $event) => $event['quantity'] > 0)->values();
                foreach ($items as $item) {
                    $remaining = max(0, round((float) ($item->base_quantity ?? $item->quantity) - (float) ($item->received_base_quantity ?? $item->received_quantity), 3));
                    $ambiguous = $unattributed->contains($item->id); $lineTransit = 0.0; $lineLoaded = 0.0; $lineArrived = 0.0; $lineComplete = !$ambiguous;
                    foreach ($cargo->get($item->id, collect()) as $line) {
                        if ($line->shipment?->status === 'cancelled') continue;
                        $verified = $line->loaded_quantity !== null && $line->base_quantity !== null && (float) $line->quantity > 0
                            && $line->unit === $item->unit && ($item->conversion_mode !== 'variable' || (float) $line->loaded_quantity === (float) $line->quantity);
                        $outstanding = $verified ? max(0, round((float) $line->base_quantity * (float) $line->loaded_quantity / (float) $line->quantity - (float) $arrived->get($line->id, 0), 3)) : null;
                        $isTransit = in_array($line->shipment?->status, ['in_transit','departed'], true);
                        $isArrived = in_array($line->shipment?->status, ['arrived','customs','delivered'], true);
                        if (!$verified || $ambiguous) $lineComplete = false;
                        if ($remaining > 0 && ($isTransit || $isArrived) && (! $verified || $ambiguous)) $complete = false;
                        if ($isTransit && $verified && ! $ambiguous) $lineTransit += $outstanding;
                        if ($isArrived && $verified && ! $ambiguous) $lineArrived += $outstanding;
                        if (($isTransit || $isArrived) && $verified && ! $ambiguous) $lineLoaded += $outstanding;
                        $evidence[] = ['purchase_order_id' => $item->purchase_order_id, 'shipment_item_id' => $line->id,
                            'shipment_id' => $line->shipment_id, 'reference' => $line->shipment?->tracking_number,
                            'status' => $line->shipment?->status, 'loaded_base' => $verified ? round((float) $line->base_quantity * (float) $line->loaded_quantity / (float) $line->quantity, 3) : null,
                            'received_base' => round((float) $arrived->get($line->id, 0), 3), 'remaining_base' => $ambiguous ? null : $outstanding,
                            'eta' => $line->shipment?->eta?->toDateString(), 'attribution_complete' => ! $ambiguous && $verified];
                        // A delayed shipment cannot leave an earlier PO date as a promise.
                        if ($line->shipment?->eta && ($outstanding === null || $outstanding > 0)) {
                            $eta = $line->shipment->eta->toDateString();
                            $events = $events->map(fn ($event) => (int) $event['purchase_order_id'] === (int) $item->purchase_order_id
                                && $event['expected_at'] !== null && $event['expected_at'] < $eta ? [...$event, 'expected_at' => $eta] : $event);
                        }
                    }
                    $transit += min($remaining, $lineTransit);
                    $arrivedQuantity += min(max(0, $remaining - $lineTransit), $lineArrived);
                    $unshipped = $remaining <= 0 ? 0 : ($lineComplete && !($item->conversion_mode === 'variable' && $item->base_quantity === null) ? max(0, round($remaining - $lineLoaded, 3)) : null);
                    if ($unshipped === null) $notShipped = null;
                    elseif ($notShipped !== null) $notShipped += $unshipped;
                    $lines[] = ['purchase_order_id' => $item->purchase_order_id, 'purchase_order_item_id' => $item->id,
                        'reference' => $item->purchaseOrder?->po_number, 'warehouse_id' => $item->purchaseOrder?->warehouse_id,
                        'state' => $remaining <= 0 ? 'received' : ((float)$item->received_quantity > 0 ? 'partially_received' : $item->purchaseOrder?->status),
                        'ordered' => $item->base_quantity !== null ? (float)$item->base_quantity : ($item->conversion_mode === 'variable' ? null : (float)$item->quantity),
                        'received' => (float)($item->received_base_quantity ?? $item->received_quantity), 'remaining' => $item->conversion_mode === 'variable' && $item->base_quantity === null ? null : $remaining,
                        'not_yet_shipped' => $unshipped, 'in_transit' => $lineComplete ? min($remaining, $lineTransit) : null,
                        'arrived_awaiting_receipt' => $lineComplete ? min(max(0, $remaining - $lineTransit), $lineArrived) : null,
                        'expected_warehouse_at' => $events->firstWhere('purchase_order_id', $item->purchase_order_id)['expected_at'] ?? null];
                }
                return ['total' => round((float) $events->sum('quantity'), 3), 'schedule' => $events->sortBy('expected_at')->values()->all(),
                    'in_transit' => $complete && $quantityComplete ? round($transit, 3) : null, 'evidence' => $evidence, 'quantity_complete' => $quantityComplete,
                    'lines' => $lines, 'not_shipped' => $notShipped !== null && $quantityComplete ? round($notShipped, 3) : null, 'arrived' => $complete && $quantityComplete ? round($arrivedQuantity, 3) : null];
        });
        foreach ($unlinkedCargo as $productId => $lines) {
            $plan = $plans->get($productId, ['total' => 0.0, 'schedule' => [], 'evidence' => [], 'lines' => []]);
            // Standalone cargo may duplicate PO supply or already have arrived through an
            // unlinked source. Never add it again or report a falsely precise zero.
            $plan['quantity_complete'] = false; $plan['in_transit'] = null;
            $plan['not_shipped'] = null; $plan['arrived'] = null;
            foreach ($lines as $line) $plan['evidence'][] = ['purchase_order_id' => null, 'shipment_item_id' => $line->id,
                'shipment_id' => $line->shipment_id, 'reference' => $line->shipment->tracking_number, 'status' => $line->shipment->status,
                'loaded_base' => null, 'received_base' => null, 'remaining_base' => null, 'eta' => $line->shipment->eta?->toDateString(),
                'attribution_complete' => false];
            $plans->put($productId, $plan);
        }
        return $plans;
    }

    /** Exclusive operational buckets, with explicit overlap for subsets. */
    public function position(array $snapshot): array
    {
        return [
            'physical' => $snapshot['on_hand'], 'available' => $snapshot['available'],
            'reserved' => $snapshot['reserved_unallocated'], 'allocated' => $snapshot['allocated'],
            'reserved_total' => $snapshot['reserved'], 'picked' => $snapshot['picked'],
            'quality_hold' => $snapshot['quarantine'], 'damaged' => $snapshot['damaged'], 'blocked' => $snapshot['blocked'],
            'incoming' => $snapshot['incoming_quantity_complete'] ? $snapshot['incoming'] : null, 'in_transit' => $snapshot['in_transit'],
            'not_yet_shipped' => $snapshot['not_yet_shipped'], 'arrived_awaiting_receipt' => $snapshot['arrived_awaiting_receipt'],
            'internal_in_transit' => $snapshot['internal_in_transit'], 'backordered' => $snapshot['committed_outgoing'],
            'projected_available' => $snapshot['incoming_quantity_complete'] ? round($snapshot['projected'] - $snapshot['committed_outgoing'], 3) : null,
            'difference' => round($snapshot['on_hand'] - $snapshot['available'] - $snapshot['reserved'] - $snapshot['quarantine'] - $snapshot['damaged'] - $snapshot['blocked'], 3),
            'explanation' => 'Available = Physical - Reserved - Allocated - Quality Hold - Damaged - Blocked. Picked is a subset of Allocated. In Transit is a subset of Incoming. Internal transit is owned stock outside warehouse physical balances. Projected Available = Available + Incoming - Backordered; it is an estimate, not sellable stock.',
        ];
    }

    /** Lazy, permission-scoped commitment and movement details. Never changes stock. */
    public function operations(Product $product, ?int $warehouseId = null): array
    {
        $can = fn ($permission) => app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission);
        $commitments = []; $truncated = false;
        if ($can('fulfillment.view')) {
            $rows = \App\Models\OutboundAllocation::query()->with(['item.order:id,order_number,status', 'warehouse:id,name', 'location:id,path', 'lot:id,status,expiry_at'])
                ->whereHas('item', fn ($q) => $q->where('product_id', $product->id))->where('status', '!=', 'released')
                ->whereRaw('quantity > dispatched_quantity')->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->orderBy('id')->limit(51)->get();
            $truncated = $rows->count() > 50;
            foreach ($rows->take(50) as $row) {
                $invalid = $row->lot && ($row->lot->status !== 'active' || ($row->lot->expiry_at && $row->lot->expiry_at->toDateString() < \App\Support\CompanyClock::today()->toDateString()));
                $commitments[] = ['id' => 'order-'.$row->id, 'kind' => $row->pick_task_id ? 'picking' : 'customer_reservation',
                    'reference' => $row->item?->order?->order_number, 'quantity' => round((float)$row->quantity - (float)$row->dispatched_quantity, 3),
                    'picked' => round((float)$row->picked_quantity - (float)$row->dispatched_quantity, 3), 'reserves_available' => true,
                    'warehouse' => $row->warehouse?->name, 'location' => $row->location?->path, 'needs_review' => (bool)$invalid,
                    'url' => '/fulfillment?order='.$row->sales_order_id];
            }
        }
        if ($can('transfers.view')) {
            $rows = \App\Models\StockTransferItem::query()->with(['transfer.sourceWarehouse:id,name', 'transfer.destinationWarehouse:id,name'])
                ->where('product_id', $product->id)->whereHas('transfer', fn ($q) => $q->whereIn('status', ['draft','in_transit','partially_received'])
                    ->when($warehouseId, fn ($q) => $q->where(fn ($q) => $q->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId))))
                ->orderBy('id')->limit(51)->get();
            $truncated = $truncated || $rows->count() > 50;
            foreach ($rows->take(50) as $row) $commitments[] = ['id' => 'transfer-'.$row->id, 'kind' => $row->transfer->status === 'draft' ? 'planned_transfer' : 'internal_transit',
                'reference' => $row->transfer->transfer_number, 'quantity' => max(0, round((float)$row->quantity - (float)$row->received_quantity - (float)$row->damaged_quantity, 3)),
                'picked' => (float)$row->picked_quantity, 'reserves_available' => false,
                'warehouse' => $row->transfer->sourceWarehouse?->name, 'destination' => $row->transfer->destinationWarehouse?->name,
                'url' => '/warehouse-operations?tab=transfers&transfer='.$row->stock_transfer_id];
        }
        $labels = ['opening_balance'=>'Opening stock', 'purchase_receipt'=>'Goods receipt', 'damage_received'=>'Damaged receipt', 'transfer_out'=>'Transfer dispatched',
            'transfer_in'=>'Transfer received', 'transfer_return'=>'Transfer returned', 'reservation'=>'Stock reserved', 'reservation_release'=>'Reservation released',
            'daily_sale'=>'Customer dispatch / sale', 'invoice_sale'=>'Customer invoice issue', 'stock_count'=>'Approved count variance', 'customer_return'=>'Customer return',
            'supplier_return'=>'Supplier return', 'quarantine_release'=>'Quality released', 'quarantine_in'=>'Quality hold', 'state_transfer'=>'Stock state changed', 'bin_transfer_in'=>'Putaway / bin move', 'bin_transfer_out'=>'Bin move out', 'daily_sale_reversal'=>'Sale reversal', 'invoice_credit'=>'Customer credit return', 'sample'=>'Sample issued', 'internal_use'=>'Internal use', 'reconciliation_adjustment'=>'Opening reconciliation', 'legacy_stock_in'=>'Stock received', 'legacy_stock_out'=>'Stock issued', 'damage_writeoff'=>'Damage write-off',
            'manual_adjustment_in'=>'Authorized adjustment', 'manual_adjustment_out'=>'Authorized adjustment', 'import_adjustment_in'=>'Imported adjustment', 'import_adjustment_out'=>'Imported adjustment'];
        $movements = \App\Models\StockMovement::query()->with(['actor:id,name', 'warehouse:id,name', 'location:id,path'])
            ->where('product_id', $product->id)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where(fn ($q) => $q->whereNull('metadata->transition_key')->orWhere('type', 'in'))
            ->orderByDesc('effective_occurred_at')->orderByDesc('id')->limit(31)->get([
                'id','company_id','product_id','warehouse_id','location_id','performed_by','type','quantity','stock_state','movement_code','reason','occurred_at','created_at',
                'source_type','source_id','metadata','affects_company_quantity','location_quantity_before','location_quantity_after',
            ]);
        $sourceLinks = ['sales_order'=>['fulfillment.view','/fulfillment?order='], 'goods_receipt'=>['inventory.view','/warehouse-operations?receipt='],
            'stock_transfer'=>['transfers.view','/warehouse-operations?tab=transfers&transfer='], 'inventory_count'=>['inventory.counts.manage','/operations-center?tab=counts&count='],
            'quality_inspection'=>['quality.view','/quality?inspection='], 'daily_sale'=>['daily_sales.view','/daily-sales?sale='], 'invoice'=>['invoices.view','/invoices?invoice='],
            'inventory_return'=>['returns.view','/operations-center?tab=returns&return=']];
        $timeline = $movements->take(30)->map(function ($row) use ($labels, $sourceLinks, $can) {
            $transition = !empty($row->metadata['transition_key']);
            $delta = round((float)$row->quantity * ($row->type === 'in' ? 1 : -1), 3);
            $source = $sourceLinks[$row->source_type] ?? null;
            return ['id'=>$row->id, 'label'=>$labels[$row->movement_code] ?? ($transition ? 'Stock state changed' : 'Stock movement'),
                'quantity'=>(float)$row->quantity, 'physical_change'=>$transition ? 0 : $delta, 'company_owned_change'=>$row->affects_company_quantity ? $delta : 0,
                'from_state'=>$transition ? ($row->metadata['from_state'] ?? null) : null, 'to_state'=>$transition ? ($row->metadata['to_state'] ?? null) : $row->stock_state,
                'warehouse'=>$row->warehouse?->name, 'location'=>$row->location?->path, 'before'=>$transition ? $row->location_quantity_after : $row->location_quantity_before, 'after'=>$row->location_quantity_after,
                'reason'=>$row->reason, 'actor'=>$row->actor?->name ?? 'System', 'at'=>($row->occurred_at ?? $row->created_at)?->toIso8601String(),
                'source_id'=>$row->source_id, 'url'=>$source && $can($source[0]) ? $source[1].$row->source_id : null];
        })->all();
        return ['commitments'=>$commitments, 'commitments_truncated'=>$truncated, 'reservations_restricted'=>!$can('fulfillment.view'),
            'transfers_restricted'=>!$can('transfers.view'), 'timeline'=>$timeline, 'timeline_truncated'=>$movements->count()>30,
            'commitment_policy'=>'Customer reservations/picking reduce available stock. Draft transfers are requests/verified picks, not reservations; dispatch rechecks available stock. Internal transit remains company-owned. Invalid/expired reservations require source review; confirmed order quantities are corrected by release/cancel and a new revision.'];
    }

    public function forProduct(Product $product, ?string $asOf = null, ?int $warehouseId = null): array
    {
        return $this->forProducts(collect([$product]), $asOf, $warehouseId)->get((int) $product->id);
    }
}
