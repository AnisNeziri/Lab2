<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductMasterHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_master_supports_optional_identifiers_import_data_and_default_shelf_life(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Imported hardware']));

        $created = $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Imported handle',
            'sku' => 'HANDLE-MASTER-1',
            'barcode' => '100000001',
            'alternative_barcodes' => [['barcode' => 'ALT-HANDLE-1', 'label' => 'Carton label']],
            'brand' => 'AIMS Hardware',
            'attributes' => ['finish' => 'matte', 'colour' => 'black'],
            'quantity' => 0,
            'unit' => 'pcs',
            'min_quantity' => 5,
            'price' => 3.5,
            'tracking_mode' => 'batch',
            'expiration_controlled' => true,
            'default_shelf_life_days' => 30,
            'shelf_life_basis' => 'receipt_date',
            'length_cm' => 10,
            'width_cm' => 20,
            'height_cm' => 30,
            'country_of_origin' => 'cn',
            'hs_code' => '8302.41',
            'unit_conversions' => [['code' => 'box', 'label' => 'Box of 100', 'factor_to_base' => 100]],
        ])->assertCreated();

        $product = Product::findOrFail($created->json('id'));
        $this->assertEqualsWithDelta(0.006, (float) $product->volume_m3, 0.000001);
        $this->assertSame('CN', $product->country_of_origin);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'barcode' => 'ALT-HANDLE-1']);
        $this->getJson('/api/products/lookup?sku=ALT-HANDLE-1')
            ->assertOk()
            ->assertJsonPath('id', $product->id);

        $warehouse = Warehouse::findOrFail($product->default_warehouse_id);
        $location = WarehouseLocation::create($this->tenantAttributes([
            'warehouse_id' => $warehouse->id,
            'type' => 'bin',
            'code' => 'MASTER-A-01',
            'name' => 'Master data test bin',
            'path' => 'MASTER-A-01',
            'is_active' => true,
        ]));

        $this->postJson('/api/stock-movements', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'type' => 'in',
            'quantity' => 2,
            'reason' => 'Shelf-life receipt',
            'trace_allocations' => [['lot_number' => 'LOT-DEFAULT-EXPIRY', 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $lot = InventoryLot::query()->where('product_id', $product->id)->sole();
        $this->assertSame(now()->addDays(30)->toDateString(), $lot->expiry_at?->toDateString());

        $this->postJson('/api/products', [
            'category_id' => $category->id, 'name' => 'Barcode conflict', 'sku' => 'HANDLE-MASTER-2',
            'barcode' => 'ALT-HANDLE-1', 'quantity' => 0, 'unit' => 'pcs', 'min_quantity' => 0, 'price' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('alternative_barcodes');
    }

    public function test_available_stock_and_product_archiving_use_warehouse_state_and_preserve_history(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Lifecycle']));
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Lifecycle supplier']));
        $created = $this->postJson('/api/products', [
            'category_id' => $category->id, 'name' => 'State-aware stock', 'sku' => 'STATE-1',
            'quantity' => 10, 'unit' => 'pcs', 'min_quantity' => 3, 'price' => 5,
        ])->assertCreated();
        $product = Product::findOrFail($created->json('id'));
        WarehouseStock::query()->where('product_id', $product->id)->update([
            'quantity' => 10, 'available_quantity' => 2, 'damaged_quantity' => 8,
        ]);
        $product = Product::findOrFail($product->id);
        $this->assertSame(2.0, $product->available_quantity);
        $this->assertSame('low', $product->stock_status);
        $this->getJson('/api/products?low_stock=1&per_page=50')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'STATE-1']);
        $this->deleteJson('/api/products/'.$product->id)
            ->assertUnprocessable();

        WarehouseStock::query()->where('product_id', $product->id)->update([
            'quantity' => 0, 'available_quantity' => 0, 'damaged_quantity' => 0,
        ]);
        $product->update(['quantity' => 0]);
        ProductSupplier::create($this->tenantAttributes([
            'product_id' => $product->id, 'supplier_id' => $supplier->id,
            'currency' => 'EUR', 'exchange_rate_to_base' => 1,
            'pack_size' => 1, 'minimum_order_quantity' => 0,
            'usual_lead_time_days' => 0, 'is_preferred' => true, 'is_active' => true,
        ]));
        $this->deleteJson('/api/products/'.$product->id)->assertNoContent();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'lifecycle_status' => 'archived']);
        $this->assertDatabaseHas('product_suppliers', ['product_id' => $product->id, 'is_active' => false]);
        $this->getJson('/api/products?per_page=50')->assertOk()->assertJsonMissing(['sku' => 'STATE-1']);
        $this->getJson('/api/products?lifecycle_status=archived&per_page=50')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'STATE-1']);
    }

    public function test_csv_update_preserves_absent_advanced_fields_and_imports_supplier_currency_snapshots(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'CSV products']));
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'CSV supplier']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id, 'name' => 'Preserved master', 'sku' => 'CSV-PRESERVE',
            'quantity' => 0, 'unit' => 'pcs', 'min_quantity' => 1, 'price' => 4,
            'brand' => 'Keep me', 'tracking_mode' => 'batch', 'expiration_controlled' => true,
            'default_shelf_life_days' => 45, 'safety_stock' => 7, 'lifecycle_status' => 'discontinued',
        ]));

        $legacy = $this->csvFile('legacy.csv',
            ['name', 'sku', 'category', 'quantity', 'min_quantity', 'price', 'unit'],
            [['Preserved master renamed', 'CSV-PRESERVE', 'CSV products', 0, 2, 5, 'pcs']],
        );
        $this->post('/api/products/import', ['file' => $legacy])
            ->assertCreated()
            ->assertJsonPath('status', 'completed');
        $product->refresh();
        $this->assertSame('Keep me', $product->brand);
        $this->assertSame('batch', $product->tracking_mode);
        $this->assertSame(45, $product->default_shelf_life_days);
        $this->assertSame('discontinued', $product->lifecycle_status);

        $foreign = $this->csvFile('foreign.csv', [
            'Name', 'SKU', 'Category', 'Supplier', 'Supplier SKU', 'Supplier Currency',
            'Exchange Rate to EUR', 'Exchange Rate Date', 'Quantity', 'Unit',
            'Min Quantity', 'Purchase Price', 'Selling Price', 'Status',
        ], [[
            'Foreign supplied product', 'CSV-FOREIGN', 'CSV products', $supplier->name, 'SUP-CN-1', 'CNY',
            0.13, '2026-08-29', 0, 'pcs', 1, 10, 4, 'active',
        ]]);
        $this->post('/api/products/import', ['file' => $foreign])
            ->assertCreated()
            ->assertJsonPath('status', 'completed');
        $foreignProduct = Product::query()->where('sku', 'CSV-FOREIGN')->firstOrFail();
        $this->assertDatabaseHas('product_suppliers', [
            'product_id' => $foreignProduct->id, 'supplier_id' => $supplier->id,
            'supplier_sku' => 'SUP-CN-1', 'currency' => 'CNY', 'exchange_rate_date' => '2026-08-29',
        ]);
        $this->assertEqualsWithDelta(1.3, (float) $foreignProduct->fresh()->purchase_price, 0.000001);

        $this->apiCompany->update(['base_currency' => 'HUF']);
        $baseCurrency = $this->csvFile('base-currency.csv', [
            'Name', 'SKU', 'Category', 'Supplier', 'Supplier SKU', 'Supplier Currency',
            'Exchange Rate to EUR', 'Exchange Rate Date', 'Quantity', 'Unit',
            'Min Quantity', 'Purchase Price', 'Selling Price', 'Status',
        ], [[
            'Base currency supplied product', 'CSV-HUF', 'CSV products', $supplier->name, 'SUP-HUF-1', 'HUF',
            '', '', 0, 'pcs', 1, 1250, 1600, 'active',
        ]]);
        $this->post('/api/products/import', ['file' => $baseCurrency])
            ->assertCreated()
            ->assertJsonPath('status', 'completed');
        $baseProduct = Product::query()->where('sku', 'CSV-HUF')->firstOrFail();
        $this->assertDatabaseHas('product_suppliers', [
            'product_id' => $baseProduct->id,
            'supplier_id' => $supplier->id,
            'currency' => 'HUF',
            'exchange_rate_to_base' => 1,
            'exchange_rate_date' => null,
        ]);

        $invalid = $this->csvFile('invalid.csv',
            ['name', 'sku', 'category', 'quantity', 'min_quantity', 'price', 'unit'],
            [['Invalid number', 'CSV-BAD', 'CSV products', 'not-a-number', 1, 2, 'pcs']],
        );
        $this->post('/api/products/import', ['file' => $invalid])
            ->assertCreated()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('records_imported', 0);
        $this->assertDatabaseMissing('products', ['sku' => 'CSV-BAD']);
    }

    public function test_product_relations_are_tenant_scoped(): void
    {
        $this->actingAsApiUser('admin');
        $ownCategory = Category::create($this->tenantAttributes(['name' => 'Own category']));
        $otherCompany = Company::factory()->create();
        $otherCategory = Category::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id, 'name' => 'Other category',
        ]);

        $this->postJson('/api/products', [
            'category_id' => $otherCategory->id, 'name' => 'Cross tenant', 'sku' => 'CROSS-1',
            'quantity' => 0, 'unit' => 'pcs', 'min_quantity' => 0, 'price' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/products', [
            'category_id' => $ownCategory->id, 'name' => 'Own product', 'sku' => 'CROSS-1',
            'quantity' => 0, 'unit' => 'pcs', 'min_quantity' => 0, 'price' => 1,
        ])->assertCreated();
    }

    private function csvFile(string $name, array $headers, array $rows): UploadedFile
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
