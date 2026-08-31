<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseStock;
use App\Support\BarcodeIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileWarehouseTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_uses_normalized_primary_and_active_alternative_barcodes_without_changing_sku_name_or_tenant_scope(): void
    {
        $this->actingAsApiUser('admin');
        $warehouse = $this->warehouse('Lookup warehouse', 'LOOKUP-WH', true);
        $primary = $this->product($warehouse, 'Mobile Needle Driver', 'MOBILE-SKU', [
            'barcode' => 'Primary   Case',
            'barcode_normalized' => BarcodeIdentity::normalize('Primary   Case'),
        ]);
        $alternative = $this->product($warehouse, 'Alternative barcode item', 'ALT-SKU');
        ProductBarcode::create($this->tenantAttributes([
            'product_id' => $alternative->id,
            'barcode' => 'Carton   Scan',
            'barcode_normalized' => BarcodeIdentity::normalize('Carton   Scan'),
            'is_active' => true,
        ]));
        $inactive = $this->product($warehouse, 'Inactive barcode item', 'INACTIVE-SKU');
        ProductBarcode::create($this->tenantAttributes([
            'product_id' => $inactive->id,
            'barcode' => 'Inactive Scan',
            'barcode_normalized' => BarcodeIdentity::normalize('Inactive Scan'),
            'is_active' => false,
        ]));

        $foreignCompany = Company::factory()->create();
        $foreignProduct = Product::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id,
            'name' => 'Foreign barcode item',
            'sku' => 'FOREIGN-MOBILE-SKU',
            'quantity' => 0,
            'unit' => 'pcs',
            'price' => 1,
        ]);
        ProductBarcode::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id,
            'product_id' => $foreignProduct->id,
            'barcode' => 'Foreign Scan',
            'barcode_normalized' => BarcodeIdentity::normalize('Foreign Scan'),
            'is_active' => true,
        ]);

        $this->lookup('  PRIMARY case  ')
            ->assertOk()
            ->assertJsonPath('product.id', $primary->id);
        $this->lookup("CARTON\tSCAN")
            ->assertOk()
            ->assertJsonPath('product.id', $alternative->id);
        $this->lookup('mobile-sku')
            ->assertOk()
            ->assertJsonPath('product.id', $primary->id);
        $this->lookup('Needle Driver')
            ->assertOk()
            ->assertJsonPath('product', null)
            ->assertJsonPath('matches.0.id', $primary->id);
        $this->lookup('Inactive Scan')
            ->assertOk()
            ->assertJsonPath('product', null)
            ->assertJsonCount(0, 'matches');
        $this->lookup('Foreign Scan')
            ->assertOk()
            ->assertJsonPath('product', null)
            ->assertJsonCount(0, 'matches');
    }

    public function test_one_transfer_item_can_be_picked_from_multiple_bins_and_dispatch_keeps_each_bin_allocation(): void
    {
        $this->actingAsApiUser('admin');
        $source = $this->warehouse('Mobile pick source', 'MOBILE-SOURCE', true);
        $destination = $this->warehouse('Mobile pick destination', 'MOBILE-DEST');
        $binA = $this->location($source, 'BIN-A');
        $binB = $this->location($source, 'BIN-B');
        $product = $this->product($source, 'Multi-bin transfer item', 'MULTI-BIN-SKU');

        foreach ([[$binA, 4], [$binB, 6]] as [$bin, $quantity]) {
            $this->postJson('/api/stock-movements', [
                'product_id' => $product->id,
                'warehouse_id' => $source->id,
                'location_id' => $bin->id,
                'type' => 'in',
                'quantity' => $quantity,
                'reason' => 'Seed mobile pick bin',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertCreated();
        }

        $transfer = $this->postJson('/api/stock-transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ])->assertCreated()
            ->assertJsonPath('source_location_id', null)
            ->json();
        $itemId = $transfer['items'][0]['id'];

        $this->postJson('/api/mobile-warehouse/pick', $this->pickPayload(
            $itemId,
            $product,
            $source,
            $binA,
            4,
        ))->assertCreated()
            ->assertJsonPath('dispatch_completed', false)
            ->assertJsonPath('transfer.status', 'draft')
            ->assertJsonPath('transfer.source_location_id', null);

        $this->postJson('/api/mobile-warehouse/pick', $this->pickPayload(
            $itemId,
            $product,
            $source,
            $binB,
            6,
        ))->assertCreated()
            ->assertJsonPath('dispatch_completed', true)
            ->assertJsonPath('transfer.status', 'in_transit')
            ->assertJsonPath('transfer.source_location_id', null);

        $events = DB::table('stock_transfer_pick_events')
            ->where('stock_transfer_item_id', $itemId)
            ->orderBy('id')
            ->get();
        $this->assertSame([$binA->id, $binB->id], $events->pluck('location_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([4.0, 6.0], $events->pluck('quantity')->map(fn ($quantity) => (float) $quantity)->all());

        $dispatchMovements = StockMovement::withoutGlobalScopes()
            ->where('company_id', $this->apiCompany->id)
            ->where('source_type', 'stock_transfer')
            ->where('source_id', $transfer['id'])
            ->where('movement_code', 'transfer_out')
            ->orderBy('location_id')
            ->get();
        $this->assertCount(2, $dispatchMovements);
        $this->assertSame([
            $binA->id => 4.0,
            $binB->id => 6.0,
        ], $dispatchMovements->mapWithKeys(fn (StockMovement $movement) => [
            (int) $movement->location_id => (float) $movement->quantity,
        ])->all());
        $this->assertSame(0.0, (float) WarehouseStock::withoutGlobalScopes()
            ->where('product_id', $product->id)->where('location_id', $binA->id)->value('available_quantity'));
        $this->assertSame(0.0, (float) WarehouseStock::withoutGlobalScopes()
            ->where('product_id', $product->id)->where('location_id', $binB->id)->value('available_quantity'));
        $this->assertSame(10.0, (float) $product->fresh()->quantity);
    }

    private function lookup(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/mobile-warehouse/lookup?'.http_build_query(['code' => $code]));
    }

    private function warehouse(string $name, string $code, bool $default = false): Warehouse
    {
        return Warehouse::create($this->tenantAttributes([
            'name' => $name,
            'code' => $code,
            'is_active' => true,
            'is_default' => $default,
        ]));
    }

    private function location(Warehouse $warehouse, string $code): WarehouseLocation
    {
        return WarehouseLocation::create($this->tenantAttributes([
            'warehouse_id' => $warehouse->id,
            'type' => 'bin',
            'code' => $code,
            'name' => $code,
            'path' => $code,
            'is_active' => true,
        ]));
    }

    private function product(Warehouse $warehouse, string $name, string $sku, array $attributes = []): Product
    {
        return Product::create($this->tenantAttributes([
            'default_warehouse_id' => $warehouse->id,
            'name' => $name,
            'sku' => $sku,
            'quantity' => 0,
            'unit' => 'pcs',
            'min_quantity' => 0,
            'price' => 1,
            ...$attributes,
        ]));
    }

    private function pickPayload(
        int $itemId,
        Product $product,
        Warehouse $warehouse,
        WarehouseLocation $location,
        float $quantity,
    ): array {
        return [
            'stock_transfer_item_id' => $itemId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reason' => 'Mobile multi-bin pick',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
