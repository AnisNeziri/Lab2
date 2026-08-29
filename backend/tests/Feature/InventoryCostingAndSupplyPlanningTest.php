<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductSupplierPriceHistory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DailySaleService;
use App\Services\LandedCostService;
use App\Services\PurchaseOrderService;
use App\Services\ReplenishmentService;
use App\Services\StockMovementService;
use App\Services\SupplierCatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class InventoryCostingAndSupplyPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_receipts_update_weighted_average_and_sales_snapshot_that_cost(): void
    {
        [$supplier, $category] = $this->setupCompany();
        $product = $this->product($category, 'COST-001', 10, 10);
        $order = $this->order($supplier, [['product' => $product, 'quantity' => 10, 'price' => 20]]);

        app(PurchaseOrderService::class)->receive($order, [
            'items' => [['id' => $order->items->first()->id, 'quantity' => 10]],
            'received_at' => '2026-08-20',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $product->refresh();
        $this->assertEqualsWithDelta(20, (float) $product->quantity, 0.0001);
        $this->assertEqualsWithDelta(15, (float) $product->weighted_average_cost, 0.000001);
        $this->assertEqualsWithDelta(300, (float) $product->inventory_value, 0.000001);
        $this->assertDatabaseHas('goods_receipt_items', [
            'product_id' => $product->id,
            'purchase_unit_price' => 20,
            'base_purchase_unit_cost' => 20,
            'landed_cost_allocated' => 0,
            'final_inventory_unit_cost' => 20,
        ]);

        $sale = app(DailySaleService::class)->create([
            'sale_date' => '2026-08-21',
            'items' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => 'pcs',
                'quantity' => 2,
                'unit_price' => 30,
            ]],
        ]);

        $this->assertEqualsWithDelta(15, (float) $sale->items->first()->unit_cost, 0.000001);
        $this->assertEqualsWithDelta(30, (float) $sale->items->first()->cost_total, 0.000001);
        $this->assertEqualsWithDelta(270, (float) $product->fresh()->inventory_value, 0.000001);
        $outbound = $product->stockMovements()->where('movement_code', 'daily_sale')->firstOrFail();
        $this->assertEqualsWithDelta(15, (float) $outbound->final_unit_cost, 0.000001);
        $this->assertEqualsWithDelta(30, (float) $outbound->cost_total, 0.000001);
    }

    public function test_landed_cost_rounding_is_exact_and_does_not_change_supplier_purchase_prices(): void
    {
        [$supplier, $category] = $this->setupCompany();
        $products = collect([
            $this->product($category, 'LAND-001', 0, 10),
            $this->product($category, 'LAND-002', 0, 10),
            $this->product($category, 'LAND-003', 0, 10),
        ]);
        $order = $this->order($supplier, $products->map(fn ($product) => [
            'product' => $product, 'quantity' => 1, 'price' => 10,
        ])->all());
        app(PurchaseOrderService::class)->receive($order, [
            'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'quantity' => 1])->all(),
            'received_at' => '2026-08-20',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $receipt = GoodsReceipt::query()->firstOrFail();

        $draft = app(LandedCostService::class)->createDraft([
            'goods_receipt_id' => $receipt->id,
            'cost_type' => 'freight',
            'amount' => 10,
            'currency' => 'EUR',
            'allocation_method' => 'quantity',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $amounts = $draft->allocations->pluck('allocated_amount')->map(fn ($amount) => (float) $amount);
        $this->assertEqualsWithDelta(10, $amounts->sum(), 0.000001);
        $this->assertSame([3.34, 3.33, 3.33], $amounts->all());

        $posted = app(LandedCostService::class)->post($draft);
        $this->assertSame('posted', $posted->status);
        foreach ($products as $product) {
            $product->refresh();
            $this->assertEqualsWithDelta(10, (float) $product->purchase_price, 0.000001);
            $this->assertGreaterThan(10, (float) $product->weighted_average_cost);
            $receiptItem = $receipt->items()->where('product_id', $product->id)->firstOrFail();
            $this->assertEqualsWithDelta(
                (float) $receiptItem->base_purchase_unit_cost + (float) $receiptItem->landed_cost_unit,
                (float) $receiptItem->final_inventory_unit_cost,
                0.000001,
            );
        }
    }

    public function test_supplier_catalogue_preserves_price_history_and_reports_delivery_performance(): void
    {
        [$supplier, $category] = $this->setupCompany();
        $product = $this->product($category, 'SUP-001', 0, null);
        $catalogueService = app(SupplierCatalogueService::class);
        $catalogue = $catalogueService->create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'VENDOR-001',
            'purchase_price' => 10,
            'currency' => 'EUR',
            'pack_size' => 5,
            'minimum_order_quantity' => 10,
            'usual_lead_time_days' => 4,
            'is_preferred' => true,
            'price_change_reason' => 'Initial quote.',
        ]);
        $catalogue = $catalogueService->update($catalogue, [
            'purchase_price' => 12,
            'price_change_reason' => 'Supplier quote revision.',
        ]);

        $this->assertCount(2, $catalogue->priceHistory);
        $this->assertEqualsWithDelta(12, (float) $product->fresh()->purchase_price, 0.000001);
        $history = ProductSupplierPriceHistory::query()->oldest('id')->firstOrFail();
        try {
            $history->update(['purchase_price' => 99]);
            $this->fail('Immutable supplier price history accepted an update.');
        } catch (LogicException) {
            $this->assertEqualsWithDelta(10, (float) $history->fresh()->purchase_price, 0.000001);
        }

        $order = $this->order($supplier, [['product' => $product, 'quantity' => 5, 'price' => 12]]);
        app(PurchaseOrderService::class)->receive($order, [
            'items' => [['id' => $order->items->first()->id, 'quantity' => 5]],
            'received_at' => '2026-08-28',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $performance = $catalogueService->performance($supplier);
        $this->assertSame(1, $performance['late_deliveries']);
        $this->assertSame(0, $performance['on_time_deliveries']);
        $this->assertEqualsWithDelta(13, $performance['average_lead_time_days'], 0.1);
        $this->assertEqualsWithDelta(5, $performance['ordered_base_quantity'], 0.001);
        $this->assertEqualsWithDelta(5, $performance['received_base_quantity'], 0.001);
        $this->assertEqualsWithDelta(60, $performance['purchase_value_eur'], 0.01);
    }

    public function test_replenishment_explains_the_formula_and_only_creates_drafts_on_explicit_action(): void
    {
        [$supplier, $category] = $this->setupCompany();
        $product = $this->product($category, 'REP-001', 20, 8, [
            'min_quantity' => 5,
            'safety_stock' => 5,
            'reorder_point' => 12,
            'replenishment_history_days' => 10,
            'replenishment_review_days' => 7,
        ]);
        app(SupplierCatalogueService::class)->create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'purchase_price' => 8,
            'currency' => 'EUR',
            'pack_size' => 6,
            'minimum_order_quantity' => 10,
            'usual_lead_time_days' => 5,
            'is_preferred' => true,
        ]);
        app(StockMovementService::class)->store([
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 10,
            'movement_code' => 'daily_sale',
            'reason' => 'Historical usage for planning test.',
            'occurred_at' => '2026-08-25 10:00:00',
            'idempotency_key' => 'replenishment-history-'.$product->id,
        ]);

        $planning = app(ReplenishmentService::class)->suggestions([
            'product_ids' => [$product->id],
            'history_days' => 10,
            'as_of' => '2026-08-28',
        ]);
        $suggestion = $planning['suggestions'][0];
        $this->assertTrue($suggestion['needs_reorder']);
        $this->assertEqualsWithDelta(10, $suggestion['calculation']['available'], 0.0001);
        $this->assertEqualsWithDelta(1, $suggestion['calculation']['average_daily_usage'], 0.000001);
        $this->assertEqualsWithDelta(12, $suggestion['recommended_quantity'], 0.0001);
        $this->assertDatabaseCount('purchase_orders', 0);

        $orders = app(ReplenishmentService::class)->createDraftPurchaseOrders([
            'product_ids' => [$product->id],
            'history_days' => 10,
            'as_of' => '2026-08-28',
        ]);
        $this->assertCount(1, $orders);
        $this->assertSame('draft', $orders[0]->status);
        $this->assertEqualsWithDelta(12, (float) $orders[0]->items->first()->quantity, 0.0001);
        $this->assertDatabaseCount('purchase_orders', 1);
    }

    private function setupCompany(): array
    {
        $this->actingAsApiUser('admin');
        $user = User::query()->where('company_id', $this->apiCompany->id)->firstOrFail();
        Auth::setUser($user);
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Costing Supplier']));
        $category = Category::create($this->tenantAttributes(['name' => 'Costed Products']));

        return [$supplier, $category];
    }

    private function product(Category $category, string $sku, float $quantity, ?float $purchasePrice, array $extra = []): Product
    {
        return Product::create($this->tenantAttributes(array_merge([
            'category_id' => $category->id,
            'name' => $sku,
            'sku' => $sku,
            'quantity' => $quantity,
            'unit' => 'pcs',
            'min_quantity' => 0,
            'price' => 25,
            'selling_price' => 25,
            'purchase_price' => $purchasePrice,
        ], $extra)));
    }

    private function order(Supplier $supplier, array $lines)
    {
        return app(PurchaseOrderService::class)->create([
            'supplier_id' => $supplier->id,
            'ordered_at' => '2026-08-15',
            'expected_at' => '2026-08-25',
            'currency' => 'EUR',
            'status' => 'ordered',
            'items' => collect($lines)->map(fn ($line) => [
                'product_id' => $line['product']->id,
                'description' => $line['product']->name,
                'unit' => 'pcs',
                'quantity' => $line['quantity'],
                'unit_price' => $line['price'],
            ])->all(),
        ]);
    }
}
