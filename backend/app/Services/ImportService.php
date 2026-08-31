<?php

namespace App\Services;

use App\Models\ImportLog;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Support\CompanyCurrency;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportService
{
    private int $currentRow = 0;

    public function __construct(
        private ProductService $productService,
        private CategoryRepositoryInterface $categories,
        private SupplierCatalogueService $supplierCatalogue,
    ) {}

    public function importProducts(UploadedFile $file): ImportLog
    {
        $user = Auth::user();

        $log = ImportLog::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'type' => 'products',
            'filename' => $file->getClientOriginalName(),
            'status' => 'processing',
        ]);

        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle);
        $columns = collect($header ?: [])->mapWithKeys(
            fn ($name, $index) => [$this->normalizeHeader((string) $name) => $index]
        )->all();
        $imported = 0;
        $total = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $total++;
                $this->currentRow = $total;

                $name = trim((string) $this->csvValue($row, $columns, 'name'));
                $categoryName = trim((string) $this->csvValue($row, $columns, 'category'));
                if ($name === '' || $categoryName === '') {
                    $errors[] = "Row {$total}: name and category are required";

                    continue;
                }

                $category = $this->categories->findByName(trim($categoryName));

                if (! $category) {
                    $category = $this->categories->create([
                        'company_id' => $user->company_id,
                        'name' => trim($categoryName),
                    ]);
                }

                $sku = trim((string) $this->csvValue($row, $columns, 'sku'));
                if ($sku === '') {
                    $sku = $this->generateSku((int) $user->company_id);
                }
                $existing = Product::where('sku', $sku)->first();
                $quantity = $this->csvNumber($row, $columns, 'quantity', 0);
                $unit = trim((string) $this->csvValue($row, $columns, 'unit')) ?: 'pcs';
                $sellingPrice = $this->csvNumber(
                    $row,
                    $columns,
                    array_key_exists('selling_price', $columns) ? 'selling_price' : 'price',
                    0,
                );
                $statusProvided = array_key_exists('status', $columns);
                $status = $statusProvided
                    ? strtolower(trim((string) $this->csvValue($row, $columns, 'status')))
                    : ($existing?->lifecycle_status ?? 'active');
                if (! in_array($status, ['active', 'discontinued'], true)) {
                    $errors[] = "Row {$total}: invalid product status '{$status}'";

                    continue;
                }
                $supplierColumnPresent = array_key_exists('supplier', $columns)
                    || array_key_exists('preferred_supplier', $columns);
                $supplierName = trim((string) ($this->csvValue($row, $columns, 'supplier')
                    ?: $this->csvValue($row, $columns, 'preferred_supplier')));
                $supplier = $supplierName === '' ? null : Supplier::query()->where('name', $supplierName)->first();
                if ($supplierName !== '' && ! $supplier) {
                    $errors[] = "Row {$total}: supplier '{$supplierName}' does not exist";

                    continue;
                }
                $trackingMode = strtolower(trim((string) $this->csvValue($row, $columns, 'tracking_mode'))) ?: 'none';
                if (! in_array($trackingMode, TraceabilityService::MODES, true)) {
                    $errors[] = "Row {$total}: invalid tracking mode '{$trackingMode}'";

                    continue;
                }
                $alternativeBarcodes = collect(preg_split(
                    '/[;,\r\n]+/',
                    (string) $this->csvValue($row, $columns, 'alternative_barcodes'),
                ))->map(fn ($barcode) => trim((string) $barcode))->filter()->unique()->values()
                    ->map(fn ($barcode) => ['barcode' => $barcode, 'is_active' => true])->all();
                $attributes = $this->csvJson($row, $columns, 'attributes_json', [], $total);
                $unitConversions = $this->csvJson($row, $columns, 'fixed_unit_conversions_json', [], $total);
                if (! is_array($attributes) || ($attributes !== [] && array_is_list($attributes))) {
                    throw new \InvalidArgumentException("Row {$total}: Attributes JSON must be an object.");
                }
                if (! is_array($unitConversions) || ! array_is_list($unitConversions)) {
                    throw new \InvalidArgumentException("Row {$total}: Fixed Unit Conversions JSON must be an array.");
                }

                $data = [
                    'company_id' => $user->company_id,
                    'category_id' => $category->id,
                    'name' => $name,
                    'sku' => $sku,
                    'barcode' => trim((string) $this->csvValue($row, $columns, 'barcode')) ?: null,
                    'alternative_barcodes' => $alternativeBarcodes,
                    'quantity' => $quantity,
                    'min_quantity' => $this->csvNumber($row, $columns, 'min_quantity', 5),
                    'price' => $sellingPrice,
                    'selling_price' => $sellingPrice,
                    'purchase_price' => $this->csvNullableNumber($row, $columns, 'purchase_price'),
                    'unit' => $unit,
                    'unit_conversions' => $unitConversions,
                    'brand' => trim((string) $this->csvValue($row, $columns, 'brand')) ?: null,
                    'attributes' => $attributes,
                    'tracking_mode' => $trackingMode,
                    'expiration_controlled' => $this->csvBoolean($row, $columns, 'expiration_controlled', $trackingMode === 'batch_expiry'),
                    'default_shelf_life_days' => $this->csvNullableInteger($row, $columns, 'default_shelf_life_days'),
                    'shelf_life_basis' => strtolower(trim((string) $this->csvValue($row, $columns, 'shelf_life_basis'))) ?: 'manufacture_date',
                    'near_expiry_days' => $this->csvInteger($row, $columns, 'near_expiry_days', 30),
                    'fefo_enabled' => $this->csvBoolean($row, $columns, 'fefo_enabled', true),
                    'safety_stock' => $this->csvNumber($row, $columns, 'safety_stock', 0),
                    'reorder_point' => $this->csvNullableNumber($row, $columns, 'reorder_point'),
                    'replenishment_history_days' => $this->csvInteger($row, $columns, 'replenishment_history_days', 90),
                    'replenishment_review_days' => $this->csvInteger($row, $columns, 'replenishment_review_days', 14),
                    'weight_kg' => $this->csvNullableNumber($row, $columns, 'weight_kg'),
                    'length_cm' => $this->csvNullableNumber($row, $columns, 'length_cm'),
                    'width_cm' => $this->csvNullableNumber($row, $columns, 'width_cm'),
                    'height_cm' => $this->csvNullableNumber($row, $columns, 'height_cm'),
                    'volume_m3' => $this->csvNullableNumber($row, $columns, 'cbm'),
                    'country_of_origin' => trim((string) $this->csvValue($row, $columns, 'country_of_origin')) ?: null,
                    'hs_code' => trim((string) $this->csvValue($row, $columns, 'hs_code')) ?: null,
                    'lifecycle_status' => $status,
                    'description' => trim((string) $this->csvValue($row, $columns, 'description')) ?: null,
                ];
                $this->validateImportedProductData($data, $total);
                if ($supplierColumnPresent) {
                    // Supplier catalogue data is synchronized after the
                    // product so a foreign-currency price is never recorded
                    // temporarily as a company-base-currency price.
                    $data['supplier_id'] = null;
                }
                if ($existing) {
                    $data = $this->existingProductPayload($data, $columns, $supplierColumnPresent);
                }

                if ($existing) {
                    $saved = $this->productService->update($existing, $data, [
                        'reason' => "Product import {$log->filename} row {$total}",
                        'source_type' => 'import_log',
                        'source_id' => $log->id,
                        'idempotency_key' => "product-import-{$log->id}-row-{$total}",
                    ]);
                } else {
                    $saved = $this->productService->create($data, [
                        'reason' => "Opening balance from product import {$log->filename} row {$total}",
                        'source_type' => 'import_log',
                        'source_id' => $log->id,
                        'idempotency_key' => "product-import-{$log->id}-row-{$total}",
                    ]);
                }

                if ($supplierColumnPresent && $supplier) {
                    $this->syncSupplierCatalogueFromCsv($saved, $supplier, $row, $columns, $status, $total);
                }

                $imported++;
            }

            DB::commit();
            $log->update([
                'status' => empty($errors) ? 'completed' : 'completed_with_errors',
                'records_total' => $total,
                'records_imported' => $imported,
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $log->update([
                'status' => 'failed',
                'records_total' => $total,
                // The transaction was rolled back, so no product row from
                // this file was actually imported.
                'records_imported' => 0,
                'errors' => array_merge($errors, [$e->getMessage()]),
            ]);
        } finally {
            fclose($handle);
        }

        return $log->fresh();
    }

    private function normalizeHeader(string $header): string
    {
        return (string) Str::of($header)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
    }

    private function csvValue(array $row, array $columns, string $name): mixed
    {
        $index = $columns[$name] ?? null;

        return $index === null ? null : ($row[$index] ?? null);
    }

    private function csvNumber(array $row, array $columns, string $name, float $default): float
    {
        $value = trim((string) ($this->csvValue($row, $columns, $name) ?? ''));

        return $value === '' ? $default : $this->parseNumber($value, $name);
    }

    private function csvNullableNumber(array $row, array $columns, string $name): ?float
    {
        $value = trim((string) ($this->csvValue($row, $columns, $name) ?? ''));

        return $value === '' ? null : $this->parseNumber($value, $name);
    }

    private function csvInteger(array $row, array $columns, string $name, int $default): int
    {
        $value = trim((string) ($this->csvValue($row, $columns, $name) ?? ''));

        return $value === '' ? $default : $this->parseInteger($value, $name);
    }

    private function csvNullableInteger(array $row, array $columns, string $name): ?int
    {
        $value = trim((string) ($this->csvValue($row, $columns, $name) ?? ''));

        return $value === '' ? null : $this->parseInteger($value, $name);
    }

    private function csvBoolean(array $row, array $columns, string $name, bool $default): bool
    {
        $value = mb_strtolower(trim((string) ($this->csvValue($row, $columns, $name) ?? '')));
        if ($value === '') {
            return $default;
        }

        if (in_array($value, ['1', 'true', 'yes', 'on', 'po'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off', 'jo'], true)) {
            return false;
        }

        throw new \InvalidArgumentException("Row {$this->currentRow}: {$name} must be true/false or 1/0.");
    }

    private function parseNumber(string $value, string $name): float
    {
        $normalized = str_replace(',', '.', $value);
        if (! is_numeric($normalized)) {
            throw new \InvalidArgumentException("Row {$this->currentRow}: {$name} must contain a valid number.");
        }

        return (float) $normalized;
    }

    private function parseInteger(string $value, string $name): int
    {
        $number = $this->parseNumber($value, $name);
        if (abs($number - round($number)) >= 0.0000005) {
            throw new \InvalidArgumentException("Row {$this->currentRow}: {$name} must contain a whole number.");
        }

        return (int) round($number);
    }

    private function existingProductPayload(array $data, array $columns, bool $supplierColumnPresent): array
    {
        $payload = [
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'sku' => $data['sku'],
        ];
        $columnFields = [
            'barcode' => ['barcode'],
            'alternative_barcodes' => ['alternative_barcodes'],
            'quantity' => ['quantity'],
            'unit' => ['unit'],
            'min_quantity' => ['min_quantity'],
            'purchase_price' => ['purchase_price'],
            'selling_price' => ['selling_price', 'price'],
            'price' => ['selling_price', 'price'],
            'unit_conversions' => ['fixed_unit_conversions_json'],
            'brand' => ['brand'],
            'attributes' => ['attributes_json'],
            'tracking_mode' => ['tracking_mode'],
            'expiration_controlled' => ['expiration_controlled'],
            'default_shelf_life_days' => ['default_shelf_life_days'],
            'shelf_life_basis' => ['shelf_life_basis'],
            'near_expiry_days' => ['near_expiry_days'],
            'fefo_enabled' => ['fefo_enabled'],
            'safety_stock' => ['safety_stock'],
            'reorder_point' => ['reorder_point'],
            'replenishment_history_days' => ['replenishment_history_days'],
            'replenishment_review_days' => ['replenishment_review_days'],
            'weight_kg' => ['weight_kg'],
            'length_cm' => ['length_cm'],
            'width_cm' => ['width_cm'],
            'height_cm' => ['height_cm'],
            'volume_m3' => ['cbm', 'volume_m3'],
            'country_of_origin' => ['country_of_origin'],
            'hs_code' => ['hs_code'],
            'lifecycle_status' => ['status'],
            'description' => ['description'],
        ];
        foreach ($columnFields as $field => $headers) {
            if (collect($headers)->contains(fn ($header) => array_key_exists($header, $columns))) {
                $payload[$field] = $data[$field];
            }
        }
        if ($supplierColumnPresent) {
            $payload['supplier_id'] = $data['supplier_id'];
        }

        return $payload;
    }

    private function validateImportedProductData(array $data, int $rowNumber): void
    {
        foreach ([
            'quantity', 'min_quantity', 'price', 'selling_price', 'purchase_price',
            'safety_stock', 'reorder_point', 'weight_kg', 'length_cm', 'width_cm',
            'height_cm', 'volume_m3',
        ] as $field) {
            if ($data[$field] !== null && (float) $data[$field] < 0) {
                throw new \InvalidArgumentException("Row {$rowNumber}: {$field} cannot be negative.");
            }
        }
        foreach ([
            'default_shelf_life_days', 'replenishment_history_days', 'replenishment_review_days',
        ] as $field) {
            if ($data[$field] !== null && (int) $data[$field] <= 0) {
                throw new \InvalidArgumentException("Row {$rowNumber}: {$field} must be greater than zero.");
            }
        }
        if (count($data['alternative_barcodes']) > 20) {
            throw new \InvalidArgumentException("Row {$rowNumber}: at most 20 alternative barcodes are allowed.");
        }
        if (count($data['attributes']) > 50) {
            throw new \InvalidArgumentException("Row {$rowNumber}: at most 50 product attributes are allowed.");
        }
        if (count($data['unit_conversions']) > 20) {
            throw new \InvalidArgumentException("Row {$rowNumber}: at most 20 fixed unit conversions are allowed.");
        }
        $origin = strtoupper(trim((string) ($data['country_of_origin'] ?? '')));
        if ($origin !== '' && ! preg_match('/^[A-Z]{2}$/', $origin)) {
            throw new \InvalidArgumentException("Row {$rowNumber}: country_of_origin must be a two-letter ISO code.");
        }
        if ($data['hs_code'] && ! preg_match('/^[A-Za-z0-9. -]{1,32}$/', (string) $data['hs_code'])) {
            throw new \InvalidArgumentException("Row {$rowNumber}: hs_code contains unsupported characters.");
        }
        if (! in_array($data['shelf_life_basis'], ['manufacture_date', 'receipt_date'], true)) {
            throw new \InvalidArgumentException("Row {$rowNumber}: shelf_life_basis must be manufacture_date or receipt_date.");
        }
    }

    private function csvJson(array $row, array $columns, string $name, array $default, int $rowNumber): array
    {
        $value = trim((string) ($this->csvValue($row, $columns, $name) ?? ''));
        if ($value === '') {
            return $default;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException("Row {$rowNumber}: {$name} contains invalid JSON.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException("Row {$rowNumber}: {$name} must contain a JSON object or array.");
        }

        return $decoded;
    }

    private function syncSupplierCatalogueFromCsv(
        Product $product,
        Supplier $supplier,
        array $row,
        array $columns,
        string $status,
        int $rowNumber,
    ): void {
        $baseCurrency = CompanyCurrency::forCompanyId((int) $product->company_id);
        $catalogue = ProductSupplier::query()
            ->where('product_id', $product->id)
            ->where('supplier_id', $supplier->id)
            ->first();
        $currency = array_key_exists('supplier_currency', $columns)
            ? strtoupper(trim((string) $this->csvValue($row, $columns, 'supplier_currency')))
            : ($catalogue?->currency ?? $baseCurrency);
        $currency = $currency ?: $baseCurrency;
        if (! in_array($currency, CompanyCurrency::accepted($baseCurrency), true)) {
            throw new \InvalidArgumentException("Row {$rowNumber}: unsupported supplier currency '{$currency}'.");
        }
        $rateColumn = array_key_exists('exchange_rate_to_base_currency', $columns)
            ? 'exchange_rate_to_base_currency'
            : 'exchange_rate_to_eur';
        $rate = $currency === $baseCurrency
            ? 1.0
            : (array_key_exists($rateColumn, $columns)
                ? $this->csvNullableNumber($row, $columns, $rateColumn)
                : ($catalogue ? (float) $catalogue->exchange_rate_to_base : null));
        $rateDate = array_key_exists('exchange_rate_date', $columns)
            ? (trim((string) $this->csvValue($row, $columns, 'exchange_rate_date')) ?: null)
            : $catalogue?->exchange_rate_date?->toDateString();
        if ($currency !== $baseCurrency && (! $rate || ! $rateDate)) {
            throw new \InvalidArgumentException("Row {$rowNumber}: a foreign supplier currency requires Exchange Rate to {$baseCurrency} and Exchange Rate Date.");
        }

        $active = $status === 'active';
        $values = [
            'currency' => $currency,
            'exchange_rate_to_base' => $rate,
            'exchange_rate_date' => $currency === $baseCurrency ? null : $rateDate,
            'is_preferred' => $active,
            'is_active' => $active,
            'price_effective_at' => $rateDate ?: now()->toDateString(),
            'price_change_reason' => "Product CSV import row {$rowNumber}.",
        ];
        $optionalFields = [
            'supplier_sku' => fn () => trim((string) $this->csvValue($row, $columns, 'supplier_sku')) ?: null,
            'purchase_price' => fn () => $this->csvNullableNumber($row, $columns, 'purchase_price'),
            'pack_size' => fn () => $this->csvNumber($row, $columns, 'supplier_pack_size', 1),
            'minimum_order_quantity' => fn () => $this->csvNumber($row, $columns, 'minimum_order_quantity', 0),
            'usual_lead_time_days' => fn () => $this->csvInteger($row, $columns, 'lead_time_days', 0),
        ];
        $headers = [
            'supplier_sku' => 'supplier_sku',
            'purchase_price' => 'purchase_price',
            'pack_size' => 'supplier_pack_size',
            'minimum_order_quantity' => 'minimum_order_quantity',
            'usual_lead_time_days' => 'lead_time_days',
        ];
        foreach ($optionalFields as $field => $resolver) {
            if (! $catalogue || array_key_exists($headers[$field], $columns)) {
                $values[$field] = $resolver();
            }
        }
        if (array_key_exists('purchase_price', $values) && $values['purchase_price'] !== null && $values['purchase_price'] < 0) {
            throw new \InvalidArgumentException("Row {$rowNumber}: purchase_price cannot be negative.");
        }
        if (array_key_exists('pack_size', $values) && $values['pack_size'] <= 0) {
            throw new \InvalidArgumentException("Row {$rowNumber}: supplier_pack_size must be greater than zero.");
        }
        if (array_key_exists('minimum_order_quantity', $values) && $values['minimum_order_quantity'] < 0) {
            throw new \InvalidArgumentException("Row {$rowNumber}: minimum_order_quantity cannot be negative.");
        }
        if (array_key_exists('usual_lead_time_days', $values) && $values['usual_lead_time_days'] < 0) {
            throw new \InvalidArgumentException("Row {$rowNumber}: lead_time_days cannot be negative.");
        }

        if ($catalogue) {
            $this->supplierCatalogue->update($catalogue, $values);
        } else {
            $this->supplierCatalogue->create(array_merge($values, [
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
            ]));
        }
    }

    private function generateSku(int $companyId): string
    {
        do {
            $sku = 'AUTO-'.$companyId.'-'.strtoupper(Str::random(10));
        } while (Product::withoutGlobalScopes()->where('company_id', $companyId)->where('sku', $sku)->exists());

        return $sku;
    }
}
