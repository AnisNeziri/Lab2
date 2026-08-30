<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReplenishmentService
{
    private const USAGE_MOVEMENT_CODES = ['daily_sale', 'invoice_sale', 'sample', 'internal_use'];

    private const INCOMING_ORDER_STATUSES = ['confirmed', 'ordered', 'partially_received'];

    public function __construct(
        private readonly SupplierCatalogueService $catalogue,
        private readonly PurchaseOrderService $purchaseOrders,
    ) {}

    public function suggestions(array $filters = []): array
    {
        $asOf = CarbonImmutable::parse($filters['as_of'] ?? now('Europe/Tirane')->toDateString(), 'Europe/Tirane')->startOfDay();
        $products = Product::query()
            ->with(['warehouseStock', 'supplier:id,name', 'supplierCatalogue' => fn ($query) => $query->where('is_active', true)->with('supplier:id,name')])
            ->where('lifecycle_status', 'active')
            ->when($filters['product_ids'] ?? null, fn ($query, $ids) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get();
        if ($products->isEmpty()) {
            return ['as_of' => $asOf->toDateString(), 'suggestion_count' => 0, 'suggestions' => []];
        }

        $maximumHistoryDays = (int) ($filters['history_days'] ?? max(1, $products->max('replenishment_history_days') ?: 90));
        $usageMovements = StockMovement::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->whereIn('movement_code', self::USAGE_MOVEMENT_CODES)
            ->where('type', 'out')
            ->where('affects_company_quantity', true)
            ->where('occurred_at', '>=', $asOf->subDays($maximumHistoryDays)->startOfDay())
            ->where('occurred_at', '<', $asOf->addDay())
            ->get(['product_id', 'quantity', 'occurred_at'])
            ->groupBy('product_id');

        $incomingOrders = PurchaseOrder::query()
            ->with(['items' => fn ($query) => $query->whereIn('product_id', $products->pluck('id'))])
            ->whereIn('status', self::INCOMING_ORDER_STATUSES)
            ->get();
        $incomingByProduct = $this->incomingByProduct($incomingOrders, $asOf);
        $includeOk = (bool) ($filters['include_ok'] ?? false);
        $suggestions = [];

        foreach ($products as $product) {
            $historyDays = max(1, (int) ($filters['history_days'] ?? $product->replenishment_history_days ?? 90));
            $historyStart = $asOf->subDays($historyDays)->startOfDay();
            $usageQuantity = round((float) collect($usageMovements->get($product->id, []))
                ->filter(fn ($movement) => $movement->occurred_at && $movement->occurred_at->greaterThanOrEqualTo($historyStart))
                ->sum(fn ($movement) => (float) $movement->quantity), 3);
            $dailyUsage = round($usageQuantity / $historyDays, 6);

            $balances = $product->warehouseStock;
            $hasBalances = $balances->isNotEmpty();
            $onHand = round($hasBalances ? (float) $balances->sum('quantity') : (float) $product->quantity, 3);
            $reserved = round($hasBalances ? (float) $balances->sum('reserved_quantity') : 0, 3);
            // Available is its own controlled state. Subtracting only
            // reservations from on-hand incorrectly makes damaged,
            // quarantined and blocked stock look sellable/reorderable.
            $available = round($hasBalances ? (float) $balances->sum('available_quantity') : (float) $product->quantity, 3);
            $incoming = $incomingByProduct->get($product->id, ['quantity' => 0.0, 'events' => []]);
            $incomingQuantity = round((float) $incoming['quantity'], 3);
            $projected = round($available + $incomingQuantity, 3);

            $supplier = $product->supplierCatalogue
                ->sortBy(fn ($item) => [! $item->is_preferred, $item->usual_lead_time_days, $item->base_currency_price ?? PHP_FLOAT_MAX])
                ->first();
            if (! $supplier && $product->supplier_id) {
                // Backward-compatible bridge for products created by older
                // imports/seeders before the multi-supplier catalogue existed.
                $supplier = new ProductSupplier([
                    'product_id' => $product->id,
                    'supplier_id' => $product->supplier_id,
                    'purchase_price' => $product->purchase_price,
                    'currency' => 'EUR',
                    'exchange_rate_to_base' => 1,
                    'pack_size' => 1,
                    'minimum_order_quantity' => 0,
                    'usual_lead_time_days' => 0,
                    'is_preferred' => true,
                    'is_active' => true,
                ]);
                $supplier->setRelation('supplier', $product->supplier);
            }
            $leadDays = (int) ($supplier?->usual_lead_time_days ?? 0);
            $safetyStock = round((float) ($product->safety_stock ?? 0), 3);
            $minimumStock = round((float) ($product->min_quantity ?? 0), 3);
            $derivedReorderPoint = round(max($minimumStock, $safetyStock + ($dailyUsage * $leadDays)), 3);
            $configuredReorderPoint = $product->reorder_point === null ? null : round((float) $product->reorder_point, 3);
            $effectiveReorderPoint = $configuredReorderPoint ?? $derivedReorderPoint;
            $reviewDays = max(1, (int) ($product->replenishment_review_days ?? 14));
            $targetStock = round(max(
                $minimumStock,
                $effectiveReorderPoint + ($dailyUsage * $reviewDays),
                $safetyStock + ($dailyUsage * ($leadDays + $reviewDays)),
            ), 3);
            $triggered = $projected <= $effectiveReorderPoint + 0.0005;
            $rawRequired = $triggered ? max(0, $targetStock - $projected) : 0;
            $moq = round((float) ($supplier?->minimum_order_quantity ?? 0), 3);
            $packSize = max(0.001, round((float) ($supplier?->pack_size ?? 1), 3));
            $recommended = $triggered ? $this->roundToPack(max($rawRequired, $moq, $packSize), $packSize) : 0.0;
            $stockoutDate = $this->expectedStockoutDate($asOf, $available, $dailyUsage, $incoming['events']);

            if (! $triggered && ! $includeOk) {
                continue;
            }

            $suggestions[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit,
                'needs_reorder' => $triggered,
                'recommended_quantity' => $recommended,
                'expected_stockout_date' => $stockoutDate,
                'preferred_supplier' => $supplier ? [
                    'product_supplier_id' => $supplier->id,
                    'supplier_id' => $supplier->supplier_id,
                    'supplier_name' => $supplier->supplier?->name,
                    'supplier_sku' => $supplier->supplier_sku,
                    'purchase_price' => $supplier->purchase_price === null ? null : (float) $supplier->purchase_price,
                    'currency' => $supplier->currency,
                    'exchange_rate_to_base' => (float) $supplier->exchange_rate_to_base,
                    'lead_time_days' => $leadDays,
                    'minimum_order_quantity' => $moq,
                    'pack_size' => $packSize,
                ] : null,
                'calculation' => [
                    'on_hand' => $onHand,
                    'reserved' => $reserved,
                    'available' => $available,
                    'confirmed_incoming' => $incomingQuantity,
                    'projected' => $projected,
                    'history_days' => $historyDays,
                    'usage_in_history' => $usageQuantity,
                    'average_daily_usage' => $dailyUsage,
                    'supplier_lead_time_days' => $leadDays,
                    'safety_stock' => $safetyStock,
                    'minimum_stock' => $minimumStock,
                    'configured_reorder_point' => $configuredReorderPoint,
                    'derived_reorder_point' => $derivedReorderPoint,
                    'effective_reorder_point' => $effectiveReorderPoint,
                    'review_period_days' => $reviewDays,
                    'target_stock' => $targetStock,
                    'raw_required_quantity' => round($rawRequired, 3),
                    'minimum_order_quantity' => $moq,
                    'pack_size' => $packSize,
                    'formula' => [
                        'available = sellable warehouse-state balance (excludes reserved, damaged, quarantine and blocked)',
                        'projected = available + confirmed_incoming',
                        'derived_reorder_point = max(minimum_stock, safety_stock + average_daily_usage x lead_time_days)',
                        'recommended = round_up_to_pack(max(target_stock - projected, MOQ)) when projected <= effective_reorder_point',
                    ],
                ],
                'reason' => $triggered
                    ? "Projected stock {$projected} is at or below the reorder point {$effectiveReorderPoint}."
                    : "Projected stock {$projected} remains above the reorder point {$effectiveReorderPoint}.",
            ];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'suggestion_count' => collect($suggestions)->where('needs_reorder', true)->count(),
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Explicit review action: selected product suggestions are grouped into
     * draft orders. Calling suggestions alone never persists an order.
     */
    public function createDraftPurchaseOrders(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $planning = $this->suggestions([
                'product_ids' => $data['product_ids'],
                'history_days' => $data['history_days'] ?? null,
                'as_of' => $data['as_of'] ?? null,
            ]);
            $suggestions = collect($planning['suggestions'])->where('needs_reorder', true);
            $missing = collect($data['product_ids'])->map(fn ($id) => (int) $id)->diff($suggestions->pluck('product_id'));
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['product_ids' => ['Some selected products no longer need replenishment: '.$missing->implode(', ').'.']]);
            }
            $withoutSupplier = $suggestions->filter(fn ($item) => empty($item['preferred_supplier']['supplier_id']) || $item['preferred_supplier']['purchase_price'] === null);
            if ($withoutSupplier->isNotEmpty()) {
                throw ValidationException::withMessages(['product_ids' => ['Set an active supplier and purchase price for: '.$withoutSupplier->pluck('product_name')->implode(', ').'.']]);
            }

            $products = Product::query()->whereIn('id', $suggestions->pluck('product_id'))->get()->keyBy('id');
            $groups = $suggestions->groupBy(function ($suggestion) use ($products, $data): string {
                $product = $products->get($suggestion['product_id']);
                $warehouseId = $data['warehouse_id'] ?? $product?->default_warehouse_id ?? 0;

                return $suggestion['preferred_supplier']['supplier_id'].'|'.$suggestion['preferred_supplier']['currency'].'|'.$warehouseId;
            });
            $orders = [];
            foreach ($groups as $group) {
                $first = $group->first();
                $supplier = $first['preferred_supplier'];
                $currency = $supplier['currency'];
                $leadDays = (int) $group->max(fn ($item) => $item['preferred_supplier']['lead_time_days']);
                $orderedAt = $planning['as_of'];
                $product = $products->get($first['product_id']);
                $orders[] = $this->purchaseOrders->create([
                    'supplier_id' => $supplier['supplier_id'],
                    'warehouse_id' => $data['warehouse_id'] ?? $product?->default_warehouse_id,
                    'ordered_at' => $orderedAt,
                    'expected_at' => CarbonImmutable::parse($orderedAt)->addDays($leadDays)->toDateString(),
                    'due_at' => null,
                    'currency' => $currency,
                    'exchange_rate' => $currency === 'EUR' ? 1 : $supplier['exchange_rate_to_base'],
                    'exchange_rate_date' => $orderedAt,
                    'exchange_rate_source' => $currency === 'EUR' ? 'EUR base currency' : 'Supplier catalogue rate used by reviewed replenishment draft',
                    'status' => 'draft',
                    'notes' => $data['notes'] ?? 'Draft created from reviewed replenishment suggestions.',
                    'change_reason' => 'Authorized user converted selected replenishment suggestions to a draft purchase order.',
                    'items' => $group->map(function ($suggestion) use ($products): array {
                        $product = $products->get($suggestion['product_id']);

                        return [
                            'product_id' => $product->id,
                            'description' => $product->name,
                            'unit' => $product->unit,
                            'quantity' => $suggestion['recommended_quantity'],
                            'unit_price' => $suggestion['preferred_supplier']['purchase_price'],
                        ];
                    })->values()->all(),
                ]);
            }

            return $orders;
        });
    }

    private function incomingByProduct(Collection $orders, CarbonImmutable $asOf): Collection
    {
        $result = collect();
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $quantity = max(0, round(
                    (float) ($item->base_quantity ?? $item->quantity)
                    - (float) ($item->received_base_quantity ?? $item->received_quantity),
                    3,
                ));
                if ($quantity <= 0) {
                    continue;
                }
                $entry = $result->get($item->product_id, ['quantity' => 0.0, 'events' => []]);
                $entry['quantity'] = round($entry['quantity'] + $quantity, 3);
                $entry['events'][] = [
                    'date' => CarbonImmutable::parse($order->expected_at?->toDateString() ?? $asOf->toDateString())->max($asOf),
                    'quantity' => $quantity,
                    'purchase_order_id' => $order->id,
                ];
                $result->put($item->product_id, $entry);
            }
        }

        return $result;
    }

    private function expectedStockoutDate(CarbonImmutable $asOf, float $available, float $dailyUsage, array $events): ?string
    {
        if ($dailyUsage <= 0) {
            return null;
        }
        $stock = max(0, $available);
        $cursor = $asOf;
        usort($events, fn ($left, $right) => $left['date']->getTimestamp() <=> $right['date']->getTimestamp());
        foreach ($events as $event) {
            $days = max(0, $cursor->diffInDays($event['date']));
            $required = $days * $dailyUsage;
            if ($stock <= $required + 0.000001) {
                return $cursor->addDays((int) floor($stock / $dailyUsage))->toDateString();
            }
            $stock -= $required;
            $stock += $event['quantity'];
            $cursor = $event['date'];
        }

        return $cursor->addDays((int) floor($stock / $dailyUsage))->toDateString();
    }

    private function roundToPack(float $quantity, float $packSize): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        return round(ceil(($quantity - 0.000000001) / $packSize) * $packSize, 3);
    }
}
