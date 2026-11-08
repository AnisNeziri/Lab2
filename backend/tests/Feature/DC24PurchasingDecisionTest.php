<?php

namespace Tests\Feature;

use App\Models\{Company, Product, PurchaseRequest, Rfq, Supplier, User, Warehouse};
use App\Services\{JwtService, ProcurementService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DC24PurchasingDecisionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/procurement/purchase-comparison';

    public function test_quotes_are_normalized_and_three_what_ifs_leave_operational_records_unchanged(): void
    {
        $this->actingAsApiUser()->getJson('/api/me')->assertOk();
        $product = Product::create($this->tenantAttributes(['name' => 'Fixture linen', 'sku' => 'F-LINEN', 'unit' => 'm', 'quantity' => 0, 'price' => 30]));
        $product->units()->create($this->tenantAttributes(['code' => 'roll', 'label' => 'Roll', 'conversion_mode' => 'fixed',
            'factor_to_base' => 25, 'allow_purchase' => true, 'is_active' => true]));
        $supplierA = Supplier::create($this->tenantAttributes(['name' => 'Supplier A']));
        $supplierB = Supplier::create($this->tenantAttributes(['name' => 'Supplier B']));
        \App\Models\ProductSupplier::create($this->tenantAttributes(['product_id' => $product->id,
            'supplier_id' => $supplierA->id, 'purchase_price' => 1.60, 'currency' => 'EUR', 'pack_size' => 25,
            'minimum_order_quantity' => 25, 'is_active' => true]));
        $request = PurchaseRequest::create($this->tenantAttributes(['request_number' => 'PR-FIXTURE',
            'status' => 'approved', 'requested_at' => today(), 'currency' => 'EUR']));
        $line = $request->items()->create(['product_id' => $product->id, 'description' => $product->name, 'unit' => 'roll', 'quantity' => 4]);
        $rfq = Rfq::create($this->tenantAttributes(['purchase_request_id' => $request->id, 'rfq_number' => 'RFQ-FIXTURE', 'status' => 'issued']));
        foreach ([$supplierA, $supplierB] as $supplier) $rfq->suppliers()->create(['supplier_id' => $supplier->id]);
        $quote = function ($supplier, $price, $currency, $rate, $until, $lead, $fxDate = null) use ($rfq, $line) {
            return app(ProcurementService::class)->createQuote($rfq, ['supplier_id' => $supplier->id, 'currency' => $currency,
                'exchange_rate' => $rate, 'exchange_rate_date' => $fxDate, 'valid_until' => $until,
                'shipping_terms' => 'Delivery to named warehouse', 'notes' => "Source: supplier reference L-442\nReceived: ".today()->toDateString(),
                'items' => [['purchase_request_item_id' => $line->id, 'unit_price' => $price,
                    'offered_quantity' => 4, 'minimum_order_quantity' => 4, 'lead_time_days' => $lead]]]);
        };
        $quote($supplierA, 5, 'EUR', 1, today()->addDays(5), 5); // superseded; must not win
        $received = $quote($supplierA, 50, 'USD', 0.8, today()->addDays(5), 8, today());
        $this->assertDatabaseHas('supplier_quotes', ['id' => $received->id, 'revision' => 2,
            'shipping_terms' => 'Delivery to named warehouse', 'notes' => "Source: supplier reference L-442\nReceived: ".today()->toDateString()]);
        $quote($supplierB, 45, 'EUR', 1, today()->addDays(5), 3);
        $payload = ['product_id' => $product->id, 'quantity' => 4, 'unit' => 'roll', 'budget' => 200, 'priority' => 'cost'];
        $answer = $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('read_only', true)->json();
        $this->assertEquals(100, $answer['normalized']['base_quantity']);
        $this->assertSame($supplierA->id, $answer['recommendation']['id']);
        $this->assertSame('/procurement?rfq='.$rfq->id.'&supplier='.$supplierA->id.'#received-supplier-quote',
            $answer['suppliers'][0]['quote_recording_url']);
        $this->assertSame('160.00', $answer['suppliers'][0]['quote']['total']); // 4 × USD 50 × 0.8
        $this->assertSame('1.600000', $answer['suppliers'][0]['quote']['unit_price']);
        $this->assertSame([50, 100, 150], array_map('intval', array_column($answer['scenarios'], 'base_quantity')));
        $this->assertSame(['80.00', '160.00', '240.00'], array_column($answer['scenarios'], 'material_cost'));
        $this->assertSame(['120.00', '40.00', '-40.00'], array_column($answer['scenarios'], 'remaining_envelope'));
        $unpriced = Product::create($this->tenantAttributes(['name' => 'Unquoted trim', 'sku' => 'DC25-TRIM', 'unit' => 'm', 'quantity' => 0, 'price' => 4]));
        $portfolio = ['budget' => 500, 'lines' => [
            ['product_id' => $product->id, 'quantity' => 4, 'unit' => 'roll', 'supplier_id' => $supplierA->id, 'assumed_unit_price' => 100],
            ['product_id' => $unpriced->id, 'quantity' => 50, 'assumed_unit_price' => 3, 'assumed_extra_cost' => 10],
        ]];
        $this->postJson(self::URL, $portfolio)->assertOk()
            ->assertJsonPath('scenarios.1.lines.0.cost_source', 'quote')->assertJsonPath('scenarios.1.lines.0.cost', '160.00')
            ->assertJsonPath('scenarios.1.lines.1.cost_source', 'assumption')->assertJsonPath('scenarios.1.lines.1.cost', '150.00')
            ->assertJsonPath('scenarios.1.material_total', '310.00')->assertJsonPath('scenarios.1.priced_subtotal', '320.00')
            ->assertJsonPath('scenarios.1.illustrative_envelope_remaining', '180.00')->assertJsonPath('scenarios.1.remaining_envelope_before_extras', null);
        unset($portfolio['lines'][1]['assumed_unit_price']);
        $this->postJson(self::URL, $portfolio)->assertOk()
            ->assertJsonPath('scenarios.1.unpriced_count', 1)->assertJsonPath('scenarios.1.material_total', null)
            ->assertJsonPath('scenarios.1.priced_subtotal', '170.00')->assertJsonPath('scenarios.1.illustrative_envelope_remaining', null);
        $this->postJson(self::URL, $payload + ['required_by' => today()->addDays(4)->toDateString()])
            ->assertOk()->assertJsonPath('recommendation', null)->assertJsonPath('suppliers.0.quoted_date_fits', false);
        $quote($supplierA, 1, 'USD', 0.8, today()->subDay(), 1, today());
        $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('recommendation', null)
            ->assertJsonPath('suppliers.0.quote', null); // an expired latest revision does not resurrect the old one
        $this->assertDatabaseCount('purchase_requests', 1);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_platform_actor_and_tenant_actors_have_separate_access_and_cross_tenant_inputs_fail(): void
    {
        $this->actingAsApiUser()->getJson('/api/me')->assertOk();
        $tenant = $this->apiCompany;
        $foreign = Company::factory()->create();
        $product = Product::create($this->tenantAttributes(['name' => 'Tenant fabric', 'sku' => 'T1', 'unit' => 'm', 'quantity' => 0, 'price' => 3]));
        $foreignProduct = Product::withoutEvents(fn () => Product::create(['company_id' => $foreign->id, 'name' => 'Foreign fabric', 'sku' => 'F1', 'unit' => 'm', 'quantity' => 0, 'price' => 9]));
        $warehouse = Warehouse::withoutEvents(fn () => Warehouse::create(['company_id' => $foreign->id, 'name' => 'Foreign warehouse', 'code' => 'FW']));
        $provider = \App\Models\IntegrationProvider::create($this->tenantAttributes(['category' => 'ai', 'provider_key' => 'isolated-test', 'display_name' => 'AI runtime']));
        $user = User::withoutGlobalScopes()->where('company_id', $tenant->id)->firstOrFail();
        $payload = ['product_id' => $product->id, 'quantity' => 20];
        $this->getJson('/api/commercial?company_id='.$foreign->id)->assertForbidden();
        $this->getJson('/api/superadmin/users')->assertForbidden();
        $this->getJson('/api/platform')->assertOk()->assertJsonMissingPath('ai')->assertJsonMissingPath('search.provider');
        $this->putJson('/api/integrations/providers/'.$provider->id, ['enabled' => false])->assertForbidden();
        \Illuminate\Support\Facades\Cache::put('tracking.aisstream.connection', ['state' => 'error', 'message' => 'private runtime details']);
        $this->getJson('/api/integrations/health')->assertOk()->assertDontSee('private runtime details');
        $this->postJson(self::URL, ['product_id' => $foreignProduct->id, 'quantity' => 20])->assertNotFound();
        $this->postJson(self::URL, $payload + ['warehouse_id' => $warehouse->id])->assertNotFound();

        $operator = User::factory()->create(['company_id' => null, 'role' => 'superadmin', 'email_verified_at' => now()]);
        $this->withHeader('Authorization', 'Bearer '.app(JwtService::class)->issueTokens($operator)['access_token']);
        $this->getJson('/api/commercial?company_id='.$tenant->id)->assertOk()->assertJsonPath('commissioning', null);
        $this->getJson('/api/products')->assertForbidden();
        $this->postJson(self::URL, $payload)->assertForbidden();
        config(['system.operation_mode' => 'online']);
        $this->postJson('/api/superadmin/users/'.$user->id.'/reset-password', [])->assertForbidden();
        $this->assertDatabaseHas('security_events', ['company_id' => null, 'user_id' => $operator->id, 'event' => 'commercial.reviewed', 'subject_id' => $tenant->id]);

        $tenant->update(['role_permissions' => ['staff' => ['inventory.view', 'procurement.view', 'licensing.manage']]]);
        $purchasing = User::factory()->create(['company_id' => $tenant->id, 'role' => 'staff', 'email_verified_at' => now()]);
        $this->renewApiSession($purchasing);
        $this->getJson('/api/me')->assertOk()->assertJsonMissing(['licensing.manage']);
        $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('can_view_cost', false)->assertJsonPath('financial', null);
        $this->postJson(self::URL, $payload + ['budget' => 200])->assertForbidden();
        $this->postJson(self::URL, $payload + ['assumed_unit_price' => 3])->assertForbidden();
        $this->assertSame(0, DB::table('journal_entries')->count());
    }
}
