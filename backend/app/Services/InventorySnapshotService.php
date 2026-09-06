<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class InventorySnapshotService
{
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

        return $products->mapWithKeys(function (Product $product) use ($balances, $incoming, $planningDate): array {
            $balance = $balances->get($product->id);
            $legacyQuantity = round((float) $product->quantity, 3);
            $available = round((float) ($balance?->available ?? $legacyQuantity), 3);
            $incomingPlan = $incoming->get($product->id, ['total' => 0.0, 'schedule' => []]);
            $incomingQuantity = round((float) $incomingPlan['total'], 3);
            $incomingByPlanningDate = round((float) collect($incomingPlan['schedule'])
                ->filter(fn (array $event) => $event['expected_at'] !== null
                    && CarbonImmutable::parse($event['expected_at'], 'Europe/Tirane')->lessThanOrEqualTo($planningDate))
                ->sum('quantity'), 3);
            // AIMS has no open sales-order commitment model yet. Issued
            // invoices and daily sales already reduce physical stock, so they
            // must not be counted again here. Expose the zero explicitly so
            // future sales-order commitments have a stable ATP contract.
            $committedOutgoing = 0.0;

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
                'commitment_limitations' => 'Open customer-order commitments are not modelled in AIMS yet; issued sales already reduce stock and are not counted twice.',
            ]];
        });
    }

    /** @return array{on_hand: float, available: float, reserved: float, damaged: float, quarantine: float, blocked: float, incoming: float, projected: float, committed_outgoing: float, available_to_promise: float, expected_incoming_by_as_of: float, available_to_promise_by_as_of: float, incoming_schedule: array<int, array{expected_at: ?string, quantity: float, purchase_order_id: int}>} */
    public function forProduct(Product $product, ?string $asOf = null): array
    {
        return $this->forProducts(collect([$product]), $asOf)->get((int) $product->id);
    }
}
