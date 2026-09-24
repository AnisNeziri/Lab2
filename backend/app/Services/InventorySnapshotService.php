<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class InventorySnapshotService
{
    /** Earliest scheduled availability for demand, using the same ATP snapshot.
     * Undated and overdue, unreceived purchase orders are not firm promises. */
    public function expectedAvailabilityDate(array $snapshot, mixed $quantity, mixed $ownUnreserved = 0): ?string
    {
        $q=fn($v)=>\App\Support\Money::normalizeDecimal($v,3);
        $required=\Brick\Math\BigDecimal::of($q($quantity));
        $other=\Brick\Math\BigDecimal::of($q($snapshot['committed_outgoing']??0))->minus($q($ownUnreserved));
        if($other->isLessThan(0))$other=\Brick\Math\BigDecimal::of('0');
        $available=\Brick\Math\BigDecimal::of($q($snapshot['available']??0))->minus($other);
        $today=now('Europe/Tirane')->toDateString();
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
    public function forProducts(Collection $products, ?string $asOf = null): Collection
    {
        if ($products->isEmpty()) {
            return collect();
        }

        $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
        $companyIds = $products->pluck('company_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $balances = WarehouseStock::withoutGlobalScopes()
            ->whereIn('company_id', $companyIds)
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, SUM(quantity) as on_hand, SUM(available_quantity) as available, SUM(reserved_quantity) as reserved, SUM(damaged_quantity) as damaged, SUM(quarantine_quantity) as quarantine, SUM(blocked_quantity) as blocked')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');
        // "Incoming" remains a planning total for backward compatibility.
        // ATP is deliberately stricter: a PO with no expected receipt date,
        // or one expected after the requested date, cannot be promised yet.
        $planningDate = CarbonImmutable::parse($asOf ?? now('Europe/Tirane')->toDateString(), 'Europe/Tirane')->startOfDay();
        $incoming = PurchaseOrderItem::query()
            ->with('purchaseOrder:id,expected_at')
            ->whereIn('product_id', $productIds)
            ->whereHas('purchaseOrder', fn ($query) => $query
                ->withoutGlobalScopes()
                ->whereIn('company_id', $companyIds)
                ->whereNull('deleted_at')
                ->whereIn('status', self::INCOMING_PURCHASE_ORDER_STATUSES))
            ->get([
                'id', 'purchase_order_id', 'product_id', 'quantity', 'base_quantity',
                'received_quantity', 'received_base_quantity',
            ])
            ->groupBy('product_id')
            ->map(function (Collection $items): array {
                $events = $items->map(function (PurchaseOrderItem $item): array {
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
                })->filter(fn (array $event) => $event['quantity'] > 0)->sortBy('expected_at')->values()->all();

                return [
                    'total' => round((float) collect($events)->sum('quantity'), 3),
                    'schedule' => $events,
                ];
            });

        $demand=\App\Models\SalesOrderItem::query()->whereIn('product_id',$productIds)->whereHas('order',fn($q)=>$q->whereNotNull('confirmed_at')->whereNotIn('status',['cancelled','delivered']))
            ->selectRaw('product_id, SUM(base_quantity - dispatched_quantity - reserved_quantity) as unreserved')->groupBy('product_id')->pluck('unreserved','product_id');
        return $products->mapWithKeys(function (Product $product) use ($balances, $incoming, $planningDate, $demand): array {
            $balance = $balances->get($product->id);
            $legacyQuantity = round((float) $product->quantity, 3);
            $available = round((float) ($balance?->available ?? $legacyQuantity), 3);
            $incomingPlan = $incoming->get($product->id, ['total' => 0.0, 'schedule' => []]);
            $incomingQuantity = round((float) $incomingPlan['total'], 3);
            $incomingByPlanningDate = round((float) collect($incomingPlan['schedule'])
                ->filter(fn (array $event) => $event['expected_at'] !== null
                    && CarbonImmutable::parse($event['expected_at'], 'Europe/Tirane')->lessThanOrEqualTo($planningDate))
                ->sum('quantity'), 3);
            // Reserved stock is already excluded from available. Only outstanding
            // unreserved order demand is subtracted here; issued sales are not counted twice.
            $committedOutgoing = max(0.0, (float) $demand->get($product->id,0));

            return [(int) $product->id => [
                'on_hand' => round((float) ($balance?->on_hand ?? $legacyQuantity), 3),
                'available' => $available,
                'reserved' => round((float) ($balance?->reserved ?? 0), 3),
                'damaged' => round((float) ($balance?->damaged ?? 0), 3),
                'quarantine' => round((float) ($balance?->quarantine ?? 0), 3),
                'blocked' => round((float) ($balance?->blocked ?? 0), 3),
                'incoming' => $incomingQuantity,
                'projected' => round($available + $incomingQuantity, 3),
                'committed_outgoing' => $committedOutgoing,
                'available_to_promise' => round(max(0, $available - $committedOutgoing), 3),
                'as_of' => $planningDate->toDateString(),
                'expected_incoming_by_as_of' => $incomingByPlanningDate,
                'available_to_promise_by_as_of' => round(max(0, $available + $incomingByPlanningDate - $committedOutgoing), 3),
                'incoming_schedule' => $incomingPlan['schedule'],
                'commitment_limitations' => 'ATP subtracts confirmed unreserved demand; reserved and dispatched quantities are already excluded from available stock.',
            ]];
        });
    }

    /** @return array{on_hand: float, available: float, reserved: float, damaged: float, quarantine: float, blocked: float, incoming: float, projected: float, committed_outgoing: float, available_to_promise: float, expected_incoming_by_as_of: float, available_to_promise_by_as_of: float, incoming_schedule: array<int, array{expected_at: ?string, quantity: float, purchase_order_id: int}>} */
    public function forProduct(Product $product, ?string $asOf = null): array
    {
        return $this->forProducts(collect([$product]), $asOf)->get((int) $product->id);
    }
}
