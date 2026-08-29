<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tracking\AisStreamMessageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsAndShipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_default_preferences(): void
    {
        $this->actingAsApiUser('admin');

        $response = $this->getJson('/api/settings/preferences');

        $response
            ->assertOk()
            ->assertJsonPath('preferences.theme', 'light')
            ->assertJsonPath('preferences.language', 'en')
            ->assertJsonPath('preferences.enable_3d_map', false);
    }

    public function test_user_can_update_preferences(): void
    {
        $this->actingAsApiUser('staff');

        $response = $this->putJson('/api/settings/preferences', [
            'theme' => 'dark',
            'language' => 'sq',
            'enable_3d_map' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('preferences.theme', 'dark')
            ->assertJsonPath('preferences.language', 'sq')
            ->assertJsonPath('preferences.enable_3d_map', true);
    }

    public function test_local_first_desktop_mode_can_enable_real_ais_independently(): void
    {
        $this->actingAsApiUser('admin');
        config()->set('system.operation_mode', 'offline');
        config()->set('tracking.external_enabled', true);
        config()->set('tracking.vessel_provider', 'aisstream');
        config()->set('tracking.aisstream.api_key', 'protected-local-key');
        config()->set('tracking.vessel_lookup.api_key', null);
        Cache::put('tracking.aisstream.connection', [
            'state' => 'connected',
            'tracked_mmsis_count' => 0,
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->getJson('/api/system/mode')
            ->assertOk()
            ->assertJsonPath('mode', 'offline')
            ->assertJsonPath('tracking.external_enabled', true)
            ->assertJsonPath('tracking.vessel.provider', 'aisstream')
            ->assertJsonPath('tracking.vessel.configured', true)
            ->assertJsonPath('tracking.vessel.enabled', true)
            ->assertJsonPath('tracking.vessel.status', 'connected')
            ->assertJsonPath('tracking.vessel.connection_available', true)
            ->assertJsonPath('tracking.vessel.mmsi_lookup_enabled', true)
            ->assertJsonPath('tracking.vessel.imo_lookup_enabled', false)
            ->assertJsonPath('tracking.parcel.enabled', false)
            ->assertJsonPath('tracking.air.enabled', false);

        $lookup = $this->postJson('/api/shipments/vessels/lookup', [
            'identifier' => '353136000',
        ])->assertOk()
            ->assertJsonPath('identifier_type', 'mmsi')
            ->assertJsonPath('vessel.mmsi', '353136000')
            ->json();

        $this->postJson('/api/shipments/ais', [
            'lookup_token' => $lookup['lookup_token'],
        ])->assertCreated()
            ->assertJsonPath('tracking_provider', 'aisstream')
            ->assertJsonPath('tracking_mode', 'live_ais')
            ->assertJsonPath('mmsi', '353136000');

        $this->getJson('/api/tracking/vessels')
            ->assertOk()
            ->assertJsonCount(1, 'vessels')
            ->assertJsonPath('vessels.0.name', 'MMSI 353136000')
            ->assertJsonPath('vessels.0.has_live_position', false)
            ->assertJsonPath('vessels.0.location_label', 'Waiting for the first verified AISStream position.')
            ->assertJsonPath('metrics.total_vessels', 1);
    }

    public function test_demo_shipment_providers_cannot_be_activated(): void
    {
        $this->actingAsApiUser('admin');
        config()->set('tracking.shipment_provider', 'demo');
        config()->set('tracking.air_cargo_provider', 'demo');

        $this->postJson('/api/shipments/track', [
            'tracking_number' => 'AIMSSEA12345678',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Shipment tracking provider [demo] is not registered.');

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_demo_tracking_is_disabled_and_verified_ais_shipment_can_be_created(): void
    {
        $this->actingAsApiUser('admin');
        config()->set('tracking.shipment_provider', 'disabled');
        config()->set('tracking.air_cargo_provider', 'disabled');
        config()->set('tracking.vessel_provider', 'aisstream');
        config()->set('tracking.aisstream.api_key', 'test-key');

        $this->postJson('/api/shipments/validate', [
            'tracking_number' => 'AIMSSEA12345678',
            'transport_mode' => 'sea',
        ])->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('available', false)
            ->assertJsonPath('provider', null);

        $this->postJson('/api/shipments/track', [
            'tracking_number' => 'AIMSSEA12345678',
        ])->assertUnprocessable();

        $lookup = $this->postJson('/api/shipments/vessels/lookup', [
            'identifier' => '123456789',
        ])->assertOk()
            ->assertJsonPath('identifier_type', 'mmsi')
            ->assertJsonPath('vessel.details_status', 'pending')
            ->json();

        $this->postJson('/api/shipments/ais', [
            'lookup_token' => $lookup['lookup_token'],
        ])->assertCreated()
            ->assertJsonPath('tracking_provider', 'aisstream')
            ->assertJsonPath('tracking_mode', 'live_ais')
            ->assertJsonPath('mmsi', '123456789')
            ->assertJsonPath('origin_port', null)
            ->assertJsonPath('destination_port', null);

        $this->assertDatabaseHas('notifications', ['type' => 'vessel_tracking_started']);
    }

    public function test_imo_lookup_resolves_verified_vessel_links_purchase_order_and_maps_position(): void
    {
        $this->actingAsApiUser('admin');
        $user = User::where('company_id', $this->apiCompany->id)->firstOrFail();
        config()->set('tracking.vessel_provider', 'aisstream');
        config()->set('tracking.aisstream.api_key', 'ais-key');
        config()->set('tracking.vessel_lookup.provider', 'vesselapi');
        config()->set('tracking.vessel_lookup.api_key', 'lookup-key');

        Http::fake([
            'https://api.vesselapi.com/v1/vessel/9811000/position*' => Http::response(['vesselPosition' => [
                'mmsi' => 353136000, 'imo' => 9811000, 'vessel_name' => 'EVER GIVEN',
                'latitude' => 1.2644, 'longitude' => 103.8215, 'sog' => 14.1,
                'cog' => 231.5, 'timestamp' => '2026-08-26T10:00:00Z',
            ]]),
            'https://api.vesselapi.com/v1/vessel/9811000/eta*' => Http::response(['vesselEta' => [
                'mmsi' => 353136000, 'imo' => 9811000, 'destination' => 'SINGAPORE',
                'eta' => '2026-08-30T08:00:00Z',
            ]]),
            'https://api.vesselapi.com/v1/vessel/9811000*' => Http::response(['vessel' => [
                'mmsi' => 353136000, 'imo' => 9811000, 'name' => 'EVER GIVEN',
                'call_sign' => 'H3RC', 'country' => 'Panama', 'vessel_type' => 'Cargo A', 'year_built' => 2018,
            ]]),
        ]);

        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Ocean Supplier']));
        $warehouse = Warehouse::create($this->tenantAttributes(['name' => 'Main', 'code' => 'MAIN', 'is_active' => true, 'is_default' => true]));
        $order = PurchaseOrder::create($this->tenantAttributes([
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'po_number' => 'PO-2026-IMO', 'status' => 'ordered', 'total_amount' => 100,
            'total_amount_eur' => 100, 'total_paid' => 0, 'currency' => 'EUR',
            'exchange_rate' => 1, 'ordered_at' => now()->toDateString(), 'created_by' => $user->id,
        ]));

        $lookup = $this->postJson('/api/shipments/vessels/lookup', ['identifier' => 'IMO 9811000'])
            ->assertOk()
            ->assertJsonPath('identifier_type', 'imo')
            ->assertJsonPath('vessel.mmsi', '353136000')
            ->assertJsonPath('vessel.details_status', 'verified')
            ->json();

        $shipment = $this->postJson('/api/shipments/ais', [
            'lookup_token' => $lookup['lookup_token'],
            'purchase_order_id' => $order->id,
        ])->assertCreated()
            ->assertJsonPath('purchase_order_id', $order->id)
            ->assertJsonPath('vessel_name', 'EVER GIVEN')
            ->assertJsonPath('imo', '9811000')
            ->assertJsonPath('current_lat', 1.2644)
            ->assertJsonPath('destination_port', 'Singapore')
            ->json();

        $this->getJson('/api/tracking/vessels?search=PO-2026-IMO')->assertOk()
            ->assertJsonPath('vessels.0.id', (string) $shipment['id'])
            ->assertJsonPath('vessels.0.purchase_order_number', 'PO-2026-IMO')
            ->assertJsonPath('metrics.linked_purchase_orders', 1);
        $this->assertDatabaseHas('notifications', ['type' => 'vessel_tracking_started']);
        $this->assertDatabaseHas('notifications', ['type' => 'vessel_position_available']);
    }

    public function test_imo_without_lookup_provider_is_rejected_truthfully_and_ais_messages_enrich_mmsi_tracking(): void
    {
        $this->actingAsApiUser('admin');
        config()->set('tracking.vessel_provider', 'aisstream');
        config()->set('tracking.aisstream.api_key', 'ais-key');
        config()->set('tracking.vessel_lookup.api_key', null);

        $this->postJson('/api/shipments/vessels/lookup', ['identifier' => 'IMO 9811000'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'IMO lookup needs a VesselAPI key because AISStream subscriptions accept MMSI only. Configure VESSELAPI_API_KEY, or search using the vessel MMSI.');

        $lookup = $this->postJson('/api/shipments/vessels/lookup', ['identifier' => '353136000'])->assertOk()->json();
        $shipment = $this->postJson('/api/shipments/ais', ['lookup_token' => $lookup['lookup_token']])->assertCreated()->json();
        $processor = app(AisStreamMessageProcessor::class);
        $processor->process([
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => 353136000, 'ShipName' => 'EVER GIVEN'],
            'Message' => ['ShipStaticData' => [
                'UserID' => 353136000, 'ImoNumber' => 9811000, 'CallSign' => 'H3RC',
                'Type' => 70, 'Destination' => 'ALDRZ',
            ]],
        ]);
        $position = [
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => 353136000, 'ShipName' => 'EVER GIVEN', 'Latitude' => 1.26, 'Longitude' => 103.82],
            'Message' => ['PositionReport' => ['UserID' => 353136000, 'Sog' => 12.4, 'Cog' => 86.7]],
        ];
        $processor->process($position);
        $processor->process($position);

        $fresh = Shipment::findOrFail($shipment['id']);
        $this->assertSame('9811000', $fresh->imo);
        $this->assertSame('H3RC', $fresh->call_sign);
        $this->assertSame('EVER GIVEN', $fresh->vessel_name);
        $this->assertSame('Durrës, Albania', $fresh->destination_port);
        $this->assertSame(41.3167, $fresh->destination_lat);
        $this->assertSame(19.45, $fresh->destination_lng);
        $this->assertSame(1.26, $fresh->current_lat);
        $this->assertSame(1, DB::table('notifications')->where('type', 'vessel_position_available')->count());
        $this->assertDatabaseHas('notifications', ['type' => 'vessel_position_available']);
    }

    public function test_imo_lookup_reports_a_temporary_provider_outage_separately_from_missing_configuration(): void
    {
        $this->actingAsApiUser('admin');
        config()->set('tracking.vessel_provider', 'aisstream');
        config()->set('tracking.aisstream.api_key', 'ais-key');
        config()->set('tracking.vessel_lookup.provider', 'vesselapi');
        config()->set('tracking.vessel_lookup.api_key', 'lookup-key');
        Http::fake(['*' => Http::failedConnection('provider offline')]);

        $this->postJson('/api/shipments/vessels/lookup', ['identifier' => 'IMO 9811000'])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'The IMO lookup provider is temporarily unavailable. Please try again, or search using the vessel MMSI to start AIS tracking immediately.'
            );
    }

    public function test_global_vessel_map_returns_fleet(): void
    {
        $this->actingAsApiUser('admin');

        $this->getJson('/api/tracking/vessels')
            ->assertOk()
            ->assertJsonStructure(['vessels', 'metrics' => ['total_vessels', 'active_vessels']]);

        $this->getJson('/api/tracking/vessels?origin=Shanghai, China&destination=Durrës, Albania')
            ->assertOk();
    }

    public function test_shipment_viewers_cannot_change_tracking_records(): void
    {
        $this->actingAsApiUser('staff');

        $this->getJson('/api/shipments')->assertOk();
        $this->getJson('/api/tracking/vessels')->assertOk();
        $this->postJson('/api/shipments/vessels/lookup', [
            'identifier' => '353136000',
        ])->assertForbidden();
        $this->postJson('/api/shipments/track', [
            'tracking_number' => 'AIMSSEA12345678',
        ])->assertForbidden();
    }

    public function test_shipments_are_scoped_to_company(): void
    {
        $this->actingAsApiUser('admin');

        Shipment::create($this->tenantAttributes([
            'origin_port' => 'A',
            'origin_lat' => 1,
            'origin_lng' => 1,
            'destination_port' => 'B',
            'destination_lat' => 2,
            'destination_lng' => 2,
            'status' => 'registered',
            'tracking_mode' => 'live_ais',
            'tracking_provider' => 'aisstream',
            'mmsi' => '353136000',
        ]));

        Shipment::create($this->tenantAttributes([
            'status' => 'registered',
            'tracking_mode' => 'manual',
            'tracking_provider' => 'manual',
        ]));

        $otherCompany = Company::factory()->create();
        Shipment::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'origin_port' => 'X',
            'origin_lat' => 3,
            'origin_lng' => 3,
            'destination_port' => 'Y',
            'destination_lat' => 4,
            'destination_lng' => 4,
            'status' => 'registered',
            'tracking_mode' => 'live_ais',
            'tracking_provider' => 'aisstream',
            'mmsi' => '367311864',
        ]);

        $response = $this->getJson('/api/shipments')->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_ais_processor_merges_message_ordering_and_rejects_unavailable_navigation_values(): void
    {
        $this->actingAsApiUser('admin');
        config()->set('tracking.external_enabled', true);
        $shipment = Shipment::create($this->tenantAttributes([
            'tracking_number' => 'MMSI-353136000',
            'transport_mode' => 'sea',
            'status' => 'registered',
            'tracking_mode' => 'live_ais',
            'tracking_provider' => 'aisstream',
            'mmsi' => '353136000',
        ]));
        $processor = app(AisStreamMessageProcessor::class);

        $processor->process([
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => 353136000, 'ShipName' => 'POSITION FIRST'],
            'Message' => ['PositionReport' => [
                'UserID' => 353136000, 'Valid' => true,
                'Latitude' => 1.26, 'Longitude' => 103.82,
                'Sog' => 102.3, 'Cog' => 360, 'TrueHeading' => 511,
                'NavigationalStatus' => 5,
            ]],
        ]);

        $afterPosition = $shipment->fresh();
        $this->assertSame('POSITION FIRST', $afterPosition->vessel_name);
        $this->assertSame(1.26, $afterPosition->current_lat);
        $this->assertNull($afterPosition->speed_knots);
        $this->assertNull($afterPosition->course);
        $this->assertNull($afterPosition->heading);
        $this->assertSame(5, $afterPosition->navigation_status);

        $processor->process([
            'MessageType' => 'StaticDataReport',
            'MetaData' => ['MMSI' => 353136000],
            'Message' => ['StaticDataReport' => [
                'UserID' => 353136000, 'Valid' => true,
                'ReportA' => ['Valid' => true, 'Name' => 'STATIC NAME'],
                'ReportB' => ['Valid' => false, 'CallSign' => 'BAD-CALL', 'ShipType' => 70],
            ]],
        ]);
        $this->assertSame('STATIC NAME', $shipment->fresh()->vessel_name);
        $this->assertNull($shipment->fresh()->call_sign);

        $processor->process([
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => 353136000],
            'Message' => ['ShipStaticData' => [
                'UserID' => 353136000, 'Valid' => true,
                'ImoNumber' => 9811000, 'CallSign' => 'H3RC', 'Type' => 70,
                'Dimension' => ['A' => 200, 'B' => 100, 'C' => 20, 'D' => 20],
                'MaximumStaticDraught' => 13.2,
                'Destination' => 'ALDRZ',
                'Eta' => ['Month' => 8, 'Day' => 30, 'Hour' => 8, 'Minute' => 15],
            ]],
        ]);

        $processor->process([
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => 353136000],
            'Message' => ['PositionReport' => [
                'UserID' => 353136000, 'Valid' => true,
                'Latitude' => 2.5, 'Longitude' => 104.5,
                'Sog' => 9.5, 'Cog' => 123.4, 'TrueHeading' => 124,
                'NavigationalStatus' => 0,
            ]],
        ]);
        $processor->process([
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => 353136000],
            'Message' => ['PositionReport' => [
                'UserID' => 353136000, 'Valid' => true,
                'Latitude' => 2.6, 'Longitude' => 104.6,
            ]],
        ]);
        $processor->process([
            'MessageType' => 'PositionReport',
            'MetaData' => ['MMSI' => 353136000, 'Latitude' => 50, 'Longitude' => 20],
            'Message' => ['PositionReport' => [
                'UserID' => 353136000, 'Valid' => false,
                'Latitude' => 50, 'Longitude' => 20, 'Sog' => 20,
            ]],
        ]);

        $fresh = $shipment->fresh();
        $this->assertSame('9811000', $fresh->imo);
        $this->assertSame('H3RC', $fresh->call_sign);
        $this->assertSame('AIS type 70', $fresh->vessel_type);
        $this->assertSame('Durrës, Albania', $fresh->destination_port);
        $this->assertSame(2.6, $fresh->current_lat);
        $this->assertSame(104.6, $fresh->current_lng);
        $this->assertSame(9.5, $fresh->speed_knots);
        $this->assertSame(123.4, $fresh->course);
        $this->assertSame(124.0, $fresh->heading);
        $this->assertSame(0, $fresh->navigation_status);
        $this->assertSame(300, data_get($fresh->vessel_details, 'aisstream.static.dimensions.length_m'));
        $this->assertSame(40, data_get($fresh->vessel_details, 'aisstream.static.dimensions.beam_m'));
        $this->assertSame(13.2, data_get($fresh->vessel_details, 'aisstream.voyage.maximum_static_draught_m'));
    }

    public function test_long_range_messages_and_multiple_companies_update_only_ais_records(): void
    {
        $this->actingAsApiUser('admin');
        $otherCompany = Company::factory()->create();
        $attributes = [
            'tracking_number' => 'MMSI-367311864',
            'transport_mode' => 'sea',
            'status' => 'registered',
            'tracking_mode' => 'live_ais',
            'tracking_provider' => 'aisstream',
            'mmsi' => '367311864',
        ];
        $first = Shipment::create($this->tenantAttributes($attributes));
        $second = Shipment::withoutGlobalScopes()->create($attributes + ['company_id' => $otherCompany->id]);
        $manual = Shipment::create($this->tenantAttributes([
            ...$attributes,
            'tracking_number' => 'MANUAL-367311864',
            'tracking_mode' => 'manual',
            'tracking_provider' => 'manual',
        ]));

        $updated = app(AisStreamMessageProcessor::class)->process([
            'MessageType' => 'LongRangeAisBroadcastMessage',
            'MetaData' => ['MMSI' => 367311864, 'ShipName' => 'LONG RANGE'],
            'Message' => ['LongRangeAisBroadcastMessage' => [
                'UserID' => 367311864, 'Valid' => true,
                'Latitude' => 35.1, 'Longitude' => 18.2,
                'Sog' => 14.2, 'Cog' => 281.4, 'NavigationalStatus' => 8,
                'PositionLatency' => true,
            ]],
        ]);

        $this->assertSame(2, $updated);
        foreach ([$first->fresh(), $second->fresh()] as $tracked) {
            $this->assertSame(35.1, $tracked->current_lat);
            $this->assertSame(14.2, $tracked->speed_knots);
            $this->assertSame(8, $tracked->navigation_status);
        }
        $this->assertNull($manual->fresh()->current_lat);
        $this->assertSame('manual', $manual->fresh()->tracking_provider);
    }

    public function test_saved_positions_remain_visible_with_truthful_freshness_when_live_tracking_is_offline(): void
    {
        $this->actingAsApiUser('admin');
        config()->set('tracking.external_enabled', false);
        Shipment::create($this->tenantAttributes([
            'tracking_number' => 'RECENT-AIS',
            'transport_mode' => 'sea',
            'status' => 'in_transit',
            'tracking_mode' => 'live_ais',
            'tracking_provider' => 'aisstream',
            'mmsi' => '353136000',
            'current_lat' => 40.1,
            'current_lng' => 19.1,
            'position_updated_at' => now()->subMinutes(2),
        ]));
        Shipment::create($this->tenantAttributes([
            'tracking_number' => 'STALE-AIS',
            'transport_mode' => 'sea',
            'status' => 'in_transit',
            'tracking_mode' => 'live_ais',
            'tracking_provider' => 'aisstream',
            'mmsi' => '367311864',
            'current_lat' => 35.1,
            'current_lng' => 18.2,
            'position_updated_at' => now()->subDays(2),
        ]));
        Shipment::create($this->tenantAttributes([
            'tracking_number' => 'WAITING-AIS',
            'transport_mode' => 'sea',
            'status' => 'registered',
            'tracking_mode' => 'live_ais',
            'tracking_provider' => 'aisstream',
            'mmsi' => '123456789',
        ]));

        $vessels = collect($this->getJson('/api/tracking/vessels')->assertOk()->json('vessels'));
        $this->assertCount(3, $vessels);
        $this->assertSame('live', $vessels->firstWhere('mmsi', '353136000')['position_state']);
        $this->assertSame('outside_coverage', $vessels->firstWhere('mmsi', '367311864')['position_state']);
        $this->assertSame('waiting_for_data', $vessels->firstWhere('mmsi', '123456789')['position_state']);
        $this->assertFalse($vessels->firstWhere('mmsi', '367311864')['has_live_position']);
        $this->assertTrue($vessels->firstWhere('mmsi', '367311864')['has_position']);
    }

    public function test_saved_ais_snapshot_is_returned_without_waiting_for_optional_lookup_provider(): void
    {
        $this->actingAsApiUser('admin');
        config()->set('tracking.external_enabled', true);
        config()->set('tracking.vessel_lookup.provider', 'vesselapi');
        config()->set('tracking.vessel_lookup.api_key', 'optional-key');
        Shipment::create($this->tenantAttributes([
            'tracking_number' => 'KNOWN-AIS',
            'transport_mode' => 'sea',
            'status' => 'in_transit',
            'tracking_mode' => 'live_ais',
            'tracking_provider' => 'aisstream',
            'mmsi' => '353136000',
            'vessel_name' => 'SAVED VESSEL',
            'current_lat' => 40.1,
            'current_lng' => 19.1,
            'position_updated_at' => now()->subMinute(),
        ]));
        Http::fake();

        $this->postJson('/api/shipments/vessels/lookup', ['identifier' => '353136000'])
            ->assertOk()
            ->assertJsonPath('vessel.name', 'SAVED VESSEL')
            ->assertJsonPath('vessel.current_lat', 40.1);
        Http::assertNothingSent();
    }
}
