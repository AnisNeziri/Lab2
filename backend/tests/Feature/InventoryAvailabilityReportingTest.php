<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAvailabilityReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_and_alerts_distinguish_available_from_non_sellable_stock(): void
    {
        $this->actingAsApiUser();
        $category = Category::create($this->tenantAttributes(['name' => 'Controlled stock']));
        $warehouse = Warehouse::create($this->tenantAttributes([
            'name' => 'Main', 'code' => 'MAIN', 'is_active' => true, 'is_default' => true,
        ]));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'default_warehouse_id' => $warehouse->id,
            'name' => 'Quarantined cable',
            'sku' => 'STATE-001',
            'quantity' => 10,
            'unit' => 'm',
            'min_quantity' => 3,
            'price' => 5,
            'purchase_price' => 2,
            'selling_price' => 5,
        ]));
        WarehouseStock::create($this->tenantAttributes([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'location_key' => 0,
            'quantity' => 10,
            'available_quantity' => 2,
            'reserved_quantity' => 0,
            'damaged_quantity' => 3,
            'quarantine_quantity' => 5,
            'blocked_quantity' => 0,
        ]));

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_units', 10)
            ->assertJsonPath('low_stock_count', 1)
            ->assertJsonPath('low_stock_products.0.quantity', 2)
            ->assertJsonPath('low_stock_products.0.on_hand_quantity', 10);
        $this->getJson('/api/dashboard/low-stock-alerts')
            ->assertOk()
            ->assertJsonPath('alerts.0.quantity', 2);
        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('categories.0.total_units', 10)
            ->assertJsonPath('top_products.0.available_quantity', 2);
        $this->getJson('/api/replenishment?include_ok=1')
            ->assertOk()
            ->assertJsonPath('suggestions.0.calculation.on_hand', 10)
            ->assertJsonPath('suggestions.0.calculation.available', 2);

        $notifications = app(NotificationService::class);
        $notifications->createLowStockAlert($product);
        $notifications->createLowStockAlert($product);
        $this->assertSame(1, Notification::query()->where('type', 'low_stock')->count());
        $this->assertStringContainsString('2 m available', Notification::query()->firstOrFail()->message);
    }
}
