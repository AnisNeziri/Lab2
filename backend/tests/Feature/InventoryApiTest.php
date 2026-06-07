<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(2, $product->refresh()->quantity);
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
        ])->assertCreated();

        $this->postJson('/api/stock-movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 1,
        ])->assertCreated();

        $response = $this->getJson('/api/stock-movements?type=out');

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.type', 'out');
    }

    public function test_invoice_can_be_created_with_line_items(): void
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

        $response = $this->postJson('/api/invoices', [
            'customer_name' => 'Alpha Market',
            'due_at' => now()->addDays(14)->toDateString(),
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('customer_name', 'Alpha Market')
            ->assertJsonPath('status', 'unpaid')
            ->assertJsonPath('total_amount', '59.97')
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.0.quantity', 3);

        $this->assertDatabaseHas('invoices', [
            'customer_name' => 'Alpha Market',
            'total_amount' => 59.97,
        ]);
    }
}
