<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseStock;
use App\Services\BinTransferService;
use App\Services\InventoryCountService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_balances_are_kept_per_bin_and_bin_moves_preserve_the_company_total(): void
    {
        [$product, $warehouse, $binA, $binB] = $this->inventoryContext();
        $movements = app(StockMovementService::class);
        $movements->store($this->movement($product, $warehouse, $binA, 'in', 10));
        $movements->store($this->movement($product, $warehouse, $binB, 'in', 5));

        $this->assertSame(2, WarehouseStock::query()->where('product_id', $product->id)->count());
        $this->assertSame(15.0, (float) $product->fresh()->quantity);

        app(BinTransferService::class)->move([
            'product_id' => $product->id,
            'source_location_id' => $binA->id,
            'destination_location_id' => $binB->id,
            'quantity' => 4,
            'stock_state' => 'available',
            'reason' => 'Re-slot fast-moving stock',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame(6.0, $this->binQuantity($product, $binA));
        $this->assertSame(9.0, $this->binQuantity($product, $binB));
        $this->assertSame(15.0, (float) $product->fresh()->quantity);
        $this->assertDatabaseHas('stock_movements', ['movement_code' => 'bin_transfer_out', 'location_id' => $binA->id]);
        $this->assertDatabaseHas('stock_movements', ['movement_code' => 'bin_transfer_in', 'location_id' => $binB->id]);
    }

    public function test_count_snapshots_expected_quantity_preserves_recounts_and_posts_only_the_approved_variance(): void
    {
        [$product, $warehouse, $binA] = $this->inventoryContext();
        $movements = app(StockMovementService::class);
        $movements->store($this->movement($product, $warehouse, $binA, 'in', 10));
        $counts = app(InventoryCountService::class);
        $session = $counts->create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $binA->id,
            'stock_states' => ['available'],
            'notes' => 'Cycle count',
        ]);
        $item = $session->items->firstOrFail();
        $this->assertSame(10.0, (float) $item->expected_quantity);

        // A later receipt makes the first pass stale. Requesting a recount
        // explicitly refreshes the book quantity before a new count is taken.
        $movements->store($this->movement($product, $warehouse, $binA, 'in', 2));
        $session = $counts->record($session, ['items' => [[
            'count_item_id' => $item->id, 'counted_quantity' => 8, 'notes' => 'First pass',
        ]]]);
        $session = $counts->requestRecount($session, [$item->id], 'Variance needs confirmation');
        $session = $counts->record($session, ['items' => [[
            'count_item_id' => $item->id, 'counted_quantity' => 9, 'notes' => 'Confirmed recount',
        ]]]);
        $session = $counts->submit($session);
        $session = $counts->approve($session, 'Supervisor approved recount');

        $approvedItem = $session->items->firstWhere('id', $item->id);
        $this->assertSame('approved', $session->status);
        $this->assertSame(12.0, (float) $approvedItem->expected_quantity);
        $this->assertSame(9.0, (float) $approvedItem->counted_quantity);
        $this->assertSame(-3.0, (float) $approvedItem->variance_quantity);
        $this->assertCount(3, $approvedItem->entries);
        $this->assertSame(9.0, $this->binQuantity($product, $binA));
        $this->assertDatabaseHas('stock_movements', [
            'movement_code' => 'stock_count', 'source_type' => 'inventory_count',
            'source_id' => $session->id, 'quantity' => 3,
        ]);
    }

    public function test_count_approval_rejects_a_new_bin_identity_and_recount_refreshes_the_scope(): void
    {
        [$product, $warehouse, $binA, $binB] = $this->inventoryContext();
        $movements = app(StockMovementService::class);
        $movements->store($this->movement($product, $warehouse, $binA, 'in', 10));

        $counts = app(InventoryCountService::class);
        $session = $counts->create([
            'warehouse_id' => $warehouse->id,
            'stock_states' => ['available'],
        ]);
        $item = $session->items->sole();
        $session = $counts->record($session, ['items' => [[
            'count_item_id' => $item->id,
            'counted_quantity' => 10,
        ]]]);
        $session = $counts->submit($session);

        // The original bin still contains ten, but the warehouse now has a
        // second identity which was outside the immutable count snapshot.
        $movements->store($this->movement($product, $warehouse, $binB, 'in', 2));

        try {
            $counts->approve($session, 'Approve stale count');
            $this->fail('A count with a new bin identity was approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame('submitted', $session->fresh()->status);
        $this->assertSame(12.0, (float) $product->fresh()->quantity);
        $this->assertDatabaseMissing('stock_movements', [
            'movement_code' => 'stock_count',
            'source_type' => 'inventory_count',
            'source_id' => $session->id,
        ]);

        $session = $counts->requestRecount($session, [$item->id], 'Refresh the changed warehouse scope');
        $this->assertCount(2, $session->items);
        $this->assertCount(2, $session->snapshot_identity_keys);
        $session = $counts->record($session, ['items' => $session->items->map(fn ($countItem) => [
            'count_item_id' => $countItem->id,
            'counted_quantity' => (float) $countItem->expected_quantity,
        ])->all()]);
        $session = $counts->submit($session);
        $session = $counts->approve($session, 'Refreshed scope counted and confirmed');

        $this->assertSame('approved', $session->status);
        $this->assertSame(12.0, (float) $product->fresh()->quantity);
        $this->assertDatabaseMissing('stock_movements', [
            'movement_code' => 'stock_count',
            'source_type' => 'inventory_count',
            'source_id' => $session->id,
        ]);
    }

    public function test_serials_are_unique_and_batch_expiry_outbound_movements_use_fefo(): void
    {
        [$serialProduct, $warehouse, $binA, $binB] = $this->inventoryContext('serial');
        $movements = app(StockMovementService::class);
        $movements->store($this->movement($serialProduct, $warehouse, $binA, 'in', 1, [[
            'serial_number' => 'SN-0001', 'quantity' => 1,
        ]]));

        try {
            $movements->store($this->movement($serialProduct, $warehouse, $binB, 'in', 1, [[
                'serial_number' => 'sn-0001', 'quantity' => 1,
            ]]));
            $this->fail('An active serial number was accepted twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('trace_allocations', $exception->errors());
        }
        $this->assertSame(1.0, (float) $serialProduct->fresh()->quantity);

        $batchProduct = Product::create([
            'company_id' => $serialProduct->company_id,
            'default_warehouse_id' => $warehouse->id,
            'name' => 'Expiry-controlled item', 'sku' => 'EXPIRY-1', 'quantity' => 0,
            'unit' => 'pcs', 'min_quantity' => 0, 'price' => 5,
            'tracking_mode' => 'batch_expiry', 'near_expiry_days' => 30, 'fefo_enabled' => true,
        ]);
        $movements->store($this->movement($batchProduct, $warehouse, $binA, 'in', 2, [[
            'lot_number' => 'LATE', 'expiry_at' => now()->addMonths(8)->toDateString(), 'quantity' => 2,
        ]]));
        $movements->store($this->movement($batchProduct, $warehouse, $binA, 'in', 2, [[
            'lot_number' => 'EARLY', 'expiry_at' => now()->addMonth()->toDateString(), 'quantity' => 2,
        ]]));
        $out = $movements->store($this->movement($batchProduct, $warehouse, $binA, 'out', 1));
        $early = InventoryLot::query()->where('product_id', $batchProduct->id)->where('lot_number', 'EARLY')->firstOrFail();

        $this->assertSame($early->id, $out->traceLines->sole()->inventory_lot_id);
        $this->assertSame(1.0, (float) $early->balances()->sum('quantity'));
    }

    private function inventoryContext(string $trackingMode = 'none'): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $this->actingAs($user);
        $warehouse = Warehouse::create([
            'company_id' => $company->id, 'name' => 'Count Warehouse', 'code' => 'COUNT-WH',
            'is_active' => true, 'is_default' => true,
        ]);
        $binA = WarehouseLocation::create([
            'company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'type' => 'bin',
            'code' => 'A-01', 'name' => 'Bin A-01', 'path' => 'A-01', 'is_active' => true,
        ]);
        $binB = WarehouseLocation::create([
            'company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'type' => 'bin',
            'code' => 'B-01', 'name' => 'Bin B-01', 'path' => 'B-01', 'is_active' => true,
        ]);
        $product = Product::create([
            'company_id' => $company->id, 'default_warehouse_id' => $warehouse->id,
            'name' => 'Count Product', 'sku' => 'COUNT-'.Str::random(8), 'quantity' => 0,
            'unit' => 'pcs', 'min_quantity' => 0, 'price' => 5,
            'tracking_mode' => $trackingMode, 'near_expiry_days' => 30, 'fefo_enabled' => true,
        ]);

        return [$product, $warehouse, $binA, $binB];
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
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'location_id' => $location->id, 'type' => $type, 'quantity' => $quantity,
            'stock_state' => 'available', 'reason' => 'Foundation test movement',
            'movement_code' => $type === 'in' ? 'import_adjustment_in' : 'legacy_stock_out',
            'source_type' => 'foundation_test', 'idempotency_key' => (string) Str::uuid(),
            'trace_allocations' => $traceAllocations,
        ];
    }

    private function binQuantity(Product $product, WarehouseLocation $location): float
    {
        return (float) WarehouseStock::query()
            ->where('product_id', $product->id)->where('location_id', $location->id)
            ->value('available_quantity');
    }
}
