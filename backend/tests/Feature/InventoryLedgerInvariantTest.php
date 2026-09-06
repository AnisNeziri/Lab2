<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryLot;
use App\Models\InventoryTraceBalance;
use App\Models\LandedCost;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseStock;
use App\Services\InventorySnapshotService;
use App\Services\ReplenishmentService;
use App\Services\StockMovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryLedgerInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_transition_changes_sellable_state_without_changing_physical_or_company_stock(): void
    {
        [$product, $warehouse, $binA] = $this->inventoryContext();
        $movements = app(StockMovementService::class);
        $movements->store($this->movement($product, $warehouse, $binA, 'in', 10));

        foreach (['reserved', 'quarantine', 'damaged', 'blocked'] as $state) {
            $key = (string) Str::uuid();
            $payload = [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $binA->id,
                'from_state' => 'available',
                'to_state' => $state,
                'quantity' => 2,
                'reason' => "Move stock into {$state} state.",
                'idempotency_key' => $key,
            ];
            $transition = $movements->transitionState($payload);
            $retry = $movements->transitionState($payload);
            $balance = WarehouseStock::withoutGlobalScopes()
                ->where('product_id', $product->id)
                ->where('location_id', $binA->id)
                ->sole();

            $this->assertSame(10.0, (float) $product->fresh()->quantity);
            $this->assertSame(10.0, (float) $balance->quantity);
            $this->assertSame(8.0, (float) $balance->available_quantity);
            $this->assertSame(2.0, (float) $balance->{$state.'_quantity'});
            $this->assertSame($transition['out_movement']->id, $retry['out_movement']->id);
            $this->assertFalse((bool) $transition['out_movement']->affects_company_quantity);
            $this->assertFalse((bool) $transition['in_movement']->affects_company_quantity);

            $movements->transitionState([
                ...$payload,
                'from_state' => $state,
                'to_state' => 'available',
                'reason' => "Release stock from {$state} state.",
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $released = $balance->fresh();
            $this->assertSame(10.0, (float) $released->quantity);
            $this->assertSame(10.0, (float) $released->available_quantity);
            $this->assertSame(0.0, (float) $released->{$state.'_quantity'});
        }
    }

    public function test_competing_reservation_and_state_transfer_cannot_overdraw_available_stock(): void
    {
        [$product, $warehouse, $binA] = $this->inventoryContext();
        $movements = app(StockMovementService::class);
        $movements->store($this->movement($product, $warehouse, $binA, 'in', 10));
        $movements->transitionState([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $binA->id,
            'from_state' => 'available',
            'to_state' => 'reserved',
            'quantity' => 7,
            'reason' => 'First competing allocation.',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            $movements->transitionState([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'location_id' => $binA->id,
                'from_state' => 'available',
                'to_state' => 'quarantine',
                'quantity' => 4,
                'reason' => 'Competing state transfer.',
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->fail('A competing state transfer overdrew the remaining available stock.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $balance = WarehouseStock::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('location_id', $binA->id)
            ->sole();
        $this->assertSame(10.0, (float) $product->fresh()->quantity);
        $this->assertSame(10.0, (float) $balance->quantity);
        $this->assertSame(3.0, (float) $balance->available_quantity);
        $this->assertSame(7.0, (float) $balance->reserved_quantity);
        $this->assertSame(0.0, (float) $balance->quarantine_quantity);
    }

    public function test_allocated_outbound_consumes_multiple_bins_without_an_aggregate_movement_and_is_retry_safe(): void
    {
        [$product, $warehouse, $binA, $binB] = $this->inventoryContext();
        $movements = app(StockMovementService::class);
        $movements->store($this->movement($product, $warehouse, $binA, 'in', 2));
        $movements->store($this->movement($product, $warehouse, $binB, 'in', 4));
        $key = (string) Str::uuid();
        $request = [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'out',
            'quantity' => 5,
            'stock_state' => 'available',
            'reason' => 'One sale picked from two bins.',
            'movement_code' => 'daily_sale',
            'source_type' => 'test_sale',
            'source_id' => 101,
            'idempotency_key' => $key,
        ];

        $allocated = $movements->storeOutboundAllocated($request);
        $retried = $movements->storeOutboundAllocated($request);
        try {
            $movements->storeOutboundAllocated([...$request, 'quantity' => 4]);
            $this->fail('An allocated-outbound key was reused for different details.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }
        try {
            $movements->storeOutboundAllocated([...$request, 'reason' => 'A different audit reason.']);
            $this->fail('An allocated-outbound key was reused with a different audit reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertCount(2, $allocated);
        $this->assertEqualsCanonicalizing([$binA->id, $binB->id], $allocated->pluck('location_id')->all());
        $this->assertEqualsCanonicalizing([2.0, 3.0], $allocated->map(fn ($row) => (float) $row->quantity)->all());
        $this->assertEquals($allocated->pluck('id')->all(), $retried->pluck('id')->all());
        $this->assertSame(2, StockMovement::withoutGlobalScopes()
            ->where('source_type', 'test_sale')->where('source_id', 101)->count());
        $this->assertSame(1.0, (float) $product->fresh()->quantity);
        $this->assertSame(1.0, (float) WarehouseStock::withoutGlobalScopes()
            ->where('product_id', $product->id)->sum('available_quantity'));
    }

    public function test_tracked_allocated_outbound_uses_global_fefo_across_bins(): void
    {
        [$product, $warehouse, $binA, $binB] = $this->inventoryContext([
            'tracking_mode' => 'batch_expiry',
            'expiration_controlled' => true,
            'fefo_enabled' => true,
        ]);
        $movements = app(StockMovementService::class);
        $movements->store($this->movement($product, $warehouse, $binA, 'in', 2, [[
            'lot_number' => 'LATE-BIN-A',
            'expiry_at' => now()->addMonths(6)->toDateString(),
            'quantity' => 2,
        ]]));
        $movements->store($this->movement($product, $warehouse, $binB, 'in', 2, [[
            'lot_number' => 'EARLY-BIN-B',
            'expiry_at' => now()->addMonth()->toDateString(),
            'quantity' => 2,
        ]]));

        $allocated = $movements->storeOutboundAllocated([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'out',
            'quantity' => 3,
            'stock_state' => 'available',
            'reason' => 'FEFO sale across bins.',
            'movement_code' => 'daily_sale',
            'source_type' => 'test_fefo_sale',
            'source_id' => 102,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $early = InventoryLot::query()->where('product_id', $product->id)->where('lot_number', 'EARLY-BIN-B')->sole();
        $late = InventoryLot::query()->where('product_id', $product->id)->where('lot_number', 'LATE-BIN-A')->sole();
        $issued = $allocated->flatMap->traceLines->groupBy('inventory_lot_id')
            ->map(fn ($rows) => (float) $rows->sum('quantity'));
        $this->assertCount(2, $allocated);
        $this->assertSame(2.0, $issued->get($early->id));
        $this->assertSame(1.0, $issued->get($late->id));
        $this->assertSame(0.0, (float) $early->balances()->sum('quantity'));
        $this->assertSame(1.0, (float) $late->balances()->sum('quantity'));
    }

    public function test_opening_quantity_is_created_as_history_and_later_changes_require_a_scoped_adjustment(): void
    {
        [, $warehouse, $binA, , $category] = $this->inventoryContext();
        $created = $this->postJson('/api/products', [
            'category_id' => $category->id,
            'default_warehouse_id' => $warehouse->id,
            'name' => 'Opening balance product',
            'sku' => 'OPENING-LEDGER-1',
            'quantity' => 7,
            'unit' => 'pcs',
            'min_quantity' => 0,
            'price' => 4,
        ])->assertCreated();
        $productId = $created->json('id');
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'movement_code' => 'opening_balance',
            'quantity' => 7,
        ]);

        $this->putJson('/api/products/'.$productId, ['quantity' => 9])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson('/api/stock-movements', [
            'product_id' => $productId,
            'type' => 'in',
            'quantity' => 2,
            'reason' => 'Counted two additional units.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id', 'location_id']);

        $this->postJson('/api/stock-movements', [
            'product_id' => $productId,
            'warehouse_id' => $warehouse->id,
            'location_id' => $binA->id,
            'type' => 'in',
            'quantity' => 2,
            'reason' => 'Counted two additional units.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('movement_code', 'manual_adjustment_in');
        $this->assertSame(9.0, (float) Product::findOrFail($productId)->quantity);
    }

    public function test_shelf_life_uses_the_configured_basis_and_expired_purchase_receipts_require_authorization(): void
    {
        Carbon::setTestNow('2026-08-30 10:00:00');
        [$manufacturedProduct, $warehouse, $binA, , $category, $supplier] = $this->inventoryContext([
            'tracking_mode' => 'batch',
            'expiration_controlled' => true,
            'default_shelf_life_days' => 30,
            'shelf_life_basis' => 'manufacture_date',
        ]);
        $missingBasis = [
            'product_id' => $manufacturedProduct->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $binA->id,
            'type' => 'in',
            'quantity' => 1,
            'reason' => 'Manufacture-basis receipt.',
            'idempotency_key' => (string) Str::uuid(),
            'trace_allocations' => [['lot_number' => 'MFG-MISSING', 'quantity' => 1]],
        ];
        $this->postJson('/api/stock-movements', $missingBasis)
            ->assertUnprocessable()->assertJsonValidationErrors('trace_allocations');
        $this->postJson('/api/stock-movements', [
            ...$missingBasis,
            'idempotency_key' => (string) Str::uuid(),
            'trace_allocations' => [[
                'lot_number' => 'MFG-KNOWN', 'manufactured_at' => '2026-08-10', 'quantity' => 1,
            ]],
        ])->assertCreated();
        $this->assertDatabaseHas('inventory_lots', [
            'product_id' => $manufacturedProduct->id,
            'lot_number' => 'MFG-KNOWN',
            'expiry_at' => '2026-09-09 00:00:00',
        ]);

        $receiptBasis = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'default_warehouse_id' => $warehouse->id,
            'name' => 'Receipt basis product',
            'sku' => 'RECEIPT-BASIS',
            'quantity' => 0,
            'unit' => 'pcs',
            'price' => 5,
            'tracking_mode' => 'serial',
            'expiration_controlled' => true,
            'default_shelf_life_days' => 30,
            'shelf_life_basis' => 'receipt_date',
        ]));
        $this->postJson('/api/stock-movements', [
            'product_id' => $receiptBasis->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $binA->id,
            'type' => 'in',
            'quantity' => 1,
            'reason' => 'Receipt-date serial.',
            'idempotency_key' => (string) Str::uuid(),
            'trace_allocations' => [['serial_number' => 'EXP-SERIAL-1', 'quantity' => 1]],
        ])->assertCreated();
        $this->assertDatabaseHas('inventory_lots', [
            'product_id' => $receiptBasis->id,
            'serial_number' => 'EXP-SERIAL-1',
            'expiry_at' => '2026-09-29 00:00:00',
        ]);

        $expiredProduct = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
            'default_warehouse_id' => $warehouse->id,
            'name' => 'Expired supplier lot',
            'sku' => 'EXPIRED-PO-LOT',
            'quantity' => 0,
            'unit' => 'pcs',
            'price' => 5,
            'tracking_mode' => 'batch_expiry',
            'expiration_controlled' => true,
            'fefo_enabled' => true,
        ]));
        $order = $this->postJson('/api/purchase-orders', $this->purchaseOrderPayload(
            $supplier,
            $warehouse,
            $expiredProduct,
            1,
        ))->assertCreated()->json();
        $receipt = [
            'warehouse_id' => $warehouse->id,
            'location_id' => $binA->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [[
                'id' => $order['items'][0]['id'],
                'accepted_quantity' => 1,
                'trace_allocations' => [[
                    'stock_state' => 'available',
                    'lot_number' => 'EXPIRED-AT-RECEIPT',
                    'expiry_at' => '2026-08-29',
                    'quantity' => 1,
                ]],
            ]],
        ];
        $this->postJson('/api/purchase-orders/'.$order['id'].'/receive', $receipt)
            ->assertUnprocessable()->assertJsonValidationErrors('trace_allocations');
        $this->assertSame(0.0, (float) $expiredProduct->fresh()->quantity);
        $this->assertDatabaseCount('goods_receipts', 0);

        $this->postJson('/api/purchase-orders/'.$order['id'].'/receive', [
            ...$receipt,
            'idempotency_key' => (string) Str::uuid(),
            'allow_expired_receipt' => true,
            'expired_receipt_reason' => 'Approved isolated receipt for documented disposal.',
        ])->assertOk()->assertJsonPath('status', 'received');
        $this->assertSame(1.0, (float) $expiredProduct->fresh()->quantity);
    }

    public function test_incoming_and_projected_quantities_follow_partial_purchase_order_receipts(): void
    {
        [$product, $warehouse, $binA, , , $supplier] = $this->inventoryContext();
        $order = $this->postJson('/api/purchase-orders', $this->purchaseOrderPayload(
            $supplier,
            $warehouse,
            $product,
            10,
        ))->assertCreated()->json();
        $snapshots = app(InventorySnapshotService::class);
        $before = $snapshots->forProduct($product->fresh());
        $this->assertSame(0.0, $before['on_hand']);
        $this->assertSame(0.0, $before['available']);
        $this->assertSame(10.0, $before['incoming']);
        $this->assertSame(10.0, $before['projected']);

        $this->postJson('/api/purchase-orders/'.$order['id'].'/receive', [
            'warehouse_id' => $warehouse->id,
            'location_id' => $binA->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['id' => $order['items'][0]['id'], 'accepted_quantity' => 4]],
        ])->assertOk()->assertJsonPath('status', 'partially_received');

        $after = $snapshots->forProduct($product->fresh());
        $this->assertSame(4.0, $after['on_hand']);
        $this->assertSame(4.0, $after['available']);
        $this->assertSame(6.0, $after['incoming']);
        $this->assertSame(10.0, $after['projected']);
    }

    public function test_atp_does_not_promise_undated_or_future_purchase_order_stock_early(): void
    {
        [$product, $warehouse, , , , $supplier] = $this->inventoryContext();
        $expectedAt = now('Europe/Tirane')->addDays(2)->toDateString();
        $this->postJson('/api/purchase-orders', [
            ...$this->purchaseOrderPayload($supplier, $warehouse, $product, 10),
            'expected_at' => $expectedAt,
        ])->assertCreated();

        $snapshots = app(InventorySnapshotService::class);
        $today = $snapshots->forProduct($product->fresh(), now('Europe/Tirane')->toDateString());
        $arrivalDay = $snapshots->forProduct($product->fresh(), $expectedAt);

        $this->assertSame(10.0, $today['incoming']);
        $this->assertSame(0.0, $today['expected_incoming_by_as_of']);
        $this->assertSame(0.0, $today['available_to_promise_by_as_of']);
        $this->assertSame(10.0, $arrivalDay['expected_incoming_by_as_of']);
        $this->assertSame(10.0, $arrivalDay['available_to_promise_by_as_of']);
        $this->assertSame(0.0, $arrivalDay['committed_outgoing']);
        $this->assertNotEmpty($arrivalDay['commitment_limitations']);
    }

    public function test_barcode_identity_is_trimmed_case_folded_and_unique_across_primary_and_alternative_codes(): void
    {
        [, $warehouse, , , $category] = $this->inventoryContext();
        $created = $this->postJson('/api/products', [
            'category_id' => $category->id,
            'default_warehouse_id' => $warehouse->id,
            'name' => 'Barcode identity product',
            'sku' => 'BARCODE-IDENTITY-1',
            'barcode' => '  AbC   123  ',
            'alternative_barcodes' => [['barcode' => ' Carton   987 ', 'label' => 'Carton']],
            'quantity' => 0,
            'unit' => 'pcs',
            'min_quantity' => 0,
            'price' => 1,
        ])->assertCreated();
        $productId = $created->json('id');
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'barcode' => 'AbC   123',
            'barcode_normalized' => 'abc 123',
        ]);
        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $productId,
            'barcode' => 'Carton   987',
            'barcode_normalized' => 'carton 987',
        ]);
        $this->getJson('/api/products/lookup?'.http_build_query(['sku' => ' ABC 123 ']))
            ->assertOk()->assertJsonPath('id', $productId);
        $this->getJson('/api/products/lookup?'.http_build_query(['sku' => 'carton 987']))
            ->assertOk()->assertJsonPath('id', $productId);

        $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Primary barcode collision',
            'sku' => 'BARCODE-IDENTITY-2',
            'barcode' => 'abc  123',
            'quantity' => 0,
            'unit' => 'pcs',
            'min_quantity' => 0,
            'price' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('alternative_barcodes');
        $this->postJson('/api/products', [
            'category_id' => $category->id,
            'name' => 'Alternative barcode collision',
            'sku' => 'BARCODE-IDENTITY-3',
            'alternative_barcodes' => [['barcode' => ' cArToN  987 ']],
            'quantity' => 0,
            'unit' => 'pcs',
            'min_quantity' => 0,
            'price' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('alternative_barcodes');
    }

    public function test_configured_iso_base_currency_is_accepted_by_supplier_and_landed_cost_requests(): void
    {
        [$product, $warehouse, , , , $supplier] = $this->inventoryContext();
        $this->apiCompany->update(['base_currency' => 'HUF']);
        $suggestion = app(ReplenishmentService::class)->suggestions([
            'product_ids' => [$product->id],
            'include_ok' => true,
        ])['suggestions'][0];
        $this->assertSame('HUF', $suggestion['preferred_supplier']['currency']);
        $this->assertSame(1.0, $suggestion['preferred_supplier']['exchange_rate_to_base']);
        $secondSupplier = Supplier::create($this->tenantAttributes(['name' => 'Second local supplier']));
        $cataloguePayload = [
            'product_id' => $product->id,
            'supplier_id' => $secondSupplier->id,
            'purchase_price' => 1200,
            'currency' => 'huf',
            'is_active' => true,
        ];
        $this->postJson('/api/product-suppliers', $cataloguePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency');
        $catalogueResponse = $this->postJson('/api/product-suppliers', [
            ...$cataloguePayload,
            'currency' => 'HUF',
        ])->assertCreated()->assertJsonPath('currency', 'HUF');
        $catalogue = ProductSupplier::findOrFail($catalogueResponse->json('id'));
        $this->assertSame(1.0, (float) $catalogue->exchange_rate_to_base);

        $baseCurrencyOrder = $this->postJson('/api/purchase-orders', [
            ...$this->purchaseOrderPayload($supplier, $warehouse, $product, 2),
            'currency' => 'HUF',
        ])->assertCreated()
            ->assertJsonPath('currency', 'HUF')
            ->assertJsonPath('exchange_rate', '1.000000');
        $this->assertSame(10.0, (float) $baseCurrencyOrder->json('total_amount_eur'));

        $order = PurchaseOrder::create($this->tenantAttributes([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'po_number' => 'PO-BASE-HUF',
            'status' => 'received',
            'total_amount' => 100,
            'total_amount_eur' => 100,
            'total_paid' => 0,
            'currency' => 'HUF',
            'exchange_rate' => 1,
            'ordered_at' => now()->toDateString(),
        ]));
        $orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'unit' => 'pcs',
            'inventory_unit' => 'pcs',
            'conversion_mode' => 'none',
            'conversion_factor' => 1,
            'quantity' => 10,
            'base_quantity' => 10,
            'received_quantity' => 10,
            'received_base_quantity' => 10,
            'unit_price' => 10,
            'line_total' => 100,
        ]);
        $receipt = GoodsReceipt::create($this->tenantAttributes([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'receipt_number' => 'GR-BASE-HUF',
            'received_at' => now(),
            'status' => 'posted',
            'idempotency_key' => (string) Str::uuid(),
        ]));
        GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'ordered_unit' => 'pcs',
            'accepted_quantity' => 10,
            'damaged_quantity' => 0,
            'rejected_quantity' => 0,
            'accepted_base_quantity' => 10,
            'damaged_base_quantity' => 0,
            'inventory_unit' => 'pcs',
            'conversion_mode' => 'none',
            'conversion_factor' => 1,
            'purchase_unit_price' => 10,
            'purchase_currency' => 'HUF',
            'purchase_exchange_rate' => 1,
            'base_purchase_cost' => 100,
            'base_purchase_unit_cost' => 10,
            'landed_cost_allocated' => 0,
            'landed_cost_unit' => 0,
            'final_inventory_unit_cost' => 10,
        ]);
        $landedResponse = $this->postJson('/api/landed-costs', [
            'goods_receipt_id' => $receipt->id,
            'cost_type' => 'freight',
            'amount' => 500,
            'currency' => 'HUF',
            'allocation_method' => 'quantity',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('currency', 'HUF');
        $landed = LandedCost::findOrFail($landedResponse->json('id'));
        $this->assertSame('HUF', $landed->base_currency);
        $this->assertSame(1.0, (float) $landed->exchange_rate_to_base);
        $this->assertSame(500.0, (float) $landed->base_currency_amount);
        $this->assertSame(1, LandedCost::withoutGlobalScopes()->count());
        $this->assertSame(1, ProductSupplier::withoutGlobalScopes()
            ->where('product_id', $product->id)->count());
    }

    public function test_archiving_fails_when_trace_and_physical_sources_disagree_even_if_product_quantity_is_zero(): void
    {
        [$product, $warehouse, $binA] = $this->inventoryContext(['tracking_mode' => 'batch']);
        $lot = InventoryLot::create($this->tenantAttributes([
            'product_id' => $product->id,
            'tracking_mode_snapshot' => 'batch',
            'identity_key' => 'batch:orphaned-trace',
            'lot_number' => 'ORPHANED-TRACE',
            'status' => 'active',
        ]));
        InventoryTraceBalance::create($this->tenantAttributes([
            'inventory_lot_id' => $lot->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $binA->id,
            'location_key' => $binA->id,
            'stock_state' => 'available',
            'quantity' => 1,
        ]));

        $this->deleteJson('/api/products/'.$product->id)
            ->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'lifecycle_status' => 'active',
        ]);
    }

    public function test_first_tenant_request_catches_up_expiry_alerts_when_no_scheduler_was_running(): void
    {
        Carbon::setTestNow('2026-08-30 10:00:00');
        [$product, $warehouse, $binA] = $this->inventoryContext([
            'tracking_mode' => 'batch_expiry',
            'expiration_controlled' => true,
            'near_expiry_days' => 30,
        ]);
        app(StockMovementService::class)->store($this->movement($product, $warehouse, $binA, 'in', 1, [[
            'lot_number' => 'STARTUP-CATCHUP',
            'expiry_at' => '2026-09-01',
            'quantity' => 1,
        ]]));
        Cache::forget("inventory-expiry-sync:{$this->apiCompany->id}");

        $this->getJson('/api/products?per_page=1')->assertOk();

        $this->assertDatabaseHas('notifications', [
            'company_id' => $this->apiCompany->id,
            'type' => 'inventory_near_expiry',
        ]);
        $this->assertDatabaseHas('inventory_expiry_alert_states', [
            'company_id' => $this->apiCompany->id,
            'alert_state' => 'near_expiry',
        ]);
    }

    /**
     * @return array{0: Product, 1: Warehouse, 2: WarehouseLocation, 3: WarehouseLocation, 4: Category, 5: Supplier}
     */
    private function inventoryContext(array $productAttributes = []): array
    {
        $this->actingAsApiUser('admin');
        $this->actingAs(User::query()->where('company_id', $this->apiCompany->id)->sole());
        $category = Category::create($this->tenantAttributes(['name' => 'Inventory invariants']));
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Inventory supplier']));
        $warehouse = Warehouse::create($this->tenantAttributes([
            'name' => 'Invariant warehouse',
            'code' => 'INVARIANT-WH',
            'is_active' => true,
            'is_default' => true,
        ]));
        $binA = WarehouseLocation::create($this->tenantAttributes([
            'warehouse_id' => $warehouse->id,
            'type' => 'bin',
            'code' => 'A-01',
            'name' => 'Bin A-01',
            'path' => 'A-01',
            'is_active' => true,
        ]));
        $binB = WarehouseLocation::create($this->tenantAttributes([
            'warehouse_id' => $warehouse->id,
            'type' => 'bin',
            'code' => 'B-01',
            'name' => 'Bin B-01',
            'path' => 'B-01',
            'is_active' => true,
        ]));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id,
            'supplier_id' => $supplier->id,
            'default_warehouse_id' => $warehouse->id,
            'name' => 'Invariant product',
            'sku' => 'INVARIANT-'.Str::upper(Str::random(8)),
            'quantity' => 0,
            'unit' => 'pcs',
            'min_quantity' => 0,
            'price' => 5,
            ...$productAttributes,
        ]));

        return [$product, $warehouse, $binA, $binB, $category, $supplier];
    }

    private function movement(
        Product $product,
        Warehouse $warehouse,
        WarehouseLocation $location,
        string $type,
        float $quantity,
        array $traceAllocations = [],
    ): array {
        return [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'type' => $type,
            'quantity' => $quantity,
            'stock_state' => 'available',
            'reason' => 'Inventory invariant test movement.',
            'movement_code' => $type === 'in' ? 'import_adjustment_in' : 'legacy_stock_out',
            'source_type' => 'inventory_invariant_test',
            'idempotency_key' => (string) Str::uuid(),
            'trace_allocations' => $traceAllocations,
        ];
    }

    private function purchaseOrderPayload(
        Supplier $supplier,
        Warehouse $warehouse,
        Product $product,
        float $quantity,
    ): array {
        return [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'ordered_at' => now()->toDateString(),
            'currency' => 'EUR',
            'status' => 'ordered',
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'unit' => $product->unit,
                'quantity' => $quantity,
                'unit_price' => 5,
            ]],
        ];
    }
}
