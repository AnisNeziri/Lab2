<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($data, $movementContext) {
            $unitDefinitions = $data['unit_conversions'] ?? [];
            $openingTraceAllocations = $data['opening_trace_allocations'] ?? [];
            unset($data['unit_conversions'], $data['opening_trace_allocations']);
            $openingQuantity = round((float) ($data['quantity'] ?? 0), 3);
            $data['quantity'] = 0;
            $product = $this->products->create($data);
            if ($product->supplier_id) {
                $this->supplierCatalogue->create([
                    'product_id' => $product->id,
                    'supplier_id' => $product->supplier_id,
                    'purchase_price' => $product->purchase_price,
                    'currency' => 'EUR',
                    'is_preferred' => true,
                    'is_active' => true,
                    'price_change_reason' => 'Initial price from product creation.',
                ]);
            }
            $this->warehouseInventory->ensureBalance($product, $product->default_warehouse_id);
            $this->syncUnits($product, $unitDefinitions);

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

            return $product->fresh(['category', 'supplier', 'supplierCatalogue.supplier', 'defaultWarehouse', 'units', 'warehouseStock.warehouse', 'warehouseStock.location']);
        });
    }

    public function update(Product $product, array $data, array $movementContext = []): Product
    {
        if (array_key_exists('unit', $data) && empty($data['unit'])) {
            $data['unit'] = 'pcs';
        }

        return DB::transaction(function () use ($product, $data, $movementContext) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $unitDefinitions = $data['unit_conversions'] ?? null;
            unset($data['unit_conversions']);
            if (array_key_exists('tracking_mode', $data)
                && $data['tracking_mode'] !== $locked->tracking_mode
                && (float) $locked->quantity > 0.0005) {
                throw ValidationException::withMessages([
                    'tracking_mode' => ['Tracking mode can only change when the product has no stock. Count or move existing stock out first.'],
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
            $quantityChangeReason = trim((string) ($data['quantity_change_reason'] ?? ($movementContext['reason'] ?? '')));
            $supplierWasProvided = array_key_exists('supplier_id', $data);
            $catalogueSupplierId = $supplierWasProvided ? $data['supplier_id'] : $locked->supplier_id;
            $cataloguePriceWasProvided = array_key_exists('purchase_price', $data);
            $cataloguePrice = $data['purchase_price'] ?? null;
            unset($data['quantity'], $data['quantity_change_reason']);

            $updated = $this->products->update($locked, $data);
            if ($supplierWasProvided && ! $catalogueSupplierId) {
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
                        'currency' => 'EUR',
                    ]));
                }
            }
            if ($unitDefinitions !== null) {
                $this->syncUnits($updated, $unitDefinitions);
            }

            if ($targetQuantity !== null) {
                $currentQuantity = round((float) $updated->quantity, 3);
                $difference = round($targetQuantity - $currentQuantity, 3);
                if (abs($difference) >= 0.0005) {
                    if ($quantityChangeReason === '') {
                        throw ValidationException::withMessages([
                            'quantity_change_reason' => ['Explain why the inventory quantity is being changed.'],
                        ]);
                    }
                    $type = $difference > 0 ? 'in' : 'out';
                    $this->stockMovements->store(array_merge([
                        'product_id' => $updated->id,
                        'type' => $type,
                        'quantity' => abs($difference),
                        'reason' => $quantityChangeReason,
                        'movement_code' => $type === 'in' ? 'manual_adjustment_in' : 'manual_adjustment_out',
                        'source_type' => 'product_edit',
                        'source_id' => $updated->id,
                    ], $movementContext));
                }
            }

            return $updated->fresh(['category', 'supplier', 'supplierCatalogue.supplier', 'defaultWarehouse', 'units', 'warehouseStock.warehouse', 'warehouseStock.location']);
        });
    }

    public function delete(Product $product): void
    {
        if ($product->stockMovements()->exists()) {
            throw ValidationException::withMessages([
                'product' => ['This product has inventory history and cannot be deleted. Keep it for audit history.'],
            ]);
        }

        $usedByIssuedInvoice = $product->invoiceItems()
            ->whereHas('invoice', fn ($query) => $query
                ->whereNotNull('issued_at')
                ->whereNotIn('status', ['void']))
            ->exists();
        if ($usedByIssuedInvoice) {
            throw ValidationException::withMessages([
                'product' => ['This product is referenced by an issued invoice and must be retained for invoice and credit-note history.'],
            ]);
        }
        $this->products->delete($product);
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
            fputcsv($handle, ['Name', 'SKU', 'Barcode', 'Category', 'Supplier', 'Quantity', 'Unit', 'Min Quantity', 'Price', 'Description']);

            foreach ($products as $product) {
                fputcsv($handle, [
                    $product->name,
                    $product->sku,
                    $product->barcode ?? '',
                    $product->category?->name ?? '',
                    $product->supplier?->name ?? '',
                    $product->quantity,
                    $product->unit,
                    $product->min_quantity,
                    $product->price,
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
        $seen = [];
        foreach ($definitions as $definition) {
            $code = trim((string) ($definition['code'] ?? ''));
            if ($code === '' || mb_strtolower($code) === mb_strtolower((string) $product->unit)) {
                throw ValidationException::withMessages([
                    'unit_conversions' => ['Every alternative unit needs a unique code different from the base inventory unit.'],
                ]);
            }
            $normalized = mb_strtolower($code);
            if (isset($seen[$normalized])) {
                throw ValidationException::withMessages(['unit_conversions' => ["The unit {$code} is listed more than once."]]);
            }
            $seen[$normalized] = true;
            $mode = 'fixed';
            $factor = round((float) ($definition['factor_to_base'] ?? 0), 6);
            if ($factor <= 0) {
                throw ValidationException::withMessages(['unit_conversions' => ["Enter a positive quantity per {$code}."]]);
            }
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
}
