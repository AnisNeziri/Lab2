<?php

namespace Tests\Feature;

use App\Models\BusinessEvent;
use App\Models\Company;
use App\Models\IntegrationOperationLog;
use App\Models\IntegrationProvider;
use App\Models\OperationalException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WebhookEndpoint;
use App\Services\BusinessEventService;
use App\Services\ControlTowerService;
use App\Services\EcbFxRateProvider;
use App\Services\IntegrationExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class SupplyChainControlTowerTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_events_are_idempotent_sanitized_and_rollback_with_failed_transaction(): void
    {
        $this->actingAsApiUser('admin');
        $shipment = $this->shipment('EVT-1');
        $events = app(BusinessEventService::class);

        $events->record('shipment.created', $shipment, 'EVT-1', ['mmsi' => '123456789', 'api_key' => 'never-store'], 'shipment:evt-1');
        $events->record('shipment.created', $shipment, 'EVT-1', ['different' => true], 'shipment:evt-1');

        $this->assertDatabaseCount('business_events', 1);
        $this->assertArrayNotHasKey('api_key', BusinessEvent::firstOrFail()->metadata);

        try {
            DB::transaction(function () use ($events, $shipment): void {
                $events->record('shipment.arrived', $shipment, 'EVT-1', [], 'shipment:evt-1:arrived');
                throw new RuntimeException('domain operation failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertDatabaseMissing('business_events', ['idempotency_key' => 'shipment:evt-1:arrived']);
    }

    public function test_control_tower_orders_milestones_exceptions_and_tenant_isolation(): void
    {
        $this->actingAsApiUser('admin');
        $shipment = $this->shipment('TOWER-1');
        ShipmentMilestone::create($this->tenantAttributes([
            'shipment_id' => $shipment->id, 'scope_key' => 'shipment',
            'milestone_type' => 'vessel_departure', 'status' => 'planned',
            'planned_at' => now()->subDay(), 'source' => 'manual',
        ]));

        $first = $this->getJson('/api/control-tower')->assertOk()->json('data.0');
        $this->assertSame('purchase_order', $first['timeline'][0]['type']);
        $this->assertNull($first['timeline'][0]['planned_at']);
        $this->assertContains($first['vessel']['ais_freshness'], ['not_applicable', 'no_signal']);
        $this->assertDatabaseCount('operational_exceptions', 2); // departure + milestone overdue
        $this->getJson('/api/control-tower')->assertOk();
        $this->assertDatabaseCount('operational_exceptions', 2);

        ShipmentMilestone::firstOrFail()->update(['actual_at' => now(), 'status' => 'completed']);
        $this->getJson('/api/control-tower')->assertOk();
        $this->assertSame(0, OperationalException::where('status', 'active')->count());

        $other = Company::factory()->create();
        Shipment::withoutEvents(fn () => Shipment::create([
            'company_id' => $other->id, 'tracking_number' => 'FOREIGN-1',
            'transport_mode' => 'sea', 'status' => 'registered', 'tracking_provider' => 'manual',
        ]));
        $this->getJson('/api/control-tower')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_container_allocation_supports_multiple_orders_without_changing_ordered_quantities(): void
    {
        $this->actingAsApiUser('admin');
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Import Supplier']));
        $warehouse = Warehouse::create($this->tenantAttributes(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true, 'is_default' => true]));
        $orders = collect([1, 2])->map(function (int $number) use ($supplier, $warehouse) {
            $order = PurchaseOrder::create($this->tenantAttributes([
                'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
                'po_number' => "PO-CONT-{$number}", 'status' => 'ordered',
                'total_amount' => 100, 'total_amount_eur' => 100, 'total_paid' => 0,
                'currency' => 'EUR', 'exchange_rate' => 1, 'ordered_at' => now()->toDateString(),
            ]));
            $item = PurchaseOrderItem::create([
                'purchase_order_id' => $order->id, 'description' => "Item {$number}",
                'unit' => 'pcs', 'inventory_unit' => 'pcs', 'conversion_mode' => 'none',
                'conversion_factor' => 1, 'quantity' => 10, 'base_quantity' => 10,
                'received_quantity' => 0, 'received_base_quantity' => 0, 'unit_price' => 10, 'line_total' => 100,
            ]);
            return [$order, $item];
        });
        $shipment = $this->shipment('CONT-1');

        $this->putJson("/api/shipments/{$shipment->id}/logistics", [
            'purchase_order_ids' => $orders->pluck('0.id')->all(),
            'containers' => [[
                'client_key' => 'new-container', 'container_number' => 'MSCU1234567',
                'container_type' => '40HQ', 'capacity_cbm' => 76.3, 'capacity_weight_kg' => 26500,
                'booking_reference' => 'BOOK-1', 'status' => 'loaded',
            ]],
            'items' => $orders->map(fn ($row) => [
                'shipment_container_key' => 'new-container', 'purchase_order_item_id' => $row[1]->id,
                'quantity' => 5, 'planned_quantity' => 5, 'loaded_quantity' => 5,
                'unit_cbm' => 0.25, 'unit_weight_kg' => 8,
            ])->all(),
        ])->assertOk()->assertJsonCount(2, 'purchase_orders')->assertJsonCount(2, 'containers.0.purchase_orders');

        $this->assertSame([10.0, 10.0], $orders->map(fn ($row) => (float) $row[0]->items()->first()->quantity)->all());
        $this->assertDatabaseCount('shipment_purchase_orders', 2);
        $this->assertDatabaseCount('shipment_container_purchase_orders', 2);
    }

    public function test_integrations_log_failures_ecb_is_cached_and_webhooks_are_signed(): void
    {
        $this->actingAsApiUser('admin');
        $provider = IntegrationProvider::create($this->tenantAttributes([
            'category' => 'routing', 'provider_key' => 'failing', 'display_name' => 'Failing Provider',
            'enabled' => true, 'health_state' => 'unknown', 'credentials' => ['token' => 'secret'],
        ]));
        try {
            app(IntegrationExecutionService::class)->run($provider, 'route', 'R-1', fn () => throw new RuntimeException('provider down'));
        } catch (RuntimeException) {
        }
        $this->assertDatabaseHas('integration_operation_logs', ['integration_provider_id' => $provider->id, 'status' => 'failed']);
        $this->getJson('/api/integrations/health')->assertOk()->assertJsonMissing(['token' => 'secret']);

        Http::fake([
            'https://www.ecb.europa.eu/*' => Http::response('<Cube><Cube time="2026-09-05"><Cube currency="USD" rate="1.2000"/></Cube></Cube>'),
            'https://hooks.example.test/*' => Http::response([], 204),
        ]);
        $first = app(EcbFxRateProvider::class)->referenceRate($this->apiCompany->id, 'EUR', 'USD');
        $second = app(EcbFxRateProvider::class)->referenceRate($this->apiCompany->id, 'EUR', 'USD');
        $this->assertSame('1.2000000000', (string) $first->rate);
        $this->assertSame($first->id, $second->id);

        WebhookEndpoint::create($this->tenantAttributes([
            'name' => 'Test hook', 'endpoint_url' => 'https://hooks.example.test/aims',
            'secret' => 'this-is-a-test-signing-secret-123', 'enabled' => true,
            'subscribed_event_types' => ['shipment.created'],
        ]));
        app(BusinessEventService::class)->record('shipment.created', $this->shipment('HOOK-1'), 'HOOK-1', ['safe' => true], 'hook-event-1');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://hooks.example.test/')
            && str_starts_with($request->header('X-AIMS-Signature')[0] ?? '', 'sha256='));
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'delivered', 'attempts' => 1]);
        $this->assertSame(1, IntegrationOperationLog::where('integration_provider_id', $provider->id)->count());
    }

    private function shipment(string $tracking): Shipment
    {
        return Shipment::create($this->tenantAttributes([
            'tracking_number' => $tracking, 'tracking_reference' => $tracking,
            'transport_mode' => 'sea', 'status' => 'registered', 'tracking_provider' => 'manual',
        ]));
    }
}
