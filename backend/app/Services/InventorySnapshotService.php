<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\WarehouseStock;
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
     * @return Collection<int, array{on_hand: float, available: float, reserved: float, damaged: float, quarantine: float, blocked: float, incoming: float, projected: float}>
     */
    public function forProducts(Collection $products): Collection
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
        $incoming = PurchaseOrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('purchaseOrder', fn ($query) => $query
                ->withoutGlobalScopes()
                ->whereIn('company_id', $companyIds)
                ->whereNull('deleted_at')
                ->whereIn('status', self::INCOMING_PURCHASE_ORDER_STATUSES))
            ->get([
                'product_id', 'quantity', 'base_quantity',
                'received_quantity', 'received_base_quantity',
            ])
            ->groupBy('product_id')
            ->map(fn ($items) => round((float) $items->sum(fn (PurchaseOrderItem $item) => max(0, round(
                (float) ($item->base_quantity ?? $item->quantity)
                - (float) ($item->received_base_quantity ?? $item->received_quantity),
                3,
            ))), 3));

        return $products->mapWithKeys(function (Product $product) use ($balances, $incoming): array {
            $balance = $balances->get($product->id);
            $legacyQuantity = round((float) $product->quantity, 3);
            $available = round((float) ($balance?->available ?? $legacyQuantity), 3);
            $incomingQuantity = round((float) $incoming->get($product->id, 0), 3);

            return [(int) $product->id => [
                'on_hand' => round((float) ($balance?->on_hand ?? $legacyQuantity), 3),
                'available' => $available,
                'reserved' => round((float) ($balance?->reserved ?? 0), 3),
                'damaged' => round((float) ($balance?->damaged ?? 0), 3),
                'quarantine' => round((float) ($balance?->quarantine ?? 0), 3),
                'blocked' => round((float) ($balance?->blocked ?? 0), 3),
                'incoming' => $incomingQuantity,
                'projected' => round($available + $incomingQuantity, 3),
            ]];
        });
    }

    /** @return array{on_hand: float, available: float, reserved: float, damaged: float, quarantine: float, blocked: float, incoming: float, projected: float} */
    public function forProduct(Product $product): array
    {
        return $this->forProducts(collect([$product]))->get((int) $product->id);
    }
}
