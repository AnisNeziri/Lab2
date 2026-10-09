<?php

namespace Tests\Feature;

use App\Jobs\RunStrategicSimulation;
use App\Models\AnalyticsSnapshot;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\StrategicSimulation as Run;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\AimsToolRegistry;
use App\Services\StrategicSimulationBaseline as Baseline;
use App\Services\StrategicSimulationEngine as Engine;
use App\Services\StrategicSimulationService as Sim;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StrategicSimulationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): Product
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(12, 0));
        $c = Category::create(['name' => 'Furniture materials']);
        $p = Product::create(['name' => 'Upholstery fabric', 'sku' => 'TWIN-1', 'category_id' => $c->id, 'unit' => 'm', 'quantity' => 200, 'min_quantity' => 30, 'high_stock_threshold' => 5000, 'purchase_price' => 2, 'price' => 5]);
        $p->forceFill(['created_at' => now()->subYear()])->save();
        foreach (['China Factory A' => [2, 3], 'Balkan Supplier B' => [3, 2]] as $name => [$price,$lead]) {
            $s = Supplier::create(['name' => $name, 'is_active' => true]);
            ProductSupplier::create(['product_id' => $p->id, 'supplier_id' => $s->id, 'purchase_price' => $price, 'currency' => 'EUR', 'exchange_rate_to_base' => 1, 'usual_lead_time_days' => $lead, 'pack_size' => 5, 'minimum_order_quantity' => 10, 'is_active' => true]);
        }
        $this->history($p, 0);

        return $p;
    }

    private function history(Product $p, int $w): void
    {
        for ($d = 1; $d <= 90; $d++) {
            if (! $w) {
                AnalyticsSnapshot::create(['entity_type' => 'demand_observation', 'entity_id' => $p->id, 'warehouse_id' => 0, 'snapshot_date' => today()->subDays($d), 'observed_at' => today()->subDays($d)->endOfDay(), 'feature_version' => 'observed-v2', 'facts' => ['unit' => 'm', 'demand' => 10, 'sales_quantity' => 10, 'returns_quantity' => 0, 'available' => 200, 'stockout' => false, 'quality' => 'observed', 'complete' => true, 'potentially_censored' => false, 'provenance' => ['sales' => ['sale:'.$d]]]]);
            } else {
                AnalyticsSnapshot::create(['entity_type' => 'inventory', 'entity_id' => $p->id, 'warehouse_id' => $w, 'snapshot_date' => today()->subDays($d), 'observed_at' => today()->subDays($d)->endOfDay(), 'feature_version' => 'observed-v1', 'facts' => ['unit' => 'm', 'available' => 100]]);
                StockMovement::create(['product_id' => $p->id, 'warehouse_id' => $w, 'type' => 'out', 'quantity' => 5, 'quantity_before' => 200, 'quantity_after' => 195, 'warehouse_quantity_after' => 100, 'unit_snapshot' => 'm', 'movement_code' => 'daily_sale', 'source_type' => 'daily_sale', 'source_id' => $d, 'affects_company_quantity' => true, 'occurred_at' => today()->subDays($d)->midDay()]);
            }
        }
    }

    private function definition(Product $p, array $a = [], array $extra = []): array
    {
        return array_replace_recursive(['name' => 'China supply stress', 'horizon' => 90, 'scope' => ['product_ids' => [$p->id]], 'assumptions' => $a, 'optimize' => false], $extra);
    }

    private function runScenario(array $d): Run
    {
        $r = app(Sim::class)->submit($d);
        app(Sim::class)->run($r['id']);
        $r = Run::findOrFail($r['id']);
        $this->assertSame('COMPLETED', $r->status, $r->error ?? '');

        return $r;
    }

    private function operationalState(): array
    {
        $tables = array_diff(DB::getSchemaBuilder()->getTableListing(null, false), ['strategic_simulations', 'jobs', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'activity_logs', 'audit_logs']);
        $out = [];
        foreach (array_unique($tables) as $t) {
            if (DB::getSchemaBuilder()->hasTable($t)) {
                $out[$t] = hash('sha256', json_encode(DB::table($t)->get()->toArray()));
            }
        }

        return $out;
    }

    public function test_pending_dedup_and_cancellation_are_isolated(): void
    {
        $p = $this->fixture();
        $before = $this->operationalState();
        $d = $this->definition($p, [['type' => 'demand', 'action' => 'percent', 'value' => 20]]);
        $a = $this->postJson('/api/strategic-simulation', $d)->assertStatus(202)->json();
        $b = $this->postJson('/api/strategic-simulation', $d)->assertStatus(202)->json();
        $this->assertSame($a['id'], $b['id']);
        $this->postJson('/api/strategic-simulation/'.$a['id'].'/cancel')->assertOk()->assertJsonPath('status', 'CANCELLED');
        app(Sim::class)->run($a['id']);
        $this->assertNull(Run::find($a['id'])->result);
        $this->assertSame($before, $this->operationalState());
        Queue::assertPushed(RunStrategicSimulation::class, 1);
    }

    public function test_demand_once_frozen_reproducibility_and_versioned_rerun(): void
    {
        $p = $this->fixture();
        $before = $this->operationalState();
        $r = $this->runScenario($this->definition($p, [['type' => 'demand', 'action' => 'percent', 'value' => 20]]));
        $this->assertEquals(10, $r->result['baseline']['rows'][0]['timeline'][0]['demand']);
        $this->assertEquals(12, $r->result['scenario']['rows'][0]['timeline'][0]['demand']);
        $copy = $r->baseline;
        $first = app(Engine::class)->calculate($copy, $r->definition['assumptions'], false);
        $this->travel(20)->days();
        $again = app(Engine::class)->calculate($copy, $r->definition['assumptions'], false);
        unset($first['performance']['seconds'],$again['performance']['seconds']);
        $this->assertSame($first, $again);
        $this->assertSame($before, $this->operationalState());
        $derived = app(Sim::class)->derive($r->id, ['assumptions' => [['type' => 'demand', 'action' => 'percent', 'value' => 30]]]);
        $this->assertSame($copy, Run::find($derived['id'])->baseline);
        $this->assertSame($r->id, $derived['parent_id']);
        $fresh = app(Sim::class)->rerun($r->id);
        $this->assertNull(Run::find($fresh['id'])->baseline);
        $this->assertNotSame($r->version, $fresh['version']);
        try {
            $r->update(['baseline' => []]);
            $this->fail('Frozen baseline changed');
        } catch (\LogicException) {
        }
    }

    public function test_combined_domains_optimizer_limit_and_no_automation_leakage(): void
    {
        $p = $this->fixture();
        $s = Supplier::first();
        $po = PurchaseOrder::create(['supplier_id' => $s->id, 'po_number' => 'CHINA-001', 'status' => 'ordered', 'ordered_at' => today(), 'expected_at' => today()->addDays(3), 'currency' => 'EUR', 'exchange_rate' => 1, 'subtotal' => 200, 'total_amount' => 200]);
        $po->items()->create(['product_id' => $p->id, 'description' => $p->name, 'quantity' => 100, 'base_quantity' => 100, 'unit' => 'm', 'unit_price' => 2, 'line_total' => 200]);
        $ship = Shipment::create(['tracking_number' => 'SH-TWIN', 'purchase_order_id' => $po->id, 'status' => 'in_transit', 'origin_port' => 'China', 'destination_port' => 'Balkans', 'eta' => $po->expected_at]);
        $before = $this->operationalState();
        $d = $this->definition($p, [['type' => 'demand', 'action' => 'percent', 'value' => 20], ['type' => 'supplier', 'action' => 'lead_days', 'supplier_id' => $s->id, 'days' => 12], ['type' => 'logistics', 'action' => 'delay', 'shipment_id' => $ship->id, 'days' => 10], ['type' => 'financial', 'action' => 'collections_delay', 'days' => 7], ['type' => 'procurement', 'action' => 'commitment_limit', 'value' => 90000]], ['optimize' => true]);
        $r = $this->runScenario($d);
        $this->assertNotEmpty($r->result['alternatives']);
        foreach ($r->result['alternatives'] as $a) {
            $this->assertLessThanOrEqual(9000000, Money::minor($a['summary']['commitment']));
        }$path = $r->result['scenario']['rows'][0]['timeline'];
        $this->assertEquals(100, collect($path)->sum('incoming'));
        $this->assertSame(today()->addDays(25)->toDateString(), collect($path)->first(fn ($x) => $x['incoming'] > 0)['date']);
        $this->assertSame($before, $this->operationalState());
        $this->assertTrue($r->result['read_only']);
        $this->assertSame('unavailable', $r->result['stochastic']['state']);
        $this->assertNotEmpty($r->result['dependency_graph']);
    }

    public function test_finance_customer_share_and_separate_currencies_use_frozen_evidence(): void
    {
        $p = $this->fixture();
        $b = app(Baseline::class)->freeze($this->definition($p));
        $b['customer_shares'] = [['customer_id' => 7, 'product_id' => $p->id, 'share' => .5]];
        $b['v7']['evidence'] = ['as_of' => today()->toDateString(), 'cash' => ['by_currency' => ['EUR' => '1000.00', 'USD' => null]], 'receivables' => [['key' => 'debt:1', 'customer_id' => 7, 'currency' => 'EUR', 'amount' => '100.01', 'expected_date' => today()->addDays(2)->toDateString()]], 'commitments' => [['key' => 'payable:1', 'currency' => 'USD', 'amount' => '200.02', 'expected_date' => today()->addDays(10)->toDateString(), 'source_type' => 'supplier_payable']], 'settings' => []];
        $a = [['type' => 'demand', 'action' => 'percent', 'value' => 20], ['type' => 'customer', 'action' => 'percent', 'value' => 30, 'customer_id' => 7], ['type' => 'financial', 'action' => 'collections_delay', 'days' => 7]];
        $r = app(Engine::class)->calculate($b, $a, false);
        $this->assertEquals(13.8, $r['scenario']['rows'][0]['timeline'][0]['demand']);
        $this->assertSame('1100.01', $r['scenario']['finance']['currencies']['EUR']['expected_closing_cash']);
        $this->assertNull($r['scenario']['finance']['currencies']['USD']['expected_closing_cash']);
        $this->assertSame(today()->addDays(9)->toDateString(), $r['scenario']['finance']['currencies']['EUR']['timeline'][0]['date']);
    }

    public function test_warehouse_rebalance_conserves_quantity_without_real_transfers(): void
    {
        $p = $this->fixture();
        $ws = [];
        foreach (['Main', 'Branch'] as $name) {
            $w = Warehouse::create(['name' => $name, 'code' => strtoupper($name), 'is_active' => true]);
            WarehouseStock::create(['product_id' => $p->id, 'warehouse_id' => $w->id, 'quantity' => 100, 'available_quantity' => 100]);
            $this->history($p, $w->id);
            $ws[] = $w;
        }$d = $this->definition($p, [['type' => 'warehouse', 'action' => 'rebalance', 'product_id' => $p->id, 'source_warehouse_id' => $ws[0]->id, 'warehouse_id' => $ws[1]->id, 'value' => 25]], ['scope' => ['warehouse_ids' => array_column($ws, 'id'), 'transfer_lead_days' => 2]]);
        $before = $this->operationalState();
        $r = $this->runScenario($d);
        $this->assertEquals(25, collect(collect($r->result['scenario']['rows'])->first(fn ($x) => $x['warehouse']['id'] === $ws[1]->id)['timeline'])->sum('incoming'));
        $this->assertSame($before, $this->operationalState());
    }

    public function test_closed_warehouse_holds_actual_and_optimized_supply_until_reopening(): void
    {
        $p = $this->fixture();
        $w = Warehouse::create(['name' => 'Temporary closure', 'code' => 'CLOSED', 'is_active' => true]);
        WarehouseStock::create(['product_id' => $p->id, 'warehouse_id' => $w->id, 'quantity' => 100, 'available_quantity' => 100]);
        $this->history($p, $w->id);
        $other = Warehouse::create(['name' => 'Open warehouse', 'code' => 'OPEN', 'is_active' => true]);
        WarehouseStock::create(['product_id' => $p->id, 'warehouse_id' => $other->id, 'quantity' => 100, 'available_quantity' => 100]);
        $this->history($p, $other->id);
        $s = Supplier::orderBy('id')->first();
        $po = PurchaseOrder::create(['supplier_id' => $s->id, 'warehouse_id' => $w->id, 'po_number' => 'CLOSURE-001', 'status' => 'ordered', 'ordered_at' => today(), 'expected_at' => today()->addDays(3), 'currency' => 'EUR', 'exchange_rate' => 1, 'subtotal' => 200, 'total_amount' => 200]);
        $po->items()->create(['product_id' => $p->id, 'description' => $p->name, 'quantity' => 100, 'base_quantity' => 100, 'unit' => 'm', 'unit_price' => 2, 'line_total' => 200]);
        $before = $this->operationalState();
        $r = $this->runScenario($this->definition($p, [
            ['type' => 'warehouse', 'action' => 'unavailable', 'warehouse_id' => $w->id, 'days' => 10],
            ['type' => 'supplier', 'action' => 'lead_days', 'supplier_id' => $s->id, 'days' => 12],
        ], ['scope' => ['warehouse_ids' => [$w->id]], 'optimize' => true]));
        $path = collect($r->result['scenario']['rows'][0]['timeline'])->keyBy('date');
        $this->assertEquals(100, $path[today()->addDays(10)->toDateString()]['incoming']);
        $this->assertEquals(100, $path[today()->addDays(15)->toDateString()]['incoming']);
        $this->assertEquals(200, $path->sum('incoming'));
        $this->assertNotEmpty($r->result['alternatives']);
        foreach ($r->result['alternatives'] as $alternative) {
            $this->assertSame(today()->toDateString(), $alternative['rows'][0]['timeline'][0]['date']);
            $this->assertTrue($alternative['rows'][0]['timeline'][0]['stockout']);
            foreach ($alternative['lines'] as $line) {
                foreach (array_merge($line['purchases'], $line['transfers']) as $supply) {
                    $this->assertGreaterThanOrEqual(today()->addDays(10)->toDateString(), $supply['expected_at']);
                }
            }
        }
        $this->assertSame($before, $this->operationalState());
    }

    public function test_long_horizon_and_sensitivity_do_not_claim_invented_forecasts(): void
    {
        $p = $this->fixture();
        $r = $this->runScenario($this->definition($p, [['type' => 'demand', 'action' => 'percent', 'value' => 20]], ['horizon' => 365]));
        $this->assertSame(1, $r->result['scenario']['summary']['unsupported_scopes']);
        $this->assertCount(90, $r->result['scenario']['rows'][0]['timeline']);
        $this->postJson('/api/strategic-simulation/'.$r->id.'/sensitivity', ['assumption_index' => 0, 'values' => [0, 20, 50]])->assertOk()->assertJsonCount(3, 'points');
        $this->postJson('/api/strategic-simulation/'.$r->id.'/sensitivity', ['assumption_index' => 0, 'values' => [0, 600]])->assertUnprocessable();
        $r2 = $this->runScenario($this->definition($p, [], ['horizon' => 365, 'scope' => ['long_horizon_mode' => 'repeat_pattern']]));
        $this->assertCount(365, $r2->result['scenario']['rows'][0]['timeline']);
    }

    public function test_tenant_permissions_tools_and_explicit_action_boundary(): void
    {
        $p = $this->fixture();
        $r = $this->runScenario($this->definition($p, [], ['optimize' => true]));
        $this->postJson('/api/strategic-simulation/'.$r->id.'/response', ['alternative' => 'balanced', 'confirm' => false])->assertUnprocessable();
        $this->assertDatabaseCount('supply_optimization_plans', 0);
        $a = $this->postJson('/api/strategic-simulation/'.$r->id.'/response', ['alternative' => 'balanced', 'confirm' => true])->assertStatus(202)->json();
        $this->assertStringContainsString('/supply-optimizer?plan=', $a['url']);
        $this->assertDatabaseCount('supply_optimization_plans', 1);
        $this->assertDatabaseCount('purchase_requests', 0);
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->postJson('/api/strategic-simulation/'.$r->id.'/response', ['alternative' => 'balanced', 'confirm' => true])->assertStatus(202)->assertJsonPath('plan_id', $a['plan_id']);
        $names = array_column(app(AimsToolRegistry::class)->catalog(), 'name');
        foreach (AimsToolRegistry::SIMULATION_TOOLS as $name) {
            $this->assertContains($name, $names);
        }$other = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'api_token' => hash('sha256', 'other')]);
        $this->withHeader('Authorization', 'Bearer other')->getJson('/api/strategic-simulation/'.$r->id)->assertNotFound();
    }

    public function test_v9_typed_tools_preserve_lineage_and_never_prepare_operational_drafts(): void
    {
        $p = $this->fixture();
        $before = $this->operationalState();
        $first = $this->postJson('/api/intelligence-assistant/ask', ['question' => 'What happens if demand grows 20%?'])->assertOk()->assertJsonPath('intent', 'strategic_simulation')->assertJsonPath('state', 'success')->json();
        $r = Run::latest('id')->first();
        $this->assertEquals(20, $r->definition['assumptions'][0]['value']);
        app(Sim::class)->run($r->id);
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'And China Factory A is 10 days late.', 'conversation_id' => $first['conversation_id']])->assertOk()->assertJsonPath('state', 'success');
        $derived = Run::latest('id')->first();
        $this->assertSame($r->id, $derived->parent_id);
        $this->assertCount(2, $derived->definition['assumptions']);
        $this->assertSame($r->fresh()->baseline, $derived->baseline);
        app(Sim::class)->run($derived->id);
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'Keep purchases under €70,000.', 'conversation_id' => $first['conversation_id']])->assertOk()->assertJsonPath('state', 'success');
        $limited = Run::latest('id')->first();
        $this->assertCount(3, $limited->definition['assumptions']);
        app(Sim::class)->run($limited->id);
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'Why does this scenario create stockouts?', 'conversation_id' => $first['conversation_id']])->assertOk()->assertJsonPath('cards.0.tool', 'explain_simulation_result');
        $this->assertSame($before, $this->operationalState());
        $this->assertDatabaseCount('supply_optimization_plans', 0);
    }

    public function test_supplier_loss_downturn_periods_and_insufficient_evidence(): void
    {
        $p = $this->fixture();
        $s = Supplier::first();
        $r = $this->runScenario($this->definition($p, [['type' => 'supplier', 'action' => 'unavailable', 'supplier_id' => $s->id, 'days' => 90], ['type' => 'demand', 'action' => 'percent', 'value' => -30, 'start_day' => 10, 'end_day' => 20]], ['optimize' => true]));
        $path = $r->result['scenario']['rows'][0]['timeline'];
        $this->assertEquals(10, $path[9]['demand']);
        $this->assertEquals(7, $path[10]['demand']);
        $this->assertEquals(10, $path[21]['demand']);
        foreach ($r->result['alternatives'] as $a) {
            foreach ($a['lines'] as $line) {
                foreach ($line['purchases'] as $purchase) {
                    $this->assertNotSame($s->id, $purchase['supplier_id']);
                }
            }
        }
        $p2 = Product::create(['name' => 'New unknown', 'sku' => 'TWIN-UNKNOWN', 'price' => 0, 'unit' => 'pcs', 'quantity' => 5, 'min_quantity' => 1, 'category_id' => $p->category_id, 'purchase_price' => null]);
        $unknown = $this->runScenario($this->definition($p2));
        $this->assertSame(1, $unknown->result['scenario']['summary']['unsupported_scopes']);
        $this->assertNull($unknown->result['scenario']['rows'][0]['purchase_estimate']);
        $removals = Supplier::orderBy('id')->pluck('id')->map(fn ($id) => ['type' => 'supplier', 'action' => 'remove', 'supplier_id' => $id])->all();
        $loss = $this->runScenario($this->definition($p, $removals));
        $this->assertSame([], $loss->result['scenario']['rows'][0]['suppliers']);
        $this->assertNull($loss->result['impact']['money']['purchase_requirement_by_currency']['EUR']);
    }

    public function test_restricted_users_cannot_read_sensitive_simulation_or_execute_tools(): void
    {
        $p = $this->fixture();
        $r = $this->runScenario($this->definition($p));
        $u = User::factory()->create(['company_id' => $p->company_id, 'role' => 'staff', 'api_token' => hash('sha256', 'limited')]);
        $this->withHeader('Authorization', 'Bearer limited')->getJson('/api/strategic-simulation/'.$r->id)->assertForbidden();
        $this->assertNotContains('get_simulation_result', array_column(app(AimsToolRegistry::class)->catalog(), 'name'));
    }
}
