<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvancedInventoryOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_box_conversion_adds_and_sells_base_inventory_units(): void
    {
        [$category, $supplier, $warehouse] = $this->setupInventory();
        $product = $this->postJson('/api/products', [
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
            'default_warehouse_id' => $warehouse['id'],
            'name' => 'Door handles',
            'sku' => 'HANDLE-100',
            'quantity' => 0,
            'unit' => 'pcs',
            'min_quantity' => 5,
            'price' => 2,
            'purchase_price' => 1,
            'selling_price' => 2,
            'unit_conversions' => [[
                'code' => 'box',
                'label' => 'Box of 100',
                'conversion_mode' => 'fixed',
                'factor_to_base' => 100,
            ]],
        ])->assertCreated()
            ->assertJsonPath('units.0.factor_to_base', '100.000000')
            ->assertJsonPath('units.0.allow_purchase', true)
            ->assertJsonPath('units.0.allow_sale', true)
            ->assertJsonPath('units.0.is_default_purchase', false)
            ->assertJsonPath('units.0.is_default_sale', false)
            ->json();

        $order = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse['id'],
            'ordered_at' => now()->toDateString(),
            'currency' => 'EUR',
            'status' => 'ordered',
            'items' => [[
                'product_id' => $product['id'], 'description' => $product['name'],
                'unit' => 'box', 'quantity' => 10, 'unit_price' => 100,
            ]],
        ])->assertCreated()->assertJsonPath('items.0.conversion_mode', 'fixed')->assertJsonPath('items.0.base_quantity', '1000.000')->json();

        $this->postJson('/api/purchase-orders/'.$order['id'].'/receive', [
            'warehouse_id' => $warehouse['id'],
            'items' => [['id' => $order['items'][0]['id'], 'accepted_quantity' => 10]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('status', 'received');

        $this->assertSame(1000.0, (float) Product::find($product['id'])->quantity);
        $this->postJson('/api/daily-sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product['id'], 'product_name' => $product['name'],
                'unit' => 'box', 'quantity' => 1, 'unit_price' => 200,
            ]],
        ])->assertCreated()->assertJsonPath('items.0.base_quantity', '100.000');
        $this->assertSame(900.0, (float) Product::find($product['id'])->quantity);
    }

    public function test_new_product_pack_sizes_must_use_a_fixed_quantity(): void
    {
        [$category, $supplier, $warehouse] = $this->setupInventory();
        $this->postJson('/api/products', [
            'category_id' => $category->id, 'supplier_id' => $supplier->id,
            'default_warehouse_id' => $warehouse['id'], 'name' => 'Fabric', 'sku' => 'FABRIC-VAR',
            'quantity' => 0, 'unit' => 'm', 'min_quantity' => 5, 'price' => 4, 'purchase_price' => 2,
            'unit_conversions' => [[
                'code' => 'roll', 'label' => 'Measured roll', 'conversion_mode' => 'variable',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_conversions.0.conversion_mode');

        $this->postJson('/api/products', [
            'category_id' => $category->id, 'supplier_id' => $supplier->id,
            'default_warehouse_id' => $warehouse['id'], 'name' => 'Fixed hardware box', 'sku' => 'HARDWARE-BOX',
            'quantity' => 650, 'unit' => 'pcs', 'min_quantity' => 5, 'price' => 4, 'purchase_price' => 2,
            'unit_conversions' => [[
                'code' => 'box', 'label' => 'Box of 65', 'factor_to_base' => 65,
            ]],
        ])->assertCreated()
            ->assertJsonPath('units.0.conversion_mode', 'fixed')
            ->assertJsonPath('units.0.factor_to_base', '65.000000');
    }

    public function test_only_meter_inventory_quantities_accept_decimals(): void
    {
        [$category, $supplier, $warehouse] = $this->setupInventory();
        $base = [
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
            'default_warehouse_id' => $warehouse['id'],
            'min_quantity' => 1,
            'price' => 2,
        ];

        $this->postJson('/api/products', $base + [
            'name' => 'Fractional kilograms', 'sku' => 'KG-FRACTION',
            'quantity' => 1.5, 'unit' => 'kg',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->postJson('/api/products', $base + [
            'name' => 'Measured fabric', 'sku' => 'M-FRACTION',
            'quantity' => 1.5, 'unit' => 'm',
        ])->assertCreated()->assertJsonPath('quantity', 1.5);
    }

    public function test_warehouse_information_can_be_edited_while_preserving_default_invariants(): void
    {
        [, , $warehouse] = $this->setupInventory();

        $this->putJson('/api/warehouses/'.$warehouse['id'], [
            'name' => 'Prishtina Central Warehouse',
            'code' => 'wh-pri-central',
            'address' => 'Zona Industriale, Prishtinë',
            'length_m' => 120.5,
            'width_m' => 65,
            'height_m' => 9.5,
            'floor_count' => 3,
            'is_active' => true,
            'is_default' => true,
        ])->assertOk()
            ->assertJsonPath('name', 'Prishtina Central Warehouse')
            ->assertJsonPath('code', 'WH-PRI-CENTRAL')
            ->assertJsonPath('address', 'Zona Industriale, Prishtinë')
            ->assertJsonPath('floor_count', 3);

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse['id'],
            'name' => 'Prishtina Central Warehouse',
            'code' => 'WH-PRI-CENTRAL',
            'floor_count' => 3,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->putJson('/api/warehouses/'.$warehouse['id'], ['is_default' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_default');
        $this->putJson('/api/warehouses/'.$warehouse['id'], ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }

    public function test_transfer_moves_warehouse_balances_without_changing_company_total(): void
    {
        [$category, $supplier, $source] = $this->setupInventory();
        $destination = $this->postJson('/api/warehouses', [
            'name' => 'Prishtina Warehouse', 'code' => 'WH-PRI', 'address' => 'Prishtina',
        ])->assertCreated()->json();
        $product = $this->postJson('/api/products', [
            'category_id' => $category->id, 'supplier_id' => $supplier->id,
            'default_warehouse_id' => $source['id'], 'name' => 'Transfer item', 'sku' => 'TRANSFER-1',
            'quantity' => 100, 'unit' => 'pcs', 'min_quantity' => 5, 'price' => 10,
        ])->assertCreated()->json();

        $transfer = $this->postJson('/api/stock-transfers', [
            'source_warehouse_id' => $source['id'], 'destination_warehouse_id' => $destination['id'],
            'items' => [['product_id' => $product['id'], 'quantity' => 40]],
        ])->assertCreated()->json();
        $dispatchKey = (string) Str::uuid();
        $this->postJson('/api/stock-transfers/'.$transfer['id'].'/dispatch', ['idempotency_key' => $dispatchKey])
            ->assertOk()->assertJsonPath('status', 'in_transit');
        $this->assertSame(100.0, (float) Product::find($product['id'])->quantity);
        $this->assertSame(60.0, (float) WarehouseStock::withoutGlobalScopes()->where('warehouse_id', $source['id'])->where('product_id', $product['id'])->value('available_quantity'));

        $receiptKey = (string) Str::uuid();
        $payload = ['idempotency_key' => $receiptKey, 'items' => [[
            'id' => $transfer['items'][0]['id'], 'accepted_quantity' => 35, 'damaged_quantity' => 5,
        ]]];
        $this->postJson('/api/stock-transfers/'.$transfer['id'].'/receive', $payload)->assertOk()->assertJsonPath('status', 'received');
        $this->postJson('/api/stock-transfers/'.$transfer['id'].'/receive', $payload)->assertOk()->assertJsonPath('status', 'received');
        $destinationBalance = WarehouseStock::withoutGlobalScopes()->where('warehouse_id', $destination['id'])->where('product_id', $product['id'])->firstOrFail();
        $this->assertSame(35.0, (float) $destinationBalance->available_quantity);
        $this->assertSame(5.0, (float) $destinationBalance->damaged_quantity);
        $this->assertSame(100.0, (float) Product::find($product['id'])->quantity);
        $this->assertDatabaseCount('stock_transfer_receipts', 1);
    }

    public function test_transfer_rejects_foreign_warehouses_wrong_locations_and_decimal_piece_quantities(): void
    {
        [$category, $supplier, $source] = $this->setupInventory();
        $destination = $this->postJson('/api/warehouses', [
            'name' => 'Secondary Warehouse', 'code' => 'WH-SECONDARY',
        ])->assertCreated()->json();
        $destinationZone = $this->postJson('/api/warehouse-locations', [
            'warehouse_id' => $destination['id'], 'type' => 'zone', 'code' => 'DZ', 'name' => 'Destination Zone',
        ])->assertCreated()->json();
        $product = $this->postJson('/api/products', [
            'category_id' => $category->id, 'supplier_id' => $supplier->id,
            'default_warehouse_id' => $source['id'], 'name' => 'Secure transfer item',
            'quantity' => 10, 'unit' => 'pcs', 'min_quantity' => 1, 'price' => 5,
        ])->assertCreated()->json();

        $foreignCompany = Company::factory()->create();
        $foreignWarehouse = Warehouse::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id, 'name' => 'Foreign Warehouse',
            'code' => 'FOREIGN', 'is_active' => true, 'is_default' => true,
        ]);

        $this->postJson('/api/stock-transfers', [
            'source_warehouse_id' => $foreignWarehouse->id,
            'destination_warehouse_id' => $destination['id'],
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('source_warehouse_id');

        $this->postJson('/api/stock-transfers', [
            'source_warehouse_id' => $source['id'],
            'destination_warehouse_id' => $destination['id'],
            'source_location_id' => $destinationZone['id'],
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('source_location_id');

        $this->postJson('/api/stock-transfers', [
            'source_warehouse_id' => $source['id'],
            'destination_warehouse_id' => $destination['id'],
            'items' => [['product_id' => $product['id'], 'quantity' => 1.5]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->getJson('/api/warehouses')->assertOk()
            ->assertJsonPath('0.available_products_count', 1);
    }

    public function test_goods_receipt_separates_accepted_damaged_and_rejected_stock(): void
    {
        [$category, $supplier, $warehouse] = $this->setupInventory();
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id, 'supplier_id' => $supplier->id,
            'default_warehouse_id' => $warehouse['id'], 'name' => 'Received item', 'sku' => 'RECEIVE-STATES',
            'quantity' => 0, 'unit' => 'pcs', 'min_quantity' => 0, 'price' => 5,
        ]));
        $order = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse['id'],
            'ordered_at' => now()->toDateString(), 'currency' => 'EUR', 'status' => 'ordered',
            'items' => [['product_id' => $product->id, 'description' => $product->name, 'unit' => 'pcs', 'quantity' => 10, 'unit_price' => 3]],
        ])->assertCreated()->json();
        $this->postJson('/api/purchase-orders/'.$order['id'].'/receive', [
            'items' => [[
                'id' => $order['items'][0]['id'], 'accepted_quantity' => 6,
                'damaged_quantity' => 1, 'rejected_quantity' => 3,
            ]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('items.0.remaining_quantity', 3);

        $balance = WarehouseStock::withoutGlobalScopes()->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(6.0, (float) $balance->available_quantity);
        $this->assertSame(1.0, (float) $balance->damaged_quantity);
        $this->assertSame(7.0, (float) $product->fresh()->quantity);
        $this->assertDatabaseHas('goods_receipt_items', ['accepted_quantity' => 6, 'damaged_quantity' => 1, 'rejected_quantity' => 3]);
    }

    public function test_location_hierarchy_requires_zone_rack_shelf_bin_order(): void
    {
        [, , $warehouse] = $this->setupInventory();
        $zone = $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse['id'], 'type' => 'zone', 'code' => 'A', 'name' => 'Zone A'])->assertCreated()->json();
        $rack = $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse['id'], 'parent_id' => $zone['id'], 'type' => 'rack', 'code' => 'R1', 'name' => 'Rack 1'])->assertCreated()->assertJsonPath('path', 'A/R1')->json();
        $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse['id'], 'parent_id' => $zone['id'], 'type' => 'bin', 'code' => 'B1', 'name' => 'Invalid bin'])->assertUnprocessable();
        $shelf = $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse['id'], 'parent_id' => $rack['id'], 'type' => 'shelf', 'code' => 'S1', 'name' => 'Shelf 1'])->assertCreated()->json();
        $this->postJson('/api/warehouse-locations', ['warehouse_id' => $warehouse['id'], 'parent_id' => $shelf['id'], 'type' => 'bin', 'code' => 'B1', 'name' => 'Bin 1'])->assertCreated()->assertJsonPath('path', 'A/R1/S1/B1');
    }

    public function test_operations_locations_and_visual_layout_stay_synchronized(): void
    {
        [$category, $supplier, $warehouse] = $this->setupInventory();

        $zone = $this->postJson('/api/warehouse-locations', [
            'warehouse_id' => $warehouse['id'], 'type' => 'zone', 'code' => 'UPPER',
            'name' => 'Upper zone', 'floor_level' => 2,
        ])->assertCreated()
            ->assertJsonPath('path', 'L2-UPPER')
            ->assertJsonPath('floor_level', 2)
            ->assertJsonPath('section.floor_level', 2)
            ->json();

        $rack = $this->postJson('/api/warehouse-locations', [
            'warehouse_id' => $warehouse['id'], 'parent_id' => $zone['id'], 'type' => 'rack',
            'code' => 'R1', 'name' => 'Upper rack',
        ])->assertCreated()
            ->assertJsonPath('path', 'L2-UPPER/R1')
            ->assertJsonPath('floor_level', 2)
            ->json();

        $product = $this->postJson('/api/products', [
            'category_id' => $category->id, 'supplier_id' => $supplier->id,
            'default_warehouse_id' => $warehouse['id'], 'name' => 'Located stock',
            'sku' => 'LOCATED-1', 'quantity' => 25, 'unit' => 'pcs', 'min_quantity' => 2, 'price' => 8,
        ])->assertCreated()->json();
        WarehouseStock::withoutGlobalScopes()
            ->where('warehouse_id', $warehouse['id'])
            ->where('product_id', $product['id'])
            ->update(['location_id' => $zone['id']]);
        $listedProduct = collect($this->getJson('/api/products?per_page=50')->assertOk()->json('data'))
            ->firstWhere('id', $product['id']);
        $this->assertSame('L2-UPPER', $listedProduct['warehouse_stock'][0]['location']['path']);
        $this->assertSame(2, $listedProduct['warehouse_stock'][0]['location']['floor_level']);

        $layout = $this->getJson('/api/warehouse/layout?warehouse_id='.$warehouse['id'])
            ->assertOk()
            ->assertJsonPath('warehouse.floor_count', 2)
            ->json('sections');
        $zoneSection = collect($layout)->firstWhere('warehouse_location_id', $zone['id']);
        $rackSection = collect($layout)->firstWhere('warehouse_location_id', $rack['id']);
        $this->assertSame('L2-UPPER', $zoneSection['display_code']);
        $this->assertSame('L2-UPPER/R1', $rackSection['display_code']);
        $this->assertNotSame([$zoneSection['pos_x'], $zoneSection['pos_z']], [$rackSection['pos_x'], $rackSection['pos_z']]);

        $layoutSection = $this->postJson('/api/warehouse/sections', [
            'warehouse_id' => $warehouse['id'], 'code' => 'COLD', 'name' => 'Cold storage',
            'floor_level' => 3, 'width' => 5, 'depth' => 5,
        ])->assertCreated()
            ->assertJsonPath('location.path', 'L3-COLD')
            ->json();
        $locationId = $layoutSection['warehouse_location_id'];
        $this->getJson('/api/warehouse-locations?warehouse_id='.$warehouse['id'])
            ->assertOk()
            ->assertJsonFragment(['id' => $locationId, 'path' => 'L3-COLD', 'floor_level' => 3]);

        $this->putJson('/api/warehouse-locations/'.$locationId, [
            'code' => 'CHILL', 'name' => 'Chilled storage', 'floor_level' => 3,
        ])->assertOk()->assertJsonPath('section.name', 'Chilled storage');
        $this->assertDatabaseHas('warehouse_sections', [
            'id' => $layoutSection['id'], 'warehouse_location_id' => $locationId,
            'code' => 'CHILL', 'name' => 'Chilled storage', 'floor_level' => 3,
        ]);

        $this->putJson('/api/warehouse/sections/'.$layoutSection['id'], [
            'code' => 'COOL', 'name' => 'Cool storage', 'floor_level' => 3,
        ])->assertOk()->assertJsonPath('location.path', 'L3-COOL');
        $this->assertDatabaseHas('warehouse_locations', [
            'id' => $locationId, 'code' => 'COOL', 'name' => 'Cool storage', 'path' => 'L3-COOL',
        ]);

        $this->deleteJson('/api/warehouse/sections/'.$layoutSection['id'])->assertNoContent();
        $this->assertDatabaseMissing('warehouse_sections', ['id' => $layoutSection['id']]);
        $this->assertDatabaseMissing('warehouse_locations', ['id' => $locationId]);
    }

    public function test_shipment_logistics_stores_containers_and_secure_documents(): void
    {
        [, , $warehouse] = $this->setupInventory();
        $shipment = Shipment::create($this->tenantAttributes([
            'tracking_number' => 'MSCU1234567', 'transport_mode' => 'sea', 'status' => 'in_transit',
            'warehouse_id' => $warehouse['id'], 'origin_port' => 'Shanghai', 'destination_port' => 'Durres',
            'origin_lat' => 31.2304, 'origin_lng' => 121.4737,
            'destination_lat' => 41.3167, 'destination_lng' => 19.45,
            'current_lat' => 31.2304, 'current_lng' => 121.4737,
            'tracking_mode' => 'live', 'tracking_provider' => 'manual',
        ]));

        $this->putJson('/api/shipments/'.$shipment->id.'/logistics', [
            'warehouse_id' => $warehouse['id'], 'bill_of_lading' => 'BL-2026-100',
            'commercial_invoice_number' => 'CI-100', 'incoterm' => 'CIF',
            'origin_port' => 'Shanghai', 'transshipment_port' => 'Piraeus', 'destination_port' => 'Durres',
            'containers' => [[
                'container_number' => 'MSCU7654321', 'seal_number' => 'SEAL-9',
                'container_type' => '40HC', 'gross_weight_kg' => 18500,
            ]],
        ])->assertOk()
            ->assertJsonPath('bill_of_lading', 'BL-2026-100')
            ->assertJsonPath('containers.0.container_number', 'MSCU7654321');

        $upload = $this->post('/api/shipments/'.$shipment->id.'/documents', [
            'document_type' => 'packing_list',
            'document' => UploadedFile::fake()->createWithContent('packing-list.pdf', '%PDF-1.4 AIMS'),
        ], ['Accept' => 'application/json'])->assertCreated()->json();

        $this->assertDatabaseHas('shipment_documents', [
            'id' => $upload['id'], 'shipment_id' => $shipment->id,
            'document_type' => 'packing_list', 'sha256' => hash('sha256', '%PDF-1.4 AIMS'),
        ]);
        $this->get('/api/shipments/'.$shipment->id.'/documents/'.$upload['id'])
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function setupInventory(): array
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Inventory']));
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Supplier']));
        $warehouse = $this->postJson('/api/warehouses', ['name' => 'Main Warehouse', 'code' => 'WH-MAIN', 'is_default' => true])->assertCreated()->json();

        return [$category, $supplier, $warehouse];
    }
}
