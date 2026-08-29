<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_can_be_filtered_and_paginated(): void
    {
        $this->actingAsApiUser();

        $electronics = Category::create($this->tenantAttributes(['name' => 'Electronics']));
        $office = Category::create($this->tenantAttributes(['name' => 'Office Supplies']));

        Product::create($this->tenantAttributes([
            'category_id' => $electronics->id,
            'name' => 'Wireless Mouse',
            'sku' => 'ELEC-001',
            'quantity' => 4,
            'min_quantity' => 5,
            'price' => 19.99,
        ]));

        Product::create($this->tenantAttributes([
            'category_id' => $office->id,
            'name' => 'A4 Paper Pack',
            'sku' => 'OFF-001',
            'quantity' => 30,
            'min_quantity' => 10,
            'price' => 6.99,
        ]));

        $response = $this->getJson('/api/products?low_stock=1&sort=quantity&direction=asc');

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'ELEC-001')
            ->assertJsonPath('data.0.category.name', 'Electronics');
    }

    public function test_stock_out_cannot_exceed_available_quantity(): void
    {
        $this->actingAsApiUser();

        $category = Category::create($this->tenantAttributes(['name' => 'Electronics']));

        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'USB-C Hub',
            'sku' => 'ELEC-002',
            'quantity' => 2,
            'min_quantity' => 1,
            'price' => 34.50,
        ]));

        $response = $this->postJson('/api/stock-movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 3,
            'reason' => 'Customer sale adjustment',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(2.0, $product->refresh()->quantity);
    }

    public function test_product_quantity_is_stored_and_returned_without_scaling(): void
    {
        $this->actingAsApiUser();

        $category = Category::create($this->tenantAttributes(['name' => 'Bulk goods']));

        $response = $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Seven Hundred Items',
            'sku' => 'BULK-700',
            'quantity' => 700,
            'unit' => 'pcs',
            'min_quantity' => 5,
            'price' => 2.50,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('quantity', 700);

        $this->assertSame(700.0, Product::where('sku', 'BULK-700')->firstOrFail()->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $response->json('id'),
            'movement_code' => 'opening_balance',
            'quantity_before' => 0,
            'quantity_after' => 700,
        ]);
    }

    public function test_product_can_be_created_without_entering_a_sku(): void
    {
        $this->actingAsApiUser();
        $category = Category::create($this->tenantAttributes(['name' => 'Uncoded goods']));

        $response = $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Product without a code',
            'quantity' => 12,
            'unit' => 'pcs',
            'min_quantity' => 1,
            'price' => 4.50,
        ]);

        $response->assertCreated()->assertJsonPath('name', 'Product without a code');
        $this->assertStringStartsWith('AUTO-', (string) $response->json('sku'));
        $this->assertDatabaseHas('products', ['id' => $response->json('id'), 'sku' => $response->json('sku')]);
    }

    public function test_product_image_can_be_uploaded_and_is_returned_in_detail(): void
    {
        $this->actingAsApiUser();
        $category = Category::create($this->tenantAttributes(['name' => 'Images']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Product photo',
            'sku' => 'IMG-001',
            'quantity' => 1,
            'min_quantity' => 0,
            'price' => 4.50,
        ]));

        $upload = $this->post('/api/products/'.$product->id.'/image', [
            'image' => UploadedFile::fake()->createWithContent(
                'product.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
            ),
        ]);

        $upload->assertOk()->assertJsonPath('id', $product->id);
        $this->assertNotEmpty($product->refresh()->image_data);
        $detail = $this->getJson('/api/products/'.$product->id)->assertOk();
        $this->assertStringStartsWith('data:image/', (string) $detail->json('product.image_url'));
    }

    public function test_stock_status_respects_low_and_high_thresholds(): void
    {
        $this->actingAsApiUser();

        $category = Category::create($this->tenantAttributes(['name' => 'Thresholds']));
        $high = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'High stock item',
            'sku' => 'THR-HIGH',
            'quantity' => 5600,
            'min_quantity' => 700,
            'high_stock_threshold' => 5000,
            'price' => 1,
        ]));
        $low = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Low stock item',
            'sku' => 'THR-LOW',
            'quantity' => 10,
            'min_quantity' => 700,
            'high_stock_threshold' => 5000,
            'price' => 1,
        ]));

        $this->assertSame('high', $high->stock_status);
        $this->assertSame('low', $low->stock_status);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('low_stock_products.0.sku', 'THR-LOW');

        $this->getJson('/api/dashboard/low-stock-alerts')
            ->assertOk()
            ->assertJsonPath('alerts.0.sku', 'THR-LOW');
    }

    public function test_dashboard_returns_inventory_summary(): void
    {
        $this->actingAsApiUser();

        $category = Category::create($this->tenantAttributes(['name' => 'Furniture']));

        Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Office Chair',
            'sku' => 'FUR-001',
            'quantity' => 3,
            'min_quantity' => 2,
            'price' => 149.00,
        ]));

        $response = $this->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('total_products', 1)
            ->assertJsonPath('total_units', 3);

        $this->assertEquals(447.0, $response->json('total_value'));

        $secondResponse = $this->getJson('/api/dashboard')->assertOk();
        $secondResponse
            ->assertJsonStructure(['low_stock_products', 'out_of_stock_products', 'recent_movements', 'category_values', 'movements_over_time']);
    }

    public function test_category_can_be_deleted_when_products_exist(): void
    {
        $this->actingAsApiUser('manager');

        $category = Category::create($this->tenantAttributes(['name' => 'Office']));

        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Stapler',
            'sku' => 'OFF-010',
            'quantity' => 4,
            'min_quantity' => 1,
            'price' => 8.00,
        ]));

        $this->deleteJson('/api/categories/'.$category->id)->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertNull($product->fresh()->category_id);
    }

    public function test_supplier_can_be_deleted_when_products_exist(): void
    {
        $this->actingAsApiUser('manager');

        $category = Category::create($this->tenantAttributes(['name' => 'Electronics']));
        $supplier = Supplier::create($this->tenantAttributes([
            'name' => 'TechSupply Co.',
            'email' => 'orders@techsupply.test',
        ]));

        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
            'name' => 'Keyboard',
            'sku' => 'ELEC-010',
            'quantity' => 5,
            'min_quantity' => 2,
            'price' => 45.00,
        ]));

        $this->deleteJson('/api/suppliers/'.$supplier->id)->assertNoContent();

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
        $this->assertNull($product->fresh()->supplier_id);
    }

    public function test_products_can_be_filtered_by_supplier(): void
    {
        $this->actingAsApiUser();

        $category = Category::create($this->tenantAttributes(['name' => 'Office Supplies']));
        $supplierA = Supplier::create($this->tenantAttributes(['name' => 'Office Depot KS']));
        $supplierB = Supplier::create($this->tenantAttributes(['name' => 'Global Furniture']));

        Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'supplier_id' => $supplierA->id,
            'name' => 'Stapler',
            'sku' => 'OFF-010',
            'quantity' => 10,
            'min_quantity' => 3,
            'price' => 8.50,
        ]));

        Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'supplier_id' => $supplierB->id,
            'name' => 'Desk Lamp',
            'sku' => 'OFF-011',
            'quantity' => 6,
            'min_quantity' => 2,
            'price' => 22.00,
        ]));

        $response = $this->getJson('/api/products?supplier_id='.$supplierA->id);

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'OFF-010')
            ->assertJsonPath('data.0.supplier.name', 'Office Depot KS');
    }

    public function test_staff_cannot_delete_products(): void
    {
        $this->actingAsApiUser('staff');

        $category = Category::create($this->tenantAttributes(['name' => 'Electronics']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Mouse',
            'sku' => 'ELEC-099',
            'quantity' => 5,
            'min_quantity' => 2,
            'price' => 10.00,
        ]));

        $this->deleteJson('/api/products/'.$product->id)->assertForbidden();
    }

    public function test_product_lookup_by_sku(): void
    {
        $this->actingAsApiUser();

        $category = Category::create($this->tenantAttributes(['name' => 'Electronics']));
        Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'HDMI Cable',
            'sku' => 'ELEC-050',
            'quantity' => 12,
            'min_quantity' => 4,
            'price' => 9.99,
        ]));

        $this->getJson('/api/products/lookup?sku=ELEC-050')
            ->assertOk()
            ->assertJsonPath('sku', 'ELEC-050')
            ->assertJsonPath('name', 'HDMI Cable');

        $this->getJson('/api/products/lookup?sku=MISSING')
            ->assertNotFound();
    }

    public function test_stock_movements_can_be_filtered_by_type(): void
    {
        $this->actingAsApiUser();

        $category = Category::create($this->tenantAttributes(['name' => 'Office']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Notebook',
            'sku' => 'OFF-020',
            'quantity' => 10,
            'min_quantity' => 2,
            'price' => 3.50,
        ]));

        $this->postJson('/api/stock-movements', [
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 2,
            'reason' => 'Counted delivery',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->postJson('/api/stock-movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 1,
            'reason' => 'Damaged unit',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $response = $this->getJson('/api/stock-movements?type=out');

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.type', 'out');
    }

    public function test_stock_movement_retry_is_idempotent_and_payload_mismatch_is_rejected(): void
    {
        $this->actingAsApiUser();
        $category = Category::create($this->tenantAttributes(['name' => 'Idempotent stock']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Retry-safe item',
            'sku' => 'IDEM-001',
            'quantity' => 10,
            'unit' => 'pcs',
            'min_quantity' => 0,
            'price' => 1,
        ]));
        $key = (string) Str::uuid();
        $payload = [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 2,
            'reason' => 'Count correction',
            'idempotency_key' => $key,
        ];

        $firstId = $this->postJson('/api/stock-movements', $payload)->assertCreated()->json('id');
        $this->postJson('/api/stock-movements', $payload)->assertCreated()->assertJsonPath('id', $firstId);
        $this->assertSame(8.0, $product->refresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);

        $payload['quantity'] = 3;
        $this->postJson('/api/stock-movements', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(8.0, $product->refresh()->quantity);
    }

    public function test_product_quantity_edit_creates_an_auditable_adjustment(): void
    {
        $this->actingAsApiUser();
        $category = Category::create($this->tenantAttributes(['name' => 'Adjustments']));
        $created = $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Adjustable product',
            'sku' => 'ADJ-001',
            'quantity' => 5,
            'unit' => 'pcs',
            'min_quantity' => 1,
            'price' => 2,
        ])->assertCreated();

        $this->putJson('/api/products/'.$created->json('id'), [
            'quantity' => 8,
            'quantity_change_reason' => 'Physical recount confirmed three additional units',
        ])->assertOk()->assertJsonPath('quantity', 8);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $created->json('id'),
            'movement_code' => 'manual_adjustment_in',
            'source_type' => 'product_edit',
            'quantity' => 3,
            'quantity_before' => 5,
            'quantity_after' => 8,
        ]);
    }

    public function test_inventory_reconciliation_adds_ledger_history_without_changing_product_balance(): void
    {
        $this->actingAsApiUser();
        $category = Category::create($this->tenantAttributes(['name' => 'Legacy']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Legacy balance',
            'sku' => 'LEGACY-10',
            'quantity' => 10,
            'unit' => 'pcs',
            'min_quantity' => 0,
            'price' => 1,
        ]));

        $this->artisan('inventory:reconcile', ['--company' => $product->company_id, '--repair' => true])
            ->assertSuccessful();
        $this->artisan('inventory:reconcile', ['--company' => $product->company_id, '--repair' => true])
            ->assertSuccessful();

        $this->assertSame(10.0, $product->refresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_code' => 'opening_balance',
            'quantity_before' => 0,
            'quantity_after' => 10,
        ]);
    }

    public function test_stock_import_uses_the_inventory_ledger_and_supports_meter_decimals(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Fabric']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Fabric roll',
            'sku' => 'ROLL-IMPORT',
            'quantity' => 10,
            'unit' => 'meter',
            'min_quantity' => 0,
            'price' => 2,
        ]));
        $file = UploadedFile::fake()->createWithContent(
            'stock.csv',
            "sku,type,quantity,reason\nROLL-IMPORT,out,2.5,Measured adjustment\n",
        );

        $this->post('/api/import/stock_movements', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('status', 'completed');

        $this->assertSame(7.5, $product->refresh()->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_code' => 'import_adjustment_out',
            'source_type' => 'import_log',
            'quantity' => 2.5,
            'quantity_before' => 10,
            'quantity_after' => 7.5,
        ]);
    }

    public function test_product_import_creates_an_opening_balance_movement(): void
    {
        $this->actingAsApiUser('admin');
        $file = UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,sku,category,quantity,unused,min_quantity,price,unit\nImported item,IMPORT-OPEN,Imports,12,,2,4.50,pcs\n",
        );

        $this->post('/api/products/import', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('status', 'completed');

        $product = Product::where('sku', 'IMPORT-OPEN')->firstOrFail();
        $this->assertSame(12.0, $product->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_code' => 'opening_balance',
            'source_type' => 'import_log',
            'quantity_before' => 0,
            'quantity_after' => 12,
        ]);
    }

    public function test_invoice_creation_endpoint_is_enabled_and_validates_a_complete_draft(): void
    {
        $this->actingAsApiUser();

        $category = Category::create($this->tenantAttributes(['name' => 'Electronics']));

        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Wireless Mouse',
            'sku' => 'ELEC-001',
            'quantity' => 10,
            'min_quantity' => 2,
            'price' => 19.99,
        ]));

        $this->postJson('/api/invoices', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['invoice_date', 'supply_date']);
    }
}
