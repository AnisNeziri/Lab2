<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\CompanyCurrency;

class SupplierCatalogueService
{
    public function list(array $filters): LengthAwarePaginator
    {
        return ProductSupplier::query()
            ->with(['product:id,name,sku,unit', 'supplier:id,name', 'updater:id,name'])
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', (bool) $filters['active']))
            ->orderByDesc('is_preferred')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);
    }

    public function create(array $data): ProductSupplier
    {
        return DB::transaction(function () use ($data) {
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            Supplier::query()->findOrFail($data['supplier_id']);
            if (ProductSupplier::query()->where('product_id', $product->id)->where('supplier_id', $data['supplier_id'])->exists()) {
                throw ValidationException::withMessages(['supplier_id' => ['This supplier is already in the product catalogue.']]);
            }

            $values = $this->normalizedValues($data, null, CompanyCurrency::forCompanyId((int) $product->company_id));
            $willBeActive = (bool) ($values['is_active'] ?? true);
            $makePreferred = $willBeActive && (
                (bool) ($values['is_preferred'] ?? false)
                || ! ProductSupplier::query()->where('product_id', $product->id)->where('is_active', true)->exists()
            );
            if ($willBeActive || $makePreferred) {
                $this->assertProductMayBeReplenished($product);
            }
            if ($makePreferred && ! $values['is_active']) {
                throw ValidationException::withMessages(['is_preferred' => ['An inactive catalogue item cannot be preferred.']]);
            }
            if ($makePreferred) {
                ProductSupplier::query()->where('product_id', $product->id)->update(['is_preferred' => false]);
            }

            $catalogue = ProductSupplier::create(array_merge($values, [
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'supplier_id' => $data['supplier_id'],
                'is_preferred' => $makePreferred,
                'last_price_changed_at' => array_key_exists('purchase_price', $values) && $values['purchase_price'] !== null ? now() : null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]));
            $this->recordPrice($catalogue, $data['price_change_reason'] ?? 'Initial supplier catalogue price.', $data['price_effective_at'] ?? now());
            $this->syncLegacyPreferred($product);

            return $this->load($catalogue);
        });
    }

    public function update(ProductSupplier $catalogue, array $data): ProductSupplier
    {
        return DB::transaction(function () use ($catalogue, $data) {
            $catalogue = ProductSupplier::query()->lockForUpdate()->findOrFail($catalogue->id);
            $product = Product::query()->lockForUpdate()->findOrFail($catalogue->product_id);
            $oldPrice = $catalogue->purchase_price;
            $oldCurrency = $catalogue->currency;
            $oldRate = $catalogue->exchange_rate_to_base;
            $oldRateDate = $catalogue->exchange_rate_date?->toDateString();
            $values = $this->normalizedValues($data, $catalogue, CompanyCurrency::forCompanyId((int) $product->company_id));

            if (($values['is_active'] ?? $catalogue->is_active) || ($values['is_preferred'] ?? $catalogue->is_preferred)) {
                $this->assertProductMayBeReplenished($product);
            }

            if (($values['is_preferred'] ?? $catalogue->is_preferred) && ! ($values['is_active'] ?? $catalogue->is_active)) {
                throw ValidationException::withMessages(['is_preferred' => ['An inactive catalogue item cannot be preferred.']]);
            }
            if ($values['is_preferred'] ?? false) {
                ProductSupplier::query()
                    ->where('product_id', $product->id)
                    ->whereKeyNot($catalogue->id)
                    ->update(['is_preferred' => false, 'updated_at' => now()]);
            }

            $catalogue->update(array_merge($values, ['updated_by' => Auth::id()]));
            $priceChanged = (string) $oldPrice !== (string) $catalogue->purchase_price
                || $oldCurrency !== $catalogue->currency
                || (string) $oldRate !== (string) $catalogue->exchange_rate_to_base
                || $oldRateDate !== $catalogue->exchange_rate_date?->toDateString();
            if ($priceChanged) {
                if ($catalogue->purchase_price === null) {
                    throw ValidationException::withMessages(['purchase_price' => ['A supplier price can be replaced, but not erased after price history exists.']]);
                }
                $catalogue->update(['last_price_changed_at' => now()]);
                $this->recordPrice($catalogue, $data['price_change_reason'] ?? null, $data['price_effective_at'] ?? now());
            }

            $this->syncLegacyPreferred($product);

            return $this->load($catalogue->fresh());
        });
    }

    public function deactivate(ProductSupplier $catalogue): ProductSupplier
    {
        return $this->update($catalogue, ['is_active' => false, 'is_preferred' => false]);
    }

    public function priceHistory(ProductSupplier $catalogue): array
    {
        return $catalogue->priceHistory()->with('changedBy:id,name')->get()->all();
    }

    public function preferredFor(Product $product): ?ProductSupplier
    {
        return ProductSupplier::query()
            ->with('supplier:id,name')
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderByDesc('is_preferred')
            ->orderBy('usual_lead_time_days')
            ->orderByRaw('purchase_price IS NULL')
            ->orderBy('purchase_price')
            ->first();
    }

    public function performance(Supplier $supplier): array
    {
        $orders = PurchaseOrder::query()
            ->with(['items', 'goodsReceipts:id,purchase_order_id,received_at'])
            ->where('supplier_id', $supplier->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->oldest('ordered_at')
            ->oldest('id')
            ->get();

        $ordered = 0.0;
        $received = 0.0;
        $shortages = 0.0;
        $overDeliveries = 0.0;
        $deliveryLeadDays = [];
        $onTime = 0;
        $late = 0;
        $assessedDeliveries = 0;
        $purchaseValue = 0.0;
        $lastPrices = [];
        $observedPriceChanges = 0;

        foreach ($orders as $order) {
            $purchaseValue += (float) ($order->total_amount_eur ?? ((float) $order->total_amount * (float) ($order->exchange_rate ?: 1)));
            foreach ($order->items as $item) {
                $orderedQuantity = (float) ($item->base_quantity ?? $item->quantity);
                $receivedQuantity = (float) ($item->received_base_quantity ?? $item->received_quantity);
                $ordered += $orderedQuantity;
                $received += $receivedQuantity;
                if (in_array($order->status, ['received', 'completed'], true)) {
                    $shortages += max(0, $orderedQuantity - $receivedQuantity);
                    $overDeliveries += max(0, $receivedQuantity - $orderedQuantity);
                }

                $priceKey = ($item->product_id ?? 'custom').'|'.mb_strtolower((string) $item->unit).'|'.$order->currency;
                $price = round((float) $item->unit_price, 6);
                if (array_key_exists($priceKey, $lastPrices) && abs($lastPrices[$priceKey] - $price) > 0.0000005) {
                    $observedPriceChanges++;
                }
                $lastPrices[$priceKey] = $price;
            }

            foreach ($order->goodsReceipts as $receipt) {
                if ($order->ordered_at) {
                    $deliveryLeadDays[] = $order->ordered_at->startOfDay()->diffInDays($receipt->received_at->startOfDay());
                }
                if ($order->expected_at) {
                    $assessedDeliveries++;
                    if ($receipt->received_at->toDateString() <= $order->expected_at->toDateString()) {
                        $onTime++;
                    } else {
                        $late++;
                    }
                }
            }
        }

        $catalogue = ProductSupplier::query()->where('supplier_id', $supplier->id)->get();
        $cataloguePriceEntries = $catalogue->sum(fn ($item) => $item->priceHistory()->count());

        return [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'purchase_order_count' => $orders->count(),
            'delivery_count' => $orders->sum(fn ($order) => $order->goodsReceipts->count()),
            'average_lead_time_days' => $deliveryLeadDays === [] ? null : round(array_sum($deliveryLeadDays) / count($deliveryLeadDays), 1),
            'on_time_delivery_rate' => $assessedDeliveries === 0 ? null : round($onTime * 100 / $assessedDeliveries, 1),
            'on_time_deliveries' => $onTime,
            'late_deliveries' => $late,
            'ordered_base_quantity' => round($ordered, 3),
            'received_base_quantity' => round($received, 3),
            'fill_rate' => $ordered <= 0 ? null : round(min(100, $received * 100 / $ordered), 1),
            'shortage_base_quantity' => round($shortages, 3),
            'over_delivery_base_quantity' => round($overDeliveries, 3),
            'purchase_value_eur' => round($purchaseValue, 2),
            'observed_po_price_changes' => $observedPriceChanges,
            'catalogue_price_changes' => max(0, $cataloguePriceEntries - $catalogue->whereNotNull('purchase_price')->count()),
            'active_catalogue_products' => $catalogue->where('is_active', true)->count(),
        ];
    }

    private function normalizedValues(array $data, ?ProductSupplier $existing = null, ?string $baseCurrency = null): array
    {
        $baseCurrency = CompanyCurrency::normalize($baseCurrency);
        $currency = strtoupper((string) ($data['currency'] ?? $existing?->currency ?? $baseCurrency));
        $rate = $currency === $baseCurrency
            ? 1.0
            : round((float) ($data['exchange_rate_to_base'] ?? $existing?->exchange_rate_to_base ?? 0), 8);
        if ($rate <= 0) {
            throw ValidationException::withMessages(['exchange_rate_to_base' => ["Enter a positive conversion rate from the supplier currency to {$baseCurrency}."]]);
        }

        $keys = [
            'supplier_sku', 'purchase_price', 'pack_size', 'minimum_order_quantity',
            'usual_lead_time_days', 'is_preferred', 'is_active', 'supplier_description',
            'exchange_rate_date',
        ];
        $values = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $values[$key] = $data[$key];
            }
        }
        $values['currency'] = $currency;
        $values['exchange_rate_to_base'] = $rate;
        $values['exchange_rate_date'] = $currency === $baseCurrency
            ? null
            : ($data['exchange_rate_date'] ?? $existing?->exchange_rate_date?->toDateString());
        if (! $existing) {
            $values += ['pack_size' => 1, 'minimum_order_quantity' => 0, 'usual_lead_time_days' => 0, 'is_preferred' => false, 'is_active' => true];
        }

        return $values;
    }

    private function recordPrice(ProductSupplier $catalogue, ?string $reason, mixed $effectiveAt): void
    {
        if ($catalogue->purchase_price === null) {
            return;
        }
        $catalogue->priceHistory()->create([
            'company_id' => $catalogue->company_id,
            'purchase_price' => $catalogue->purchase_price,
            'currency' => $catalogue->currency,
            'exchange_rate_to_base' => $catalogue->exchange_rate_to_base,
            'exchange_rate_date' => $catalogue->exchange_rate_date?->toDateString(),
            'base_currency_price' => $catalogue->base_currency_price,
            'effective_at' => $effectiveAt,
            'changed_by' => Auth::id(),
            'change_reason' => $reason,
        ]);
    }

    private function assertProductMayBeReplenished(Product $product): void
    {
        if (($product->lifecycle_status ?? 'active') !== 'active') {
            throw ValidationException::withMessages([
                'product_id' => ['Only active products can receive a new or active supplier catalogue entry. Discontinued stock remains available for sale and returns, but it cannot be replenished.'],
            ]);
        }
    }

    private function syncLegacyPreferred(Product $product): void
    {
        $preferred = ProductSupplier::query()
            ->where('product_id', $product->id)
            ->where('is_preferred', true)
            ->where('is_active', true)
            ->first();
        $product->update([
            'supplier_id' => $preferred?->supplier_id,
            // This remains the preferred supplier's base purchase price. The
            // weighted average and landed cost are stored in separate fields.
            'purchase_price' => $preferred?->base_currency_price,
        ]);
    }

    private function load(ProductSupplier $catalogue): ProductSupplier
    {
        return $catalogue->load(['product:id,name,sku,unit', 'supplier:id,name', 'priceHistory.changedBy:id,name']);
    }
}
