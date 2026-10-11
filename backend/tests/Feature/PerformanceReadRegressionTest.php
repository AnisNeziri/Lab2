<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CustomerCreditService;
use App\Services\InventorySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformanceReadRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_projection_is_authoritative_tenant_scoped_and_has_no_serialization_queries(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/products')->assertOk();
        $warehouse = Warehouse::create(['name' => 'Stock', 'code' => 'PERF']);
        $product = Product::create(['name' => 'Stock item', 'sku' => 'PERF', 'quantity' => 99, 'price' => 5, 'min_quantity' => 3]);
        WarehouseStock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 9, 'available_quantity' => 0, 'reserved_quantity' => 9, 'location_key' => 0]);
        $legacy = Product::create(['name' => 'Legacy', 'sku' => 'LEGACY', 'quantity' => 7, 'price' => 5]);
        $rows = Product::withStockTotals()->whereIn('id', [$product->id, $legacy->id])->get()->keyBy('id');
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->assertSame(0.0, $rows[$product->id]->available_quantity);
        $this->assertSame('out', $rows[$product->id]->stock_status);
        $this->assertSame(7.0, $rows[$legacy->id]->available_quantity);
        $payload = $rows->toArray();
        $this->assertCount(0, $queries);
        $this->assertArrayNotHasKey('stock_available_total', $payload[$product->id]);
        DB::table('warehouse_stock')->where('product_id', $product->id)->update(['available_quantity' => 4, 'reserved_quantity' => 5]);
        $this->assertSame(4.0, Product::withStockTotals()->find($product->id)->available_quantity);
        $this->assertSame(9.0, app(InventorySnapshotService::class)->onHandForProducts(collect([$product]))[$product->id]['on_hand']);
    }

    public function test_inventory_locator_query_count_is_bounded_as_product_rows_grow(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/products')->assertOk();
        $warehouse = Warehouse::create(['name' => 'Stock', 'code' => 'PERF']);
        for ($i = 0; $i < 45; $i++) {
            $p = Product::create(['name' => 'Item '.$i, 'sku' => 'PERF-'.$i, 'quantity' => 9, 'price' => 5]);
            WarehouseStock::create(['product_id' => $p->id, 'warehouse_id' => $warehouse->id, 'quantity' => 9, 'available_quantity' => 4, 'location_key' => 0]);
        }
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $response = $this->getJson('/api/inventory/locator?per_page=50')->assertOk()->assertJsonCount(45, 'data');
        $this->assertLessThan(20, count($queries), 'Product appends must not execute one query per row.');
        foreach ($response->json('data') as $row) {
            $this->assertSame(4, (int) $row['product']['available_quantity']);
        }
    }

    public function test_batch_overdue_matches_authoritative_allocation_for_partial_payments_null_dates_and_reversals(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/customers')->assertOk();
        $customers = collect();
        for ($i = 0; $i < 12; $i++) {
            $c = Customer::create(['name' => 'Buyer '.$i, 'current_debt' => 25]);
            $customers->push($c);
            foreach ([['debt_added', 20, today()->subDays(20)], ['debt_added', 30, null], ['payment', 25, null]] as [$type,$amount,$due]) {
                CustomerDebtTransaction::create(['customer_id' => $c->id, 'type' => $type, 'amount' => $amount, 'balance_before' => 0, 'balance_after' => 0, 'transaction_date' => today()->subDays(25), 'due_date' => $due]);
            }
            if ($i % 2 === 0) {
                CustomerDebtTransaction::create(['customer_id' => $c->id, 'type' => 'positive_adjustment', 'amount' => 7, 'balance_before' => 0, 'balance_after' => 7, 'transaction_date' => today(), 'due_date' => today()->subDay()]);
            }
        }
        $foreign = Company::factory()->create();
        $foreignId = DB::table('customers')->insertGetId(['company_id' => $foreign->id, 'name' => 'Foreign debt']);
        DB::table('customer_debt_transactions')->insert(['company_id' => $foreign->id, 'customer_id' => $foreignId, 'type' => 'debt_added', 'amount' => 999, 'balance_before' => 0, 'balance_after' => 999, 'transaction_date' => today(), 'due_date' => today()->subDay()]);
        $foreignCustomer = Customer::withoutGlobalScopes()->find($foreignId);
        $customers->push($foreignCustomer);
        $first = $customers->first();
        $reversed = CustomerDebtTransaction::create(['customer_id' => $first->id, 'type' => 'debt_added', 'amount' => 99, 'balance_before' => 0, 'balance_after' => 99, 'transaction_date' => today(), 'due_date' => today()->subDays(40)]);
        CustomerDebtTransaction::create(['customer_id' => $first->id, 'type' => 'cancellation', 'amount' => 99, 'balance_before' => 99, 'balance_after' => 0, 'transaction_date' => today(), 'reversed_transaction_id' => $reversed->id]);
        $service = app(CustomerCreditService::class);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $batch = $service->overdueForCustomers($customers);
        $this->assertLessThan(5, count($queries));
        $this->assertArrayNotHasKey($foreignId, $batch);
        foreach ($customers->where('company_id', $this->apiCompany->id) as $c) {
            $expected = $service->exposure($c->fresh());
            $this->assertSame($expected['overdue'], $batch[$c->id]['overdue']);
            $this->assertSame($expected['oldest_overdue_date'], $batch[$c->id]['oldest_overdue_date']);
        }
    }

    public function test_effective_stock_history_sort_preserves_null_fallback_and_tie_order(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/products')->assertOk();
        $p = Product::create(['name' => 'Item', 'sku' => 'SORT', 'quantity' => 0, 'price' => 1]);
        $common = ['company_id' => $this->apiCompany->id, 'product_id' => $p->id, 'type' => 'in', 'quantity' => 1, 'quantity_before' => 0, 'quantity_after' => 1];
        $older = DB::table('stock_movements')->insertGetId($common + ['occurred_at' => '2026-01-02 12:00:00', 'created_at' => '2026-02-03 12:00:00']);
        $fallback = DB::table('stock_movements')->insertGetId($common + ['occurred_at' => null, 'created_at' => '2026-02-01 12:00:00']);
        $tie = DB::table('stock_movements')->insertGetId($common + ['occurred_at' => '2026-02-01 12:00:00', 'created_at' => '2026-01-01 12:00:00']);
        $rows = $this->getJson('/api/stock-movements')->assertOk()->json();
        $this->assertSame([$tie, $fallback, $older], array_column($rows,'id'));
    }
}
