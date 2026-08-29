<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\DailySale;
use App\Models\DailySaleItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailySaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_and_finalize_daily_sale_with_stock_deduction(): void
    {
        $this->actingAsApiUser('admin');

        $category = Category::create($this->tenantAttributes(['name' => 'Textiles']));

        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Cotton Fabric Roll',
            'sku' => 'TXT-001',
            'quantity' => 20,
            'unit' => 'roll',
            'selling_price' => 10,
            'purchase_price' => 6,
            'price' => 10,
        ]));

        $create = $this->postJson('/api/daily-sales', [
            'sale_date' => now()->toDateString(),
            'customer_name' => 'Walk-in Customer',
            'notes' => 'Morning sale',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => 5,
                    'unit_price' => 10,
                ],
            ],
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('total_amount', '50.00');

        $saleId = $create->json('id');

        $this->assertSame(15.0, $product->fresh()->quantity);

        $this->postJson("/api/daily-sales/{$saleId}/finalize")
            ->assertOk()
            ->assertJsonPath('status', 'finalized');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'quantity' => 15,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => 5,
        ]);

        $this->assertDatabaseHas('daily_sale_items', [
            'daily_sale_id' => $saleId,
            'unit_cost' => 6,
            'cost_total' => 30,
            'gross_profit' => 20,
        ]);

        $this->getJson('/api/dashboard/sales-analytics?period=week')
            ->assertOk()
            ->assertJsonPath('total_sales', 50)
            ->assertJsonPath('total_cost', 30)
            ->assertJsonPath('gross_profit', 20)
            ->assertJsonPath('gross_margin_percent', 40)
            ->assertJsonPath('cost_coverage_percent', 100)
            ->assertJsonPath('margin_is_complete', true)
            ->assertJsonPath('uncosted_revenue', 0);

        $product->update(['purchase_price' => 9]);

        $this->getJson('/api/dashboard/sales-analytics?period=week')
            ->assertOk()
            ->assertJsonPath('total_cost', 30)
            ->assertJsonPath('gross_profit', 20);
    }

    public function test_sales_without_purchase_price_are_disclosed_as_uncosted_instead_of_zero_margin(): void
    {
        $this->actingAsApiUser('admin');

        $category = Category::create($this->tenantAttributes(['name' => 'Services']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Legacy Priced Product',
            'sku' => 'LEGACY-PRICE-1',
            'quantity' => 10,
            'unit' => 'pcs',
            'price' => 20,
            'selling_price' => 20,
            'purchase_price' => null,
        ]));

        $this->postJson('/api/daily-sales', [
            'sale_date' => '2026-07-29',
            'items' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => 'pcs',
                'quantity' => 2,
                'unit_price' => 20,
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('daily_sale_items', [
            'product_id' => $product->id,
            'unit_cost' => null,
            'cost_total' => null,
            'gross_profit' => null,
        ]);

        $this->getJson('/api/dashboard/sales-analytics?period=week&date=2026-07-29')
            ->assertOk()
            ->assertJsonPath('total_sales', 40)
            ->assertJsonPath('total_cost', 0)
            ->assertJsonPath('gross_profit', 0)
            ->assertJsonPath('gross_margin_percent', null)
            ->assertJsonPath('cost_coverage_percent', 0)
            ->assertJsonPath('margin_is_complete', false)
            ->assertJsonPath('uncosted_revenue', 40);
    }

    public function test_sales_analytics_use_tenant_safe_calendar_periods_and_previous_calendar_month(): void
    {
        $this->actingAsApiUser('admin');

        $this->createAnalyticsSale($this->apiCompany->id, '2026-03-01', 100, 60);
        $this->createAnalyticsSale($this->apiCompany->id, '2026-03-31', 50, 20);
        $this->createAnalyticsSale($this->apiCompany->id, '2026-04-01', 900, 100);
        $this->createAnalyticsSale($this->apiCompany->id, '2026-02-01', 30, 10);
        $this->createAnalyticsSale($this->apiCompany->id, '2026-02-28', 70, 40);
        $this->createAnalyticsSale($this->apiCompany->id, '2026-01-29', 999, 1);

        $otherCompany = Company::factory()->create();
        $this->createAnalyticsSale($otherCompany->id, '2026-03-15', 777, 7);

        $this->getJson('/api/dashboard/sales-analytics?period=month&date=2026-03-15')
            ->assertOk()
            ->assertJsonPath('period', 'month')
            ->assertJsonPath('start_date', '2026-03-01')
            ->assertJsonPath('end_date', '2026-03-31')
            ->assertJsonPath('total_sales', 150)
            ->assertJsonPath('total_cost', 80)
            ->assertJsonPath('gross_profit', 70)
            ->assertJsonPath('gross_margin_percent', 46.7)
            ->assertJsonPath('transaction_count', 2)
            ->assertJsonPath('previous_total_sales', 100)
            ->assertJsonPath('previous_gross_profit', 50)
            ->assertJsonCount(31, 'series');

        $this->getJson('/api/dashboard/sales-analytics?period=week&date=2026-07-29')
            ->assertOk()
            ->assertJsonPath('start_date', '2026-07-27')
            ->assertJsonPath('end_date', '2026-08-02')
            ->assertJsonCount(7, 'series');

        $this->getJson('/api/dashboard/sales-analytics?period=year&date=2026-07-29')
            ->assertOk()
            ->assertJsonPath('start_date', '2026-01-01')
            ->assertJsonPath('end_date', '2026-12-31')
            ->assertJsonCount(12, 'series');
    }

    public function test_legacy_uncosted_rows_do_not_inflate_reported_profit_or_margin(): void
    {
        $this->actingAsApiUser('admin');

        $sale = $this->createAnalyticsSale($this->apiCompany->id, '2026-07-29', 100, 60);
        $sale->update(['total_amount' => 150, 'total_quantity' => 2]);
        DailySaleItem::withoutGlobalScopes()->create([
            'daily_sale_id' => $sale->id,
            'company_id' => $this->apiCompany->id,
            'line_number' => 2,
            'product_name' => 'Legacy custom line',
            'unit' => 'pcs',
            'quantity' => 1,
            'unit_price' => 50,
            'unit_cost' => null,
            'line_total' => 50,
            'cost_total' => null,
            'gross_profit' => null,
        ]);

        $this->getJson('/api/dashboard/sales-analytics?period=week&date=2026-07-29')
            ->assertOk()
            ->assertJsonPath('total_sales', 150)
            ->assertJsonPath('total_cost', 60)
            ->assertJsonPath('gross_profit', 40)
            ->assertJsonPath('gross_margin_percent', 40)
            ->assertJsonPath('costed_revenue', 100)
            ->assertJsonPath('uncosted_revenue', 50)
            ->assertJsonPath('cost_coverage_percent', 66.7)
            ->assertJsonPath('margin_is_complete', false);
    }

    public function test_recording_sale_prevents_negative_stock_immediately(): void
    {
        $this->actingAsApiUser('admin');

        $category = Category::create($this->tenantAttributes(['name' => 'Textiles']));

        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Thread Spool',
            'sku' => 'TXT-002',
            'quantity' => 2,
            'unit' => 'pcs',
            'selling_price' => 5,
            'price' => 5,
        ]));

        $this->postJson('/api/daily-sales', [
            'sale_date' => now()->toDateString(),
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit' => 'pcs',
                    'quantity' => 5,
                    'unit_price' => 5,
                ],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(2.0, $product->fresh()->quantity);
        $this->assertDatabaseCount('daily_sales', 0);
    }

    public function test_staff_cannot_finalize_daily_sale(): void
    {
        $this->actingAsApiUser('staff');

        $sale = DailySale::create($this->tenantAttributes([
            'sale_number' => 'DS-TEST-001',
            'sale_date' => now()->toDateString(),
            'status' => 'draft',
            'total_amount' => 0,
            'total_quantity' => 0,
        ]));

        $this->postJson("/api/daily-sales/{$sale->id}/finalize")
            ->assertForbidden();
    }

    public function test_daily_sales_summary_returns_totals(): void
    {
        $this->actingAsApiUser('admin');

        DailySale::create($this->tenantAttributes([
            'sale_number' => 'DS-TEST-002',
            'sale_date' => now()->toDateString(),
            'status' => 'finalized',
            'total_amount' => 120,
            'total_quantity' => 8,
            'finalized_at' => now(),
        ]));

        $this->getJson('/api/daily-sales/summary')
            ->assertOk()
            ->assertJsonPath('total_sales', 120)
            ->assertJsonPath('transaction_count', 1)
            ->assertJsonPath('total_quantity', 8);
    }

    public function test_one_numbered_sale_combines_multiple_products_into_one_total(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Equipment']));
        $computer = Product::create($this->tenantAttributes(['category_id' => $category->id, 'name' => 'Computer', 'sku' => 'PC-DAY-1', 'quantity' => 10, 'unit' => 'pcs', 'price' => 1000, 'selling_price' => 1000]));
        $keyboard = Product::create($this->tenantAttributes(['category_id' => $category->id, 'name' => 'Keyboard', 'sku' => 'KEY-DAY-1', 'quantity' => 20, 'unit' => 'pcs', 'price' => 50, 'selling_price' => 50]));

        $sale = $this->postJson('/api/daily-sales', [
            'sale_date' => '2026-07-18',
            'items' => [
                ['product_id' => $computer->id, 'product_name' => $computer->name, 'unit' => 'pcs', 'quantity' => 2, 'unit_price' => 1000],
                ['product_id' => $keyboard->id, 'product_name' => $keyboard->name, 'unit' => 'pcs', 'quantity' => 3, 'unit_price' => 50],
            ],
        ])->assertCreated()
            ->assertJsonPath('sale_number', 'DS-20260718-001')
            ->assertJsonPath('total_amount', '2150.00')
            ->assertJsonCount(2, 'items');

        $this->assertSame('2150.00', DailySale::find($sale->json('id'))->total_amount);
    }

    public function test_closing_the_day_finalizes_all_numbered_sales_and_combines_summary(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Equipment']));
        $product = Product::create($this->tenantAttributes(['category_id' => $category->id, 'name' => 'Keyboard', 'sku' => 'KEY-CLOSE-1', 'quantity' => 20, 'unit' => 'pcs', 'price' => 50, 'selling_price' => 50]));
        $date = '2026-07-18';

        $this->postJson('/api/daily-sales', ['sale_date' => $date, 'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit' => 'pcs', 'quantity' => 2, 'unit_price' => 50]]])
            ->assertCreated()->assertJsonPath('sale_number', 'DS-20260718-001');
        $this->postJson('/api/daily-sales', ['sale_date' => $date, 'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit' => 'pcs', 'quantity' => 3, 'unit_price' => 50]]])
            ->assertCreated()->assertJsonPath('sale_number', 'DS-20260718-002');

        $this->postJson('/api/daily-sales/finalize-day', ['date' => $date])
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.status', 'finalized')
            ->assertJsonPath('1.status', 'finalized');

        $this->getJson("/api/daily-sales?date={$date}")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.sale_number', 'DS-20260718-001')
            ->assertJsonPath('1.sale_number', 'DS-20260718-002');

        $this->assertSame(15.0, $product->fresh()->quantity);
        $this->getJson("/api/daily-sales/summary?date={$date}")
            ->assertOk()
            ->assertJsonPath('day_total', 250)
            ->assertJsonPath('transaction_count', 2)
            ->assertJsonPath('day_closed', true);
    }

    public function test_large_piece_inventory_correlates_and_sale_deducts_exact_quantity(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Equipment']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Computer Stand',
            'sku' => 'STAND-20000',
            'quantity' => 20000,
            'unit' => 'pcs',
            'price' => 25,
            'selling_price' => 30,
        ]));

        $sale = $this->postJson('/api/daily-sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => 'pcs',
                'quantity' => 50,
                'unit_price' => 30,
            ]],
        ])->assertCreated();

        $this->assertSame(19950.0, $product->fresh()->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity_before' => 20000,
            'quantity' => 50,
            'quantity_after' => 19950,
        ]);

        $this->postJson("/api/daily-sales/{$sale->json('id')}/finalize")->assertOk();
        $this->assertSame(19950.0, $product->fresh()->quantity);
    }

    public function test_editing_and_deleting_open_sale_reconciles_inventory(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Equipment']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'name' => 'Computer Stand',
            'sku' => 'STAND-EDIT',
            'quantity' => 100,
            'unit' => 'pcs',
            'price' => 25,
        ]));

        $sale = $this->postJson('/api/daily-sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit' => 'pcs', 'quantity' => 20, 'unit_price' => 25]],
        ])->assertCreated();
        $this->assertSame(80.0, $product->fresh()->quantity);

        $this->putJson("/api/daily-sales/{$sale->json('id')}", [
            'sale_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit' => 'pcs', 'quantity' => 35, 'unit_price' => 25]],
        ])->assertOk();
        $this->assertSame(65.0, $product->fresh()->quantity);

        $this->deleteJson("/api/daily-sales/{$sale->json('id')}")->assertOk();
        $this->assertSame(100.0, $product->fresh()->quantity);
    }

    public function test_daily_sales_ignore_customer_payment_fields_and_do_not_create_debt(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Equipment']));
        $product = Product::create($this->tenantAttributes(['category_id' => $category->id, 'name' => 'Mouse', 'sku' => 'MOUSE-DAY-1', 'quantity' => 10, 'unit' => 'pcs', 'price' => 20, 'selling_price' => 20]));

        $sale = $this->postJson('/api/daily-sales', [
            'sale_date' => now()->toDateString(),
            'customer_name' => 'Legacy customer field',
            'paid_amount' => 0,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit' => 'pcs', 'quantity' => 2, 'unit_price' => 20]],
        ])->assertCreated();

        $this->postJson("/api/daily-sales/{$sale->json('id')}/finalize")->assertOk();

        $this->assertNull(DailySale::find($sale->json('id'))->customer_name);
        $this->assertDatabaseCount('customer_debt_transactions', 0);
    }

    public function test_daily_day_notes_can_be_saved_and_loaded_independently_of_sales(): void
    {
        $this->actingAsApiUser('admin');

        $this->getJson('/api/daily-sales/day-notes?date=2026-07-18')
            ->assertOk()
            ->assertJsonPath('notes', null);

        $this->putJson('/api/daily-sales/day-notes', [
            'date' => '2026-07-18',
            'notes' => 'Morning delivery arrived; afternoon sales were slower.',
        ])->assertOk()->assertJsonPath('notes', 'Morning delivery arrived; afternoon sales were slower.');

        $this->getJson('/api/daily-sales/day-notes?date=2026-07-18')
            ->assertOk()
            ->assertJsonPath('notes', 'Morning delivery arrived; afternoon sales were slower.');
    }

    private function createAnalyticsSale(
        int $companyId,
        string $date,
        float $revenue,
        ?float $cost,
    ): DailySale {
        $sequence = DailySale::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('sale_date', $date)
            ->count() + 1;

        $sale = DailySale::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'sale_number' => sprintf('AN-%s-%03d', str_replace('-', '', $date), $sequence),
            'sale_date' => $date,
            'status' => 'finalized',
            'total_amount' => $revenue,
            'paid_amount' => $revenue,
            'total_quantity' => 1,
            'finalized_at' => now(),
        ]);

        DailySaleItem::withoutGlobalScopes()->create([
            'daily_sale_id' => $sale->id,
            'company_id' => $companyId,
            'line_number' => 1,
            'product_name' => 'Analytics test line',
            'unit' => 'pcs',
            'quantity' => 1,
            'unit_price' => $revenue,
            'unit_cost' => $cost,
            'line_total' => $revenue,
            'cost_total' => $cost,
            'gross_profit' => $cost === null ? null : $revenue - $cost,
        ]);

        return $sale;
    }
}
