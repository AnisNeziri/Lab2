<?php

namespace Tests\Feature;

use App\Models\BusinessEvent;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\QualityInspection;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AimsToolRegistry;
use App\Services\AssistantPlanner;
use App\Services\InventoryIntegrityService;
use App\Services\ShipmentLogisticsService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DC5InventoryControlTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->actingAsApiUser();
        $category = Category::create($this->tenantAttributes(['name' => 'DC5 Fabric']));
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'DC5 Supplier']));
        $warehouse = $this->postJson('/api/warehouses', ['name' => 'Ferizaj', 'code' => 'FER', 'is_default' => true])->assertCreated()->json();
        $product = $this->postJson('/api/products', ['name' => 'Milano 04', 'sku' => 'DC5-M04', 'category_id' => $category->id,
            'supplier_id' => $supplier->id, 'default_warehouse_id' => $warehouse['id'], 'unit' => 'm', 'quantity' => 5000,
            'min_quantity' => 500, 'price' => 5, 'selling_price' => 5, 'purchase_price' => 2])->assertCreated()->json();
        $customer = Customer::create($this->tenantAttributes(['name' => 'DC5 Buyer', 'current_debt' => 0, 'current_credit' => 0]));

        return [$product, $supplier, $warehouse, $customer];
    }

    private function order(array $p, Customer $customer, int $quantity): array
    {
        $o = $this->postJson('/api/sales-orders', ['customer_id' => $customer->id, 'order_date' => now()->toDateString(),
            'payment_type' => 'cash', 'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $p['id'], 'quantity' => $quantity, 'unit' => 'm', 'unit_price' => 5]]])->assertCreated()->json();

        return $this->action($this->action($o, 'confirm'), 'reserve');
    }

    private function action(array $o, string $action, array $data = []): array
    {
        return $this->postJson('/api/sales-orders/'.$o['id'].'/'.$action, ['idempotency_key' => (string) Str::uuid(), ...$data])->assertOk()->json();
    }

    private function position(array $p): array
    {
        return $this->getJson('/api/inventory/products/'.$p['id'])->assertOk()->json('position');
    }

    public function test_representative_partial_reservation_cargo_receipt_quality_pick_dispatch_and_accounting(): void
    {
        [$p, $supplier, $warehouse, $customer] = $this->fixture();
        $a = $this->order($p, $customer, 2000);
        $b = $this->order($p, $customer, 4000);
        $s = $this->position($p);
        $this->assertEquals(5000, $s['physical']);
        $this->assertEquals(5000, $s['reserved']);
        $this->assertEquals(0, $s['available']);
        $this->assertEquals(1000, $s['backordered']);
        $po = $this->postJson('/api/purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse['id'],
            'ordered_at' => now()->toDateString(), 'expected_at' => now()->addDays(8)->toDateString(), 'currency' => 'EUR',
            'status' => 'ordered', 'items' => [['product_id' => $p['id'], 'description' => 'Fabric', 'unit' => 'm', 'quantity' => 8000, 'unit_price' => 2]]])->assertCreated()->json();
        $shipment = Shipment::create($this->tenantAttributes(['tracking_number' => 'SH-2048', 'status' => 'in_transit', 'eta' => now()->addDays(8), 'purchase_order_id' => $po['id'], 'warehouse_id' => $warehouse['id']]));
        $cargo = ShipmentItem::create($this->tenantAttributes(['shipment_id' => $shipment->id, 'purchase_order_item_id' => $po['items'][0]['id'],
            'product_id' => $p['id'], 'description' => 'Milano 04', 'unit' => 'm', 'quantity' => 8000, 'planned_quantity' => 8000, 'loaded_quantity' => 8000, 'base_quantity' => 8000]));
        $this->assertEquals(8000, $this->position($p)['in_transit']);
        $this->putJson('/api/quality/configuration', ['entity_type' => 'product', 'entity_id' => $p['id'], 'quality_inspection_mode' => 'required'])->assertOk();
        $receive = ['warehouse_id' => $warehouse['id'], 'items' => [['id' => $po['items'][0]['id'], 'shipment_item_id' => $cargo->id, 'accepted_quantity' => 4000]], 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/purchase-orders/'.$po['id'].'/receive', $receive)->assertOk();
        $this->postJson('/api/purchase-orders/'.$po['id'].'/receive', $receive)->assertOk();
        $s = $this->position($p);
        $this->assertEquals(9000, $s['physical']);
        $this->assertEquals(4000, $s['incoming']);
        $this->assertEquals(4000, $s['in_transit']);
        $receive['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/api/purchase-orders/'.$po['id'].'/receive', $receive)->assertOk();
        foreach (QualityInspection::all() as $i) {
            $last = $i->id === QualityInspection::max('id');
            $this->postJson('/api/quality/inspections/'.$i->id.'/finalize', ['accepted_quantity' => $last ? 3900 : 4000,
                'rejected_quantity' => 0, 'damaged_quantity' => 0, 'quarantine_quantity' => $last ? 100 : 0])->assertOk();
        }
        $b = $this->action($b, 'reserve');
        $a = $this->action($a, 'allocate');
        $s = $this->position($p);
        $this->assertEquals(13000, $s['physical']);
        $this->assertEquals(2000, $s['allocated']);
        $this->assertEquals(4000, $s['reserved']);
        $this->assertEquals(6900, $s['available']);
        $this->assertEquals(100, $s['quality_hold']);
        $this->assertEquals(0, $s['incoming']);
        $this->assertEquals(0, $s['in_transit']);
        $this->assertEquals(0, $s['backordered']);
        $allocation = $a['allocations'][0];
        $a = $this->action($a, 'pick', ['allocation_id' => $allocation['id'], 'quantity' => 2000, 'location_id' => $allocation['location_id'], 'inventory_lot_id' => null, 'manual_verification' => true]);
        $this->assertEquals(2000, $this->position($p)['picked']);
        $a = $this->action($a, 'pack', ['items' => [['allocation_id' => $allocation['id'], 'quantity' => 2000]]]);
        $dispatch = ['idempotency_key' => (string) Str::uuid(), 'package_ids' => [$a['packages'][0]['id']]];
        $this->postJson('/api/sales-orders/'.$a['id'].'/dispatch', $dispatch)->assertOk();
        $count = StockMovement::count();
        $events = BusinessEvent::count();
        $this->postJson('/api/sales-orders/'.$a['id'].'/dispatch', $dispatch)->assertOk();
        $this->assertEquals($count, StockMovement::count());
        $this->assertEquals($events, BusinessEvent::count());
        $s = $this->position($p);
        $this->assertEquals(11000, $s['physical']);
        $this->assertEquals(6900, $s['available']);
        $this->assertEquals(0, $s['difference']);
        $this->assertSame([], app(InventoryIntegrityService::class)->productSnapshot(Product::findOrFail($p['id']))['issues']);
        $this->assertGreaterThan(0, JournalEntry::where('status', 'posted')->count());
        $reconciliation = $this->getJson('/api/accounting/reconciliation')->assertOk()->json();
        $this->assertArrayHasKey('inventory', $reconciliation['rows']);
        $this->assertEquals(0, $reconciliation['rows']['inventory']['difference']);
        $this->assertTrue($reconciliation['rows']['inventory']['reconciled']);
        $this->getJson('/api/inventory/control-center?search=Milano')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.position.available', 6900);
        $this->getJson('/api/system-integrity')->assertOk();
    }

    public function test_new_reads_and_cargo_receipts_are_tenant_scoped_and_bounded(): void
    {
        [$p, , $warehouse] = $this->fixture();
        $other = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'api_token' => hash('sha256', 'dc5-other'), 'email_verified_at' => now()]);
        $this->withToken('dc5-other')->getJson('/api/inventory/products/'.$p['id'])->assertNotFound();
        $this->getJson('/api/inventory/control-center?warehouse_id='.$warehouse['id'])->assertUnprocessable();
        $this->getJson('/api/inventory/control-center')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/inventory/control-center?per_page=51')->assertUnprocessable();
    }

    public function test_priority_reservation_run_is_durable_on_retries_even_after_new_supply(): void
    {
        [$p, , , $customer] = $this->fixture();
        $a = $this->order($p, $customer, 2000);
        $b = $this->order($p, $customer, 4000);
        $this->action($a, 'release');
        SalesOrder::findOrFail($b['id'])->update(['priority' => 1]);
        $request = ['idempotency_key' => (string) Str::uuid()];
        $result = $this->postJson('/api/fulfillment/reserve-waiting', $request)->assertOk()->json();
        $this->assertEquals($b['id'], $result['orders'][0]['order_id']);
        $this->assertEquals(4000, SalesOrderItem::where('sales_order_id', $b['id'])->sum('reserved_quantity'));
        app(StockMovementService::class)->store(['product_id' => $p['id'], 'type' => 'in', 'quantity' => 100,
            'movement_code' => 'import_adjustment_in', 'reason' => 'New supply after successful reservation run', 'idempotency_key' => (string) Str::uuid()]);
        $this->postJson('/api/fulfillment/reserve-waiting', $request)->assertOk()->assertExactJson($result);
        $this->assertEquals(100, $this->position($p)['available']);
        $this->postJson('/api/fulfillment/reserve-waiting', [...$request, 'product_id' => $p['id']])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_reservation_runs', 1);
        $plan = app(AssistantPlanner::class)->plan('sa Milano 04 kemi ne Ferizaj');
        $this->assertSame('Milano 04', $plan['term']);
        $this->assertSame('Ferizaj', $plan['warehouse_term']);
        $facts = app(AimsToolRegistry::class)->execute('get_product_availability', ['product_id' => $p['id'], 'warehouse_term' => 'Ferizaj']);
        $this->assertEquals(100, $facts['data']['position']['available']);
    }

    public function test_split_shipments_cannot_overreceive_or_rewrite_receipt_lineage_and_legacy_receipts_are_explicit(): void
    {
        [$p, $supplier, $warehouse] = $this->fixture();
        $po = $this->postJson('/api/purchase-orders', ['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse['id'], 'ordered_at' => now()->toDateString(),
            'status' => 'ordered', 'currency' => 'EUR', 'items' => [['product_id' => $p['id'], 'description' => 'Split fabric', 'unit' => 'm', 'quantity' => 20, 'unit_price' => 2]]])->assertCreated()->json();
        $lines = [];
        foreach ([8, 7] as $i => $quantity) {
            $shipment = Shipment::create($this->tenantAttributes(['tracking_number' => 'SPLIT-'.$i, 'status' => 'in_transit', 'purchase_order_id' => $po['id']]));
            $lines[] = ShipmentItem::create($this->tenantAttributes(['shipment_id' => $shipment->id, 'purchase_order_item_id' => $po['items'][0]['id'], 'product_id' => $p['id'], 'description' => 'Fabric',
                'unit' => 'm', 'quantity' => $quantity, 'planned_quantity' => $quantity, 'loaded_quantity' => $quantity, 'base_quantity' => $quantity]));
        }
        $this->assertEquals(15, $this->position($p)['in_transit']);
        $this->assertEquals(20, $this->position($p)['incoming']);
        $receive = ['warehouse_id' => $warehouse['id'], 'items' => [['id' => $po['items'][0]['id'], 'shipment_item_id' => $lines[0]->id, 'accepted_quantity' => 6]], 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/purchase-orders/'.$po['id'].'/receive', $receive)->assertOk();
        $receive['idempotency_key'] = (string) Str::uuid();
        $receive['items'][0]['accepted_quantity'] = 3;
        $this->postJson('/api/purchase-orders/'.$po['id'].'/receive', $receive)->assertUnprocessable();
        $this->assertEquals(9, $this->position($p)['in_transit']);
        $this->assertEquals(14, $this->position($p)['incoming']);
        try {
            app(ShipmentLogisticsService::class)->update($lines[0]->shipment, ['purchase_order_id' => $po['id'], 'items' => []]);
            $this->fail('Posted receipt cargo was deleted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be removed', $e->getMessage());
        }
        unset($receive['items'][0]['shipment_item_id']);
        $receive['items'][0]['accepted_quantity'] = 1;
        $this->postJson('/api/purchase-orders/'.$po['id'].'/receive', $receive)->assertOk();
        $this->assertNull($this->position($p)['in_transit']);
        $this->assertEquals(13, $this->position($p)['incoming']);
        DB::enableQueryLog();
        $response = $this->getJson('/api/inventory/control-center?per_page=50')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(30, $queries);
        $this->assertLessThan(16000, strlen($response->getContent()));
        fwrite(STDOUT, "DC5 bounded control read: {$queries} queries, ".strlen($response->getContent())." bytes.\n");
        ShipmentItem::create($this->tenantAttributes(['shipment_id' => $lines[1]->shipment_id, 'product_id' => $p['id'],
            'description' => 'Unattributed standalone cargo', 'unit' => 'm', 'quantity' => 3, 'loaded_quantity' => 3, 'base_quantity' => 3]));
        $this->assertNull($this->position($p)['incoming']);
        $this->assertNull($this->position($p)['in_transit']);
    }
}
