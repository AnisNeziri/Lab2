<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_can_be_filtered_and_paginated(): void
    {
        $electronics = Category::create(['name' => 'Electronics']);
        $office = Category::create(['name' => 'Office Supplies']);

        Product::create([
            'category_id' => $electronics->id,
            'name' => 'Wireless Mouse',
            'sku' => 'ELEC-001',
            'quantity' => 4,
            'min_quantity' => 5,
            'price' => 19.99,
        ]);

        Product::create([
            'category_id' => $office->id,
            'name' => 'A4 Paper Pack',
            'sku' => 'OFF-001',
            'quantity' => 30,
            'min_quantity' => 10,
            'price' => 6.99,
        ]);

        $response = $this->getJson('/api/products?low_stock=1&sort=quantity&direction=asc');

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'ELEC-001')
            ->assertJsonPath('data.0.category.name', 'Electronics');
    }

    public function test_stock_out_cannot_exceed_available_quantity(): void
    {
        $category = Category::create(['name' => 'Electronics']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'USB-C Hub',
            'sku' => 'ELEC-002',
            'quantity' => 2,
            'min_quantity' => 1,
            'price' => 34.50,
        ]);

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
        $category = Category::create(['name' => 'Furniture']);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Office Chair',
            'sku' => 'FUR-001',
            'quantity' => 3,
            'min_quantity' => 2,
            'price' => 149.00,
        ]);

        $response = $this->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('total_products', 1)
            ->assertJsonPath('total_units', 3);

        $this->assertEquals(447.0, $response->json('total_value'));
    }
}
