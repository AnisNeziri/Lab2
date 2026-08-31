<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductSupplier;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Support\BarcodeIdentity;
use App\Support\CompanyCurrency;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductService
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private StockMovementService $stockMovements,
        private UnitConversionService $units,
        private WarehouseInventoryService $warehouseInventory,
        private SupplierCatalogueService $supplierCatalogue,
        private InventoryIntegrityService $inventoryIntegrity,
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->products->paginate($filters, $perPage);
    }

    public function lookup(string $sku): ?Product
    {
        return $this->products->findBySku($sku);
    }

    public function create(array $data, array $movementContext = []): Product
    {
        if (empty($data['unit'])) {
            $data['unit'] = 'pcs';
        }
        $this->assertProductQuantityPrecision($data, (string) $data['unit']);

        return DB::transaction(function () use ($data, $movementContext) {
            // Primary and alternative barcodes live in separate tables. A
            // shared company lock serializes assignment across both unique
            // namespaces and closes the cross-table concurrency race.
            DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->first();
            $unitDefinitions = $data['unit_conversions'] ?? [];
            $barcodes = $data['alternative_barcodes'] ?? [];
            $openingTraceAllocations = $data['opening_trace_allocations'] ?? [];
            unset($data['unit_conversions'], $data['alternative_barcodes'], $data['opening_trace_allocations']);
            $data = $this->normalizeMasterData($data);
            $this->assertBarcodeAvailability((int) $data['company_id'], $data['barcode'] ?? null, $barcodes);
            $openingQuantity = round((float) ($data['quantity'] ?? 0), 3);
            $data['quantity'] = 0;
            $product = $this->products->create($data);
            if ($product->supplier_id && ($product->lifecycle_status ?? 'active') === 'active') {
                $this->supplierCatalogue->create([
                    'product_id' => $product->id,
                    'supplier_id' => $product->supplier_id,
                    'purchase_price' => $product->purchase_price,
                    'currency' => CompanyCurrency::forCompanyId((int) $product->company_id),
                    'is_preferred' => true,
                    'is_active' => true,
                    'price_change_reason' => 'Initial price from product creation.',
                ]);
            }
            $this->warehouseInventory->ensureBalance($product, $product->default_warehouse_id);
            $this->syncUnits($product, $unitDefinitions);
            $this->syncBarcodes($product, $barcodes);

            if ($openingQuantity > 0) {
                $this->stockMovements->store(array_merge([
                    'product_id' => $product->id,
                    'type' => 'in',
                    'quantity' => $openingQuantity,
                    'reason' => 'Opening inventory balance',
                    'movement_code' => 'opening_balance',
                    'source_type' => 'product',
                    'source_id' => $product->id,
                    'idempotency_key' => 'product-opening-'.$product->id,
                    'warehouse_id' => $product->default_warehouse_id,
                    'trace_allocations' => $openingTraceAllocations,
                ], $movementContext));
            }

            return $product->fresh(['category', 'supplier', 'supplierCatalogue.supplier', 'alternativeBarcodes', 'defaultWarehouse', 'units', 'warehouseStock.warehouse', 'warehouseStock.location']);
        });
    }

    public function update(Product $product, array $data, array $movementContext = []): Product
    {
        if (array_key_exists('unit', $data) && empty($data['unit'])) {
            $data['unit'] = 'pcs';
        }
        $this->assertProductQuantityPrecision($data, (string) ($data['unit'] ?? $product->unit ?? 'pcs'));

        return DB::transaction(function () use ($product, $data, $movementContext) {
            DB::table('companies')->where('id', $product->company_id)->lockForUpdate()->first();
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $unitDefinitions = $data['unit_conversions'] ?? null;
            $barcodes = $data['alternative_barcodes'] ?? null;
            unset($data['unit_conversions'], $data['alternative_barcodes']);
            $data = $this->normalizeMasterData($data, $locked);
            if ($barcodes !== null || array_key_exists('barcode', $data)) {
                $this->assertBarcodeAvailability(
                    (int) $locked->company_id,
                    array_key_exists('barcode', $data) ? $data['barcode'] : $locked->barcode,
                    $barcodes ?? $locked->alternativeBarcodes()->get(['barcode', 'label', 'is_active'])->toArray(),
                    $locked->id,
                );
            }
            if (array_key_exists('tracking_mode', $data)
                && $data['tracking_mode'] !== $locked->tracking_mode
                && (float) $locked->quantity > 0.0005) {
                throw ValidationException::withMessages([
                    'tracking_mode' => ['Tracking mode can only change when the product has no stock. Count or move existing stock out first.'],
                ]);
            }
            if (array_key_exists('expiration_controlled', $data)
                && (bool) $data['expiration_controlled'] !== (bool) $locked->expiration_controlled
                && (float) $locked->quantity > 0.0005) {
                throw ValidationException::withMessages([
                    'expiration_controlled' => ['Expiration control can only change when the product has no stock.'],
                ]);
            }
            if (array_key_exists('unit', $data)
                && mb_strtolower(trim((string) $data['unit'])) !== mb_strtolower(trim((string) $locked->unit))
                && $locked->stockMovements()->exists()) {
                throw ValidationException::withMessages([
                    'unit' => ['The base inventory unit cannot be changed after stock history exists. Add a purchase or sales conversion instead.'],
                ]);
            }
            $targetQuantity = array_key_exists('quantity', $data)
                ? round((float) $data['quantity'], 3)
                : null;
            if ($targetQuantity !== null && abs($targetQuantity - round((float) $locked->quantity, 3)) >= 0.0005) {
                throw ValidationException::withMessages([
                    'quantity' => ['Inventory cannot be edited on the product master. Use Stock adjustment and choose the warehouse, location, reason and any required lot or serial details.'],
                ]);
            }
            $supplierWasProvided = array_key_exists('supplier_id', $data);
            $catalogueSupplierId = $supplierWasProvided ? $data['supplier_id'] : $locked->supplier_id;
            $cataloguePriceWasProvided = array_key_exists('purchase_price', $data);
            $cataloguePrice = $data['purchase_price'] ?? null;
            unset($data['quantity'], $data['quantity_change_reason']);

            $updated = $this->products->update($locked, $data);
            if (($updated->lifecycle_status ?? 'active') !== 'active') {
                // Keep supplier/catalogue history, but prevent a discontinued
                // or archived product from being selected for replenishment.
                ProductSupplier::query()->where('product_id', $updated->id)->update([
                    'is_preferred' => false,
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
            } elseif ($supplierWasProvided && ! $catalogueSupplierId) {
                $preferred = ProductSupplier::query()->where('product_id', $updated->id)->where('is_preferred', true)->first();
                if ($preferred) {
                    $this->supplierCatalogue->update($preferred, ['is_preferred' => false]);
                }
            } elseif ($catalogueSupplierId && ($supplierWasProvided || $cataloguePriceWasProvided)) {
                $catalogue = ProductSupplier::query()
                    ->where('product_id', $updated->id)
                    ->where('supplier_id', $catalogueSupplierId)
                    ->first();
                $catalogueData = [
                    'is_preferred' => true,
                    'is_active' => true,
                    'price_change_reason' => 'Updated from product maintenance.',
                ];
                if ($cataloguePriceWasProvided) {
                    $catalogueData['purchase_price'] = $cataloguePrice;
                }
                if ($catalogue) {
                    $this->supplierCatalogue->update($catalogue, $catalogueData);
                } else {
                    $this->supplierCatalogue->create(array_merge($catalogueData, [
                        'product_id' => $updated->id,
                        'supplier_id' => $catalogueSupplierId,
                        'purchase_price' => $cataloguePriceWasProvided ? $cataloguePrice : $updated->purchase_price,
                        'currency' => CompanyCurrency::forCompanyId((int) $updated->company_id),
                    ]));
                }
            }
            if ($unitDefinitions !== null) {
                $this->syncUnits($updated, $unitDefinitions);
            }
            if ($barcodes !== null) {
                $this->syncBarcodes($updated, $barcodes);
            }

            return $updated->fresh(['category', 'supplier', 'supplierCatalogue.supplier', 'alternativeBarcodes', 'defaultWarehouse', 'units', 'warehouseStock.warehouse', 'warehouseStock.location']);
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->inventoryIntegrity->assertProductCanArchive($locked);

            if ($this->hasBusinessReferences($locked)) {
                $locked->update([
                    'lifecycle_status' => 'archived',
                    'archived_at' => now(),
                    'discontinued_at' => $locked->discontinued_at ?: now(),
                ]);
                ProductSupplier::query()->where('product_id', $locked->id)->update([
                    'is_preferred' => false,
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

                return;
            }

            // Empty balance rows are operational cache, not business history.
            $locked->warehouseStock()->delete();
            $this->products->delete($locked);
        });
    }

    public function exportCsv(): StreamedResponse
    {
        $products = $this->products->allWithRelations();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="products.csv"',
        ];

        $callback = function () use ($products) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Name', 'SKU', 'Barcode', 'Alternative Barcodes', 'Category', 'Supplier',
                'Supplier SKU', 'Supplier Currency', 'Exchange Rate to Base Currency', 'Exchange Rate Date',
                'Supplier Pack Size', 'Minimum Order Quantity', 'Lead Time Days',
                'Quantity', 'Unit', 'Min Quantity', 'Purchase Price', 'Selling Price',
                'Fixed Unit Conversions JSON', 'Brand', 'Attributes JSON',
                'Tracking Mode', 'Expiration Controlled', 'Default Shelf Life Days', 'Shelf Life Basis',
                'Near Expiry Days', 'FEFO Enabled', 'Safety Stock', 'Reorder Point',
                'Replenishment History Days', 'Replenishment Review Days',
                'Weight KG', 'Length CM', 'Width CM', 'Height CM', 'CBM',
                'Country of Origin', 'HS Code', 'Status', 'Description',
            ]);

            foreach ($products as $product) {
                $catalogue = $product->supplierCatalogue->firstWhere('is_preferred', true)
                    ?? $product->supplierCatalogue->firstWhere('is_active', true);
                fputcsv($handle, [
                    $product->name,
                    $product->sku,
                    $product->barcode ?? '',
                    $product->alternativeBarcodes->where('is_active', true)->pluck('barcode')->implode(';'),
                    $product->category?->name ?? '',
                    $catalogue?->supplier?->name ?? $product->supplier?->name ?? '',
                    $catalogue?->supplier_sku ?? '',
                    $catalogue?->currency ?? CompanyCurrency::forCompanyId((int) $product->company_id),
                    $catalogue?->exchange_rate_to_base ?? 1,
                    $catalogue?->exchange_rate_date?->toDateString() ?? '',
                    $catalogue?->pack_size ?? 1,
                    $catalogue?->minimum_order_quantity ?? 0,
                    $catalogue?->usual_lead_time_days ?? 0,
                    $product->quantity,
                    $product->unit,
                    $product->min_quantity,
                    $catalogue?->purchase_price ?? $product->purchase_price ?? '',
                    $product->selling_price ?? $product->price,
                    json_encode($product->units->where('is_active', true)->map(fn ($unit) => [
                        'code' => $unit->code,
                        'label' => $unit->label,
                        'factor_to_base' => (float) $unit->factor_to_base,
                    ])->values()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $product->brand ?? '',
                    json_encode($product->attributes ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $product->tracking_mode ?? 'none',
                    $product->expiration_controlled ? '1' : '0',
                    $product->default_shelf_life_days ?? '',
                    $product->shelf_life_basis ?? 'manufacture_date',
                    $product->near_expiry_days ?? 30,
                    $product->fefo_enabled ? '1' : '0',
                    $product->safety_stock ?? 0,
                    $product->reorder_point ?? '',
                    $product->replenishment_history_days ?? 90,
                    $product->replenishment_review_days ?? 14,
                    $product->weight_kg ?? '',
                    $product->length_cm ?? '',
                    $product->width_cm ?? '',
                    $product->height_cm ?? '',
                    $product->volume_m3 ?? '',
                    $product->country_of_origin ?? '',
                    $product->hs_code ?? '',
                    $product->lifecycle_status ?? 'active',
                    $product->description ?? '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function globalSearch(string $term): Collection
    {
        return $this->products->searchGlobal($term);
    }

    private function syncUnits(Product $product, array $definitions): void
    {
        if (count($definitions) > 20) {
            throw ValidationException::withMessages(['unit_conversions' => ['At most 20 fixed unit conversions are allowed.']]);
        }
        $seen = [];
        foreach ($definitions as $definition) {
            $code = trim((string) ($definition['code'] ?? ''));
            if ($code === '' || mb_strlen($code) > 30 || mb_strtolower($code) === mb_strtolower((string) $product->unit)) {
                throw ValidationException::withMessages([
                    'unit_conversions' => ['Every alternative unit needs a code of at most 30 characters, different from the base inventory unit.'],
                ]);
            }
            if (mb_strlen(trim((string) ($definition['label'] ?? ''))) > 100) {
                throw ValidationException::withMessages(['unit_conversions' => ['Unit labels may not exceed 100 characters.']]);
            }
            $normalized = mb_strtolower($code);
            if (isset($seen[$normalized])) {
                throw ValidationException::withMessages(['unit_conversions' => ["The unit {$code} is listed more than once."]]);
            }
            $seen[$normalized] = true;
            $mode = 'fixed';
            $factor = round((float) ($definition['factor_to_base'] ?? 0), 6);
            if ($factor <= 0 || $factor > 1000000000) {
                throw ValidationException::withMessages(['unit_conversions' => ["Enter a positive quantity per {$code}."]]);
            }
            $this->units->assertPrecision($factor, $product->unit, 'unit_conversions');
            $product->units()->updateOrCreate(
                ['code' => $code],
                [
                    'company_id' => $product->company_id,
                    'label' => trim((string) ($definition['label'] ?? '')) ?: null,
                    // Every configured conversion is available anywhere a
                    // product quantity is entered. Inventory itself always
                    // remains in the product's smallest/base unit.
                    'allow_purchase' => true,
                    'allow_sale' => true,
                    'conversion_mode' => $mode,
                    'factor_to_base' => $factor,
                    'is_default_purchase' => false,
                    'is_default_sale' => false,
                    'is_active' => (bool) ($definition['is_active'] ?? true),
                ],
            );
        }
        $product->units()->whereNotIn('code', array_keys(array_flip(array_map(fn ($definition) => trim((string) $definition['code']), $definitions))))->delete();
    }

    private function normalizeMasterData(array $data, ?Product $existing = null): array
    {
        foreach (['brand', 'hs_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = trim((string) ($data[$field] ?? '')) ?: null;
            }
        }
        if (array_key_exists('barcode', $data)) {
            $data['barcode'] = BarcodeIdentity::display($data['barcode']);
            $data['barcode_normalized'] = BarcodeIdentity::normalize($data['barcode']);
        }
        if (array_key_exists('country_of_origin', $data)) {
            $data['country_of_origin'] = strtoupper(trim((string) ($data['country_of_origin'] ?? ''))) ?: null;
        }
        if (array_key_exists('attributes', $data)) {
            $data['attributes'] = collect($data['attributes'] ?? [])
                ->mapWithKeys(fn ($value, $key) => [trim((string) $key) => trim((string) ($value ?? ''))])
                ->filter(fn ($value, $key) => $key !== '' && $value !== '')
                ->all() ?: null;
        }

        $trackingMode = $data['tracking_mode'] ?? $existing?->tracking_mode ?? 'none';
        if ($trackingMode === 'batch_expiry') {
            $data['expiration_controlled'] = true;
        }
        $expirationControlled = array_key_exists('expiration_controlled', $data)
            ? (bool) $data['expiration_controlled']
            : (bool) ($existing?->expiration_controlled ?? false);
        if ($expirationControlled && ! in_array($trackingMode, ['batch', 'batch_expiry', 'serial'], true)) {
            throw ValidationException::withMessages([
                'expiration_controlled' => ['Expiration-controlled stock must use lot/batch or serial tracking.'],
            ]);
        }
        $shelfLifeBasis = $data['shelf_life_basis'] ?? $existing?->shelf_life_basis ?? 'manufacture_date';
        if (! in_array($shelfLifeBasis, ['manufacture_date', 'receipt_date'], true)) {
            throw ValidationException::withMessages([
                'shelf_life_basis' => ['Shelf life must start from the manufacture date or the receipt date.'],
            ]);
        }
        $data['shelf_life_basis'] = $shelfLifeBasis;
        if (! $expirationControlled && array_key_exists('default_shelf_life_days', $data)) {
            $data['default_shelf_life_days'] = null;
        }

        if ((! array_key_exists('volume_m3', $data) || $data['volume_m3'] === null)
            && collect(['length_cm', 'width_cm', 'height_cm'])->every(
                fn ($field) => (float) ($data[$field] ?? $existing?->{$field} ?? 0) > 0
            )) {
            $data['volume_m3'] = round(
                (float) ($data['length_cm'] ?? $existing->length_cm)
                * (float) ($data['width_cm'] ?? $existing->width_cm)
                * (float) ($data['height_cm'] ?? $existing->height_cm) / 1000000,
                6,
            );
        }

        if (array_key_exists('lifecycle_status', $data)) {
            $status = $data['lifecycle_status'];
            if ($status === 'active') {
                $data['discontinued_at'] = null;
                $data['archived_at'] = null;
            } elseif ($status === 'discontinued') {
                $data['discontinued_at'] = $existing?->discontinued_at ?: now();
                $data['archived_at'] = null;
            } elseif ($status === 'archived') {
                if ($existing) {
                    $this->inventoryIntegrity->assertProductCanArchive($existing, 'lifecycle_status');
                }
                $data['discontinued_at'] = $existing?->discontinued_at ?: now();
                $data['archived_at'] = $existing?->archived_at ?: now();
            }
        }

        return $data;
    }

    private function assertProductQuantityPrecision(array $data, string $unit): void
    {
        foreach (['quantity', 'min_quantity', 'safety_stock', 'reorder_point', 'high_stock_threshold'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $this->units->assertPrecision((float) $data[$field], $unit, $field);
            }
        }
    }

    private function assertBarcodeAvailability(int $companyId, ?string $primary, array $alternatives, ?int $productId = null): void
    {
        $primary = BarcodeIdentity::display($primary) ?? '';
        if (mb_strlen($primary) > 50 || count($alternatives) > 20) {
            throw ValidationException::withMessages(['alternative_barcodes' => ['The primary barcode may contain up to 50 characters and at most 20 alternatives are allowed.']]);
        }
        $rows = collect($alternatives)->map(fn ($row) => [
            'barcode' => BarcodeIdentity::display($row['barcode'] ?? null) ?? '',
            'label' => trim((string) ($row['label'] ?? '')) ?: null,
            'is_active' => (bool) ($row['is_active'] ?? true),
        ])->filter(fn ($row) => $row['barcode'] !== '')->values();
        if ($rows->contains(fn ($row) => mb_strlen($row['barcode']) > 100 || mb_strlen((string) ($row['label'] ?? '')) > 100)) {
            throw ValidationException::withMessages(['alternative_barcodes' => ['Alternative barcodes and their labels may not exceed 100 characters.']]);
        }
        $normalized = $rows->pluck('barcode')->map(fn ($value) => BarcodeIdentity::normalize($value));
        if ($normalized->duplicates()->isNotEmpty() || ($primary !== '' && $normalized->contains(BarcodeIdentity::normalize($primary)))) {
            throw ValidationException::withMessages(['alternative_barcodes' => ['Every barcode must be unique for this product.']]);
        }

        $all = $normalized->when($primary !== '', fn ($values) => $values->push(BarcodeIdentity::normalize($primary)))->all();
        if ($all === []) {
            return;
        }
        $primaryConflict = Product::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($productId, fn ($query) => $query->whereKeyNot($productId))
            ->whereIn('barcode_normalized', $all)
            ->exists();
        $alternativeConflict = ProductBarcode::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($productId, fn ($query) => $query->where('product_id', '!=', $productId))
            ->whereIn('barcode_normalized', $all)
            ->exists();
        if ($primaryConflict || $alternativeConflict) {
            throw ValidationException::withMessages(['alternative_barcodes' => ['One of these barcodes is already assigned to another product.']]);
        }
    }

    private function syncBarcodes(Product $product, array $rows): void
    {
        $barcodes = collect($rows)->map(fn ($row) => [
            'barcode' => BarcodeIdentity::display($row['barcode'] ?? null) ?? '',
            'barcode_normalized' => BarcodeIdentity::normalize($row['barcode'] ?? null),
            'label' => trim((string) ($row['label'] ?? '')) ?: null,
            'is_active' => (bool) ($row['is_active'] ?? true),
        ])->filter(fn ($row) => $row['barcode'] !== '')->values();
        foreach ($barcodes as $row) {
            $product->alternativeBarcodes()->updateOrCreate(
                ['barcode_normalized' => $row['barcode_normalized']],
                ['company_id' => $product->company_id, 'barcode' => $row['barcode'], 'label' => $row['label'], 'is_active' => $row['is_active']],
            );
        }
        $product->alternativeBarcodes()->whereNotIn('barcode_normalized', $barcodes->pluck('barcode_normalized')->all())->delete();
    }

    private function hasBusinessReferences(Product $product): bool
    {
        foreach ([
            'stock_movements', 'purchase_order_items', 'goods_receipt_items', 'daily_sale_items',
            'invoice_items', 'inventory_lots', 'inventory_count_items', 'stock_transfer_items',
            'inventory_return_items', 'landed_cost_allocations', 'landed_cost_accounting_entries',
            'product_suppliers',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'product_id')
                && DB::table($table)->where('product_id', $product->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
