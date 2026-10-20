<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            $incomingPlan = $incoming->get($product->id, ['total' => 0.0, 'schedule' => [], 'in_transit' => 0.0, 'evidence' => [], 'quantity_complete' => true]);
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
            ->with('purchaseOrder:id,expected_at')
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
                $transit = 0.0; $complete = true; $evidence = [];
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
                    if ($remaining <= 0) continue;
                    $ambiguous = $unattributed->contains($item->id); $lineTransit = 0.0;
                    foreach ($cargo->get($item->id, collect()) as $line) {
                        $verified = $line->loaded_quantity !== null && $line->base_quantity !== null && (float) $line->quantity > 0
                            && $line->unit === $item->unit && ($item->conversion_mode !== 'variable' || (float) $line->loaded_quantity === (float) $line->quantity);
                        $outstanding = $verified ? max(0, round((float) $line->base_quantity * (float) $line->loaded_quantity / (float) $line->quantity - (float) $arrived->get($line->id, 0), 3)) : null;
                        $isTransit = in_array($line->shipment?->status, ['in_transit','departed','arrived','customs','delivered'], true);
                        if ($isTransit && (! $verified || $ambiguous)) $complete = false;
                        if ($isTransit && $verified && ! $ambiguous) $lineTransit += $outstanding;
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
                }
                return ['total' => round((float) $events->sum('quantity'), 3), 'schedule' => $events->sortBy('expected_at')->values()->all(),
                    'in_transit' => $complete && $quantityComplete ? round($transit, 3) : null, 'evidence' => $evidence, 'quantity_complete' => $quantityComplete];
        });
        foreach ($unlinkedCargo as $productId => $lines) {
            $plan = $plans->get($productId, ['total' => 0.0, 'schedule' => [], 'evidence' => []]);
            // Standalone cargo may duplicate PO supply or already have arrived through an
            // unlinked source. Never add it again or report a falsely precise zero.
            $plan['quantity_complete'] = false; $plan['in_transit'] = null;
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
            'internal_in_transit' => $snapshot['internal_in_transit'], 'backordered' => $snapshot['committed_outgoing'],
            'projected_available' => $snapshot['incoming_quantity_complete'] ? round($snapshot['projected'] - $snapshot['committed_outgoing'], 3) : null,
            'difference' => round($snapshot['on_hand'] - $snapshot['available'] - $snapshot['reserved'] - $snapshot['quarantine'] - $snapshot['damaged'] - $snapshot['blocked'], 3),
            'explanation' => 'Available = Physical - Reserved - Allocated - Quality Hold - Damaged - Blocked. Picked is a subset of Allocated. In Transit is a subset of Incoming. Internal transit is owned stock outside warehouse physical balances. Projected Available = Available + Incoming - Backordered; it is an estimate, not sellable stock.',
        ];
    }

    /** @return array{on_hand: float, available: float, reserved: float, damaged: float, quarantine: float, blocked: float, incoming: float, projected: float, committed_outgoing: float, available_to_promise: float, expected_incoming_by_as_of: float, available_to_promise_by_as_of: float, incoming_schedule: array<int, array{expected_at: ?string, quantity: float, purchase_order_id: int}>} */
    public function forProduct(Product $product, ?string $asOf = null, ?int $warehouseId = null): array
    {
        return $this->forProducts(collect([$product]), $asOf, $warehouseId)->get((int) $product->id);
    }
}
