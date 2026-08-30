<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\LandedCost;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LandedCostService
{
    // Kosovo company ledgers in AIMS are EUR-denominated. The landed-cost
    // record snapshots this explicitly; it is not a configurable currency
    // that could diverge from the rest of inventory valuation.
    public const BASE_CURRENCY = 'EUR';

    public const COST_TYPES = ['freight', 'customs', 'insurance', 'port', 'forwarding', 'inland_transport', 'handling', 'other'];

    public const ALLOCATION_METHODS = ['quantity', 'value', 'weight', 'volume', 'manual'];

    public function __construct(private readonly InventoryCostingService $costing) {}

    public function list(array $filters): LengthAwarePaginator
    {
        return LandedCost::query()
            ->with(['goodsReceipt:id,receipt_number', 'purchaseOrder:id,po_number', 'shipment:id,tracking_number', 'creator:id,name', 'poster:id,name'])
            ->withSum('allocations', 'allocated_amount')
            ->withSum('accountingEntries as inventory_adjustment_total', 'inventory_adjustment_amount')
            ->withSum('accountingEntries as cogs_adjustment_total', 'cogs_adjustment_amount')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['goods_receipt_id'] ?? null, fn ($query, $id) => $query->where('goods_receipt_id', $id))
            ->when($filters['shipment_id'] ?? null, fn ($query, $id) => $query->where('shipment_id', $id))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function find(LandedCost $landedCost): LandedCost
    {
        return $landedCost->load([
            'goodsReceipt:id,receipt_number,purchase_order_id', 'purchaseOrder:id,po_number,supplier_id',
            'shipment:id,tracking_number', 'creator:id,name', 'poster:id,name',
            'allocations.goodsReceiptItem:id,goods_receipt_id,product_id,accepted_base_quantity,damaged_base_quantity,base_purchase_unit_cost,landed_cost_allocated,final_inventory_unit_cost',
            'allocations.product:id,name,sku,unit',
            'allocations.accountingEntry.poster:id,name',
        ]);
    }

    public function createDraft(array $data): LandedCost
    {
        return DB::transaction(function () use ($data) {
            $companyId = (int) Auth::user()->company_id;
            $existing = LandedCost::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                $sameRequest = (int) $existing->goods_receipt_id === (int) $data['goods_receipt_id']
                    && $existing->cost_type === $data['cost_type']
                    && $existing->allocation_method === $data['allocation_method']
                    && strtoupper((string) $existing->currency) === strtoupper((string) $data['currency'])
                    && abs((float) $existing->amount - (float) $data['amount']) < 0.0000005;
                if (! $sameRequest) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was already used for a different landed cost.']]);
                }

                return $this->find($existing);
            }

            $receipt = GoodsReceipt::query()->with(['items.product', 'items.purchaseOrderItem', 'purchaseOrder'])->findOrFail($data['goods_receipt_id']);
            if (! empty($data['shipment_id'])) {
                $shipment = Shipment::query()->findOrFail($data['shipment_id']);
                if ($shipment->purchase_order_id && (int) $shipment->purchase_order_id !== (int) $receipt->purchase_order_id) {
                    throw ValidationException::withMessages(['shipment_id' => ['The shipment must belong to the receipt purchase order.']]);
                }
            }
            $method = $data['allocation_method'];
            $rate = strtoupper($data['currency']) === self::BASE_CURRENCY ? 1.0 : round((float) $data['exchange_rate_to_base'], 8);
            $amount = round((float) $data['amount'], 6);
            $baseAmount = round($amount * $rate, 2);
            if ($baseAmount <= 0) {
                throw ValidationException::withMessages(['amount' => ['The converted landed cost must be greater than zero.']]);
            }

            $items = $this->targetItems($receipt, $data, $method);
            $weights = $this->allocationWeights($items, $method, $data, $amount);
            $amounts = $this->allocateRounded($baseAmount, $weights);

            $landedCost = LandedCost::create([
                'company_id' => $companyId,
                'goods_receipt_id' => $receipt->id,
                'purchase_order_id' => $receipt->purchase_order_id,
                'shipment_id' => $data['shipment_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'cost_type' => $data['cost_type'],
                'description' => $data['description'] ?? null,
                'amount' => $amount,
                'currency' => strtoupper($data['currency']),
                'base_currency' => self::BASE_CURRENCY,
                'exchange_rate_to_base' => $rate,
                'exchange_rate_date' => $data['exchange_rate_date'] ?? (strtoupper($data['currency']) === self::BASE_CURRENCY ? now()->toDateString() : null),
                'base_currency_amount' => $baseAmount,
                'allocation_method' => $method,
                'status' => 'draft',
                'idempotency_key' => $data['idempotency_key'],
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            foreach ($items->values() as $index => $item) {
                $baseQuantity = $this->inventoryQuantity($item);
                $allocated = $amounts[$index];
                $priorLandedUnit = $baseQuantity > 0
                    ? round((float) $item->landed_cost_allocated / $baseQuantity, 6)
                    : 0.0;
                $allocatedUnit = round($allocated / $baseQuantity, 6);
                $landedCost->allocations()->create([
                    'company_id' => $companyId,
                    'goods_receipt_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'allocation_basis' => $weights[$index],
                    'allocated_amount' => $allocated,
                    'allocated_unit_cost' => $allocatedUnit,
                    'base_purchase_unit_cost_snapshot' => $item->base_purchase_unit_cost,
                    'final_unit_cost' => $item->base_purchase_unit_cost === null
                        ? null
                        : round((float) $item->base_purchase_unit_cost + $priorLandedUnit + $allocatedUnit, 6),
                ]);
            }

            return $this->find($landedCost);
        });
    }

    public function post(LandedCost $landedCost): LandedCost
    {
        return DB::transaction(function () use ($landedCost) {
            $landedCost = LandedCost::query()->lockForUpdate()->findOrFail($landedCost->id);
            if ($landedCost->status === 'posted') {
                return $this->find($landedCost);
            }
            if ($landedCost->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only a draft landed cost can be posted.']]);
            }

            $allocations = $landedCost->allocations()->orderBy('id')->lockForUpdate()->get();
            $allocatedTotal = round((float) $allocations->sum(fn ($allocation) => (float) $allocation->allocated_amount), 2);
            if (abs($allocatedTotal - round((float) $landedCost->base_currency_amount, 2)) > 0.000001) {
                throw ValidationException::withMessages(['allocations' => ['The allocation total no longer equals the landed-cost total.']]);
            }

            $postedAt = now();
            foreach ($allocations as $allocation) {
                $receiptItem = GoodsReceiptItem::query()->lockForUpdate()->findOrFail($allocation->goods_receipt_item_id);
                $product = Product::query()->lockForUpdate()->findOrFail($allocation->product_id);
                $baseQuantity = $this->inventoryQuantity($receiptItem);
                if ($baseQuantity <= 0) {
                    throw ValidationException::withMessages(['allocations' => ['A landed cost cannot be posted to a receipt line with no accepted inventory.']]);
                }

                $newLandedTotal = round((float) $receiptItem->landed_cost_allocated + (float) $allocation->allocated_amount, 6);
                $newLandedUnit = round($newLandedTotal / $baseQuantity, 6);
                $finalUnitCost = $receiptItem->base_purchase_unit_cost === null
                    ? null
                    : round((float) $receiptItem->base_purchase_unit_cost + $newLandedUnit, 6);
                $receiptItem->update([
                    'landed_cost_allocated' => $newLandedTotal,
                    'landed_cost_unit' => $newLandedUnit,
                    'final_inventory_unit_cost' => $finalUnitCost,
                ]);

                $recognition = $this->recognitionSplit(
                    $receiptItem,
                    $product,
                    (float) $allocation->allocated_amount,
                );
                $valuation = $this->costing->applyLandedCostAdjustment(
                    $product,
                    $recognition['inventory_adjustment_amount'],
                );
                $allocation->update([
                    'base_purchase_unit_cost_snapshot' => $receiptItem->base_purchase_unit_cost,
                    'final_unit_cost' => $finalUnitCost,
                    'weighted_average_cost_before' => $valuation['weighted_average_cost_before'],
                    'weighted_average_cost_after' => $valuation['weighted_average_cost_after'],
                    'inventory_value_before' => $valuation['inventory_value_before'],
                    'inventory_value_after' => $valuation['inventory_value_after'],
                ]);
                $allocation->accountingEntry()->create([
                    'company_id' => $landedCost->company_id,
                    'landed_cost_id' => $landedCost->id,
                    'goods_receipt_item_id' => $receiptItem->id,
                    'product_id' => $product->id,
                    'receipt_quantity' => $recognition['receipt_quantity'],
                    'remaining_quantity_at_post' => $recognition['remaining_quantity_at_post'],
                    'quantity_recognized_in_cogs' => $recognition['quantity_recognized_in_cogs'],
                    'inventory_adjustment_amount' => $recognition['inventory_adjustment_amount'],
                    'cogs_adjustment_amount' => $recognition['cogs_adjustment_amount'],
                    'calculation_method' => $recognition['calculation_method'],
                    'calculation_evidence' => $recognition['calculation_evidence'],
                    'posted_by' => Auth::id(),
                    'posted_at' => $postedAt,
                ]);
            }

            $landedCost->update([
                'status' => 'posted',
                'posted_by' => Auth::id(),
                'posted_at' => $postedAt,
            ]);

            return $this->find($landedCost->fresh());
        });
    }

    public function deleteDraft(LandedCost $landedCost): void
    {
        DB::transaction(function () use ($landedCost): void {
            $locked = LandedCost::query()->lockForUpdate()->findOrFail($landedCost->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Posted landed costs are permanent valuation history and cannot be deleted.']]);
            }
            $locked->delete();
        });
    }

    private function targetItems(GoodsReceipt $receipt, array $data, string $method): Collection
    {
        $requestedIds = collect($method === 'manual'
            ? array_column($data['allocations'] ?? [], 'goods_receipt_item_id')
            : ($data['goods_receipt_item_ids'] ?? $receipt->items->pluck('id')->all()))
            ->map(fn ($id) => (int) $id)->unique()->values();

        $items = $receipt->items->whereIn('id', $requestedIds)->values();
        if ($items->count() !== $requestedIds->count()) {
            throw ValidationException::withMessages(['goods_receipt_item_ids' => ['Every allocation line must belong to the selected goods receipt.']]);
        }
        $items = $items->filter(fn (GoodsReceiptItem $item) => $this->inventoryQuantity($item) > 0)->values();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['goods_receipt_item_ids' => ['Select at least one receipt line with accepted or damaged inventory.']]);
        }

        return $items;
    }

    private function allocationWeights(Collection $items, string $method, array $data, float $documentAmount): array
    {
        if ($method === 'manual') {
            $manual = collect($data['allocations'])->keyBy(fn ($row) => (int) $row['goods_receipt_item_id']);
            $weights = $items->map(fn ($item) => round((float) $manual->get($item->id)['amount'], 6))->all();
            if (abs(array_sum($weights) - $documentAmount) > 0.005) {
                throw ValidationException::withMessages(['allocations' => ['Manual amounts must add up exactly to the landed cost amount.']]);
            }

            return $weights;
        }

        $weights = $items->map(function (GoodsReceiptItem $item) use ($method): float {
            $quantity = $this->inventoryQuantity($item);

            return match ($method) {
                'quantity' => $quantity,
                'value' => (float) ($item->base_purchase_cost ?? 0),
                'weight' => $quantity * (float) ($item->product?->weight_kg ?? 0),
                'volume' => $quantity * (float) ($item->product?->volume_m3 ?? 0),
                default => 0.0,
            };
        })->all();

        if (array_sum($weights) <= 0) {
            $field = match ($method) {
                'weight' => 'product weights',
                'volume' => 'product volumes',
                'value' => 'received purchase values',
                default => 'received quantities',
            };
            throw ValidationException::withMessages(['allocation_method' => ["The selected receipt lines need positive {$field} for this allocation method."]]);
        }

        return $weights;
    }

    /** Allocate whole cents by largest remainder so the stored lines equal the header exactly. */
    private function allocateRounded(float $total, array $weights): array
    {
        $totalCents = (int) round($total * 100);
        $weightTotal = array_sum($weights);
        $floors = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $raw = $totalCents * $weight / $weightTotal;
            $floors[$index] = (int) floor($raw);
            $remainders[$index] = $raw - $floors[$index];
        }

        $remaining = $totalCents - array_sum($floors);
        $order = array_keys($remainders);
        usort($order, fn ($left, $right) => $remainders[$right] <=> $remainders[$left] ?: $left <=> $right);
        for ($position = 0; $position < $remaining; $position++) {
            $floors[$order[$position % count($order)]]++;
        }
        ksort($floors);

        return array_map(fn (int $cents): float => $cents / 100, $floors);
    }

    private function inventoryQuantity(GoodsReceiptItem $item): float
    {
        return round((float) $item->accepted_base_quantity + (float) $item->damaged_base_quantity, 3);
    }

    /**
     * A late landed-cost bill belongs partly to stock still on hand and partly
     * to units already issued. AIMS uses perpetual weighted-average costing, so
     * each later outbound movement consumes the receipt's notional share of the
     * cost pool in the same proportion as it consumes the full stock pool.
     *
     * This keeps the calculation deterministic without pretending that an
     * untracked product has FIFO layers. The evidence stored with the posting
     * makes the split independently auditable. Legacy receipts without their
     * original movement use a clearly labelled current-stock-cap fallback.
     */
    private function recognitionSplit(
        GoodsReceiptItem $receiptItem,
        Product $product,
        float $allocatedAmount,
    ): array {
        $lineQuantity = $this->inventoryQuantity($receiptItem);
        $receiptProductItems = GoodsReceiptItem::query()
            ->where('goods_receipt_id', $receiptItem->goods_receipt_id)
            ->where('product_id', $product->id)
            ->get(['accepted_base_quantity', 'damaged_base_quantity']);
        $receiptProductQuantity = round((float) $receiptProductItems->sum(
            fn (GoodsReceiptItem $item) => $this->inventoryQuantity($item)
        ), 3);

        $receiptMovements = StockMovement::withoutGlobalScopes()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('source_type', 'goods_receipt')
            ->where('source_id', $receiptItem->goods_receipt_id)
            ->where('type', 'in')
            ->where('affects_company_quantity', true)
            ->whereIn('movement_code', ['purchase_receipt', 'damage_received'])
            ->orderBy('id')
            ->get(['id', 'quantity']);

        $currentQuantity = round(max(0, (float) $product->quantity), 3);
        $receiptMovementQuantity = round((float) $receiptMovements->sum('quantity'), 3);
        $method = 'moving_average_pool_v1';
        $lastReceiptMovementId = $receiptMovements->isEmpty() ? null : (int) $receiptMovements->max('id');
        $laterOutboundCount = 0;
        $firstLaterOutboundId = null;
        $lastLaterOutboundId = null;

        if ($receiptMovements->isEmpty() || $receiptProductQuantity <= 0) {
            $fallbackPool = min($receiptProductQuantity > 0 ? $receiptProductQuantity : $lineQuantity, $currentQuantity);
            $lineShare = $receiptProductQuantity > 0 ? $lineQuantity / $receiptProductQuantity : 1;
            $remainingLineQuantity = round(min($lineQuantity, $fallbackPool * $lineShare), 3);
            $method = 'current_stock_cap_fallback_v1';
        } else {
            // The receipt is recorded atomically, so all same-product receipt
            // movements are present before any later business transaction.
            $receiptPool = min($receiptProductQuantity, $receiptMovementQuantity);

            $laterOutbounds = StockMovement::withoutGlobalScopes()
                ->where('company_id', $product->company_id)
                ->where('product_id', $product->id)
                ->where('id', '>', $lastReceiptMovementId)
                ->where('type', 'out')
                ->where('affects_company_quantity', true)
                ->orderBy('id')
                ->get(['id', 'quantity', 'quantity_before', 'quantity_after']);

            foreach ($laterOutbounds as $movement) {
                if ($receiptPool <= 0.0005) {
                    break;
                }
                $quantityBefore = max(0, (float) $movement->quantity_before);
                if ($quantityBefore <= 0.0005) {
                    $receiptPool = 0.0;
                } else {
                    $issued = min($quantityBefore, max(0, (float) $movement->quantity));
                    $receiptPool = round($receiptPool * (($quantityBefore - $issued) / $quantityBefore), 9);
                }
                $movementId = (int) $movement->id;
                $firstLaterOutboundId ??= $movementId;
                $lastLaterOutboundId = $movementId;
                $laterOutboundCount++;
            }

            $receiptPool = min(max(0, $receiptPool), $currentQuantity, $receiptProductQuantity);
            $lineShare = $receiptProductQuantity > 0 ? $lineQuantity / $receiptProductQuantity : 0;
            $remainingLineQuantity = round(min($lineQuantity, $receiptPool * $lineShare), 3);
        }

        $soldQuantity = round(max(0, $lineQuantity - $remainingLineQuantity), 3);
        $inventoryAmount = $lineQuantity > 0
            ? round($allocatedAmount * ($remainingLineQuantity / $lineQuantity), 6)
            : 0.0;
        $cogsAmount = round($allocatedAmount - $inventoryAmount, 6);

        return [
            'receipt_quantity' => $lineQuantity,
            'remaining_quantity_at_post' => $remainingLineQuantity,
            'quantity_recognized_in_cogs' => $soldQuantity,
            'inventory_adjustment_amount' => $inventoryAmount,
            'cogs_adjustment_amount' => $cogsAmount,
            'calculation_method' => $method,
            'calculation_evidence' => [
                'receipt_product_quantity' => $receiptProductQuantity,
                'receipt_movement_quantity' => $receiptMovementQuantity,
                'receipt_movement_count' => $receiptMovements->count(),
                'first_receipt_movement_id' => $receiptMovements->isEmpty() ? null : (int) $receiptMovements->min('id'),
                'last_receipt_movement_id' => $lastReceiptMovementId,
                'later_outbound_movement_count' => $laterOutboundCount,
                'first_later_outbound_movement_id' => $firstLaterOutboundId,
                'last_later_outbound_movement_id' => $lastLaterOutboundId,
                'product_quantity_at_post' => $currentQuantity,
                'amount_check' => round($inventoryAmount + $cogsAmount, 6),
            ],
        ];
    }
}
