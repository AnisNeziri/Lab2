<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\DailySale;
use App\Models\InventoryExpiryAlertState;
use App\Models\InventoryLot;
use App\Models\Notification;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryCountService;
use App\Services\InventoryExpiryAlertService;
use App\Services\StockMovementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryWorkflowHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_sale_retry_is_exactly_once_and_draft_deletion_keeps_the_audit_source(): void
    {
        $this->actingAsApiUser('admin');
        [$warehouse, $product] = $this->simpleInventory(5);
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => 'pcs',
                'quantity' => 1,
                'unit_price' => 10,
                'warehouse_id' => $warehouse->id,
            ]],
        ];

        $created = $this->postJson('/api/daily-sales', $payload)->assertCreated();
        $this->postJson('/api/daily-sales', $payload)->assertCreated()
            ->assertJsonPath('id', $created->json('id'));
        $this->postJson('/api/daily-sales', [
            ...$payload,
            'items' => [[...$payload['items'][0], 'quantity' => 2]],
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('daily_sales', 1);
        $this->assertSame(4.0, (float) $product->fresh()->quantity);
        $this->deleteJson('/api/daily-sales/'.$created->json('id'))->assertOk();
        $this->assertSame(5.0, (float) $product->fresh()->quantity);
        $this->assertNotNull(DailySale::withTrashed()->findOrFail($created->json('id'))->deleted_at);
        $this->assertSame(2, StockMovement::withoutGlobalScopes()
            ->where('source_type', 'daily_sale')->where('source_id', $created->json('id'))->count());
    }

    public function test_expired_inventory_is_blocked_unless_an_authorized_reason_is_audited(): void
    {
        $this->actingAsApiUser('admin');
        [$warehouse, $product] = $this->simpleInventory(0, [
            'tracking_mode' => 'batch_expiry',
            'expiration_controlled' => true,
            'fefo_enabled' => true,
            'near_expiry_days' => 30,
        ]);
        $location = WarehouseLocation::create($this->tenantAttributes([
            'warehouse_id' => $warehouse->id,
            'type' => 'bin',
            'code' => 'EXP-01',
            'name' => 'Expired inventory bin',
            'path' => 'EXP-01',
            'is_active' => true,
        ]));
        $inbound = $this->postJson('/api/stock-movements', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'type' => 'in',
            'quantity' => 2,
            'reason' => 'Opening expired test lot',
            'idempotency_key' => (string) Str::uuid(),
            'trace_allocations' => [[
                'lot_number' => 'EXP-LOT-1',
                'expiry_at' => now()->subDay()->toDateString(),
                'quantity' => 2,
            ]],
        ])->assertCreated();
        $lotId = $inbound->json('trace_lines.0.inventory_lot_id');

        app(InventoryExpiryAlertService::class)->syncCompany($this->apiCompany->id);
        app(InventoryExpiryAlertService::class)->syncCompany($this->apiCompany->id);
        $this->assertSame(1, Notification::withoutGlobalScopes()->where('company_id', $this->apiCompany->id)
            ->where('type', 'inventory_expired')->count());

        $line = [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit' => 'pcs',
            'quantity' => 1,
            'unit_price' => 10,
            'warehouse_id' => $warehouse->id,
            'trace_allocations' => [['inventory_lot_id' => $lotId, 'quantity' => 1]],
        ];
        $this->postJson('/api/daily-sales', [
            'idempotency_key' => (string) Str::uuid(),
            'sale_date' => now()->toDateString(),
            'items' => [$line],
        ])->assertUnprocessable()->assertJsonValidationErrors('trace_allocations');

        $sale = $this->postJson('/api/daily-sales', [
            'idempotency_key' => (string) Str::uuid(),
            'sale_date' => now()->toDateString(),
            'allow_expired_override' => true,
            'expired_override_reason' => 'Approved disposal to a controlled recipient.',
            'items' => [$line],
        ])->assertCreated();
        $movement = StockMovement::withoutGlobalScopes()
            ->where('source_type', 'daily_sale')->where('source_id', $sale->json('id'))->firstOrFail();
        $this->assertSame('Approved disposal to a controlled recipient.', data_get($movement->metadata, 'expired_stock_override.reason'));
        $this->assertNotNull(data_get($movement->metadata, 'expired_stock_override.authorized_by'));
    }

    public function test_return_keys_cannot_be_reused_for_changed_details_and_refunds_are_value_capped(): void
    {
        $this->actingAsApiUser('admin');
        [$warehouse, $product] = $this->simpleInventory();
        $customer = Customer::create($this->tenantAttributes(['name' => 'Return customer', 'is_active' => true]));
        $key = (string) Str::uuid();
        $payload = [
            'type' => 'customer',
            'customer_id' => $customer->id,
            'financial_resolution' => 'none',
            'financial_amount' => 0,
            'reason' => 'Customer brought goods back',
            'idempotency_key' => $key,
            'items' => [[
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 1,
                'condition' => 'sellable',
            ]],
        ];

        $created = $this->postJson('/api/inventory-returns', $payload)->assertCreated();
        $this->postJson('/api/inventory-returns', $payload)->assertCreated()
            ->assertJsonPath('id', $created->json('id'));
        $this->postJson('/api/inventory-returns', [...$payload, 'reason' => 'Changed return details'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/inventory-returns', [
            ...$payload,
            'idempotency_key' => (string) Str::uuid(),
            'financial_resolution' => 'debt_credit',
            'financial_amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('financial_amount');
        $this->assertDatabaseCount('inventory_returns', 1);
    }

    public function test_counts_snapshot_live_stock_cover_each_selected_state_and_reject_foreign_lots(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $company = \App\Models\Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $this->actingAs($user);
        $warehouse = Warehouse::create([
            'company_id' => $company->id, 'name' => 'Count warehouse', 'code' => 'COUNT-HARDEN',
            'is_active' => true, 'is_default' => true,
        ]);
        $product = Product::create([
            'company_id' => $company->id, 'default_warehouse_id' => $warehouse->id,
            'name' => 'Count product', 'sku' => 'COUNT-HARDEN-1', 'quantity' => 0,
            'unit' => 'pcs', 'price' => 4,
        ]);
        $counts = app(InventoryCountService::class);
        $scoped = $counts->create([
            'warehouse_id' => $warehouse->id,
            'stock_states' => ['damaged', 'quarantine'],
            'product_ids' => [$product->id],
        ]);
        $this->assertEqualsCanonicalizing(['damaged', 'quarantine'], $scoped->items->pluck('stock_state')->all());
        $outsideScope = Product::create([
            'company_id' => $company->id, 'default_warehouse_id' => $warehouse->id,
            'name' => 'Outside count scope', 'sku' => 'COUNT-OUTSIDE-SCOPE', 'quantity' => 0,
            'unit' => 'pcs', 'price' => 4,
        ]);
        try {
            $counts->record($scoped, ['items' => [[
                'product_id' => $outsideScope->id,
                'stock_state' => 'damaged',
                'counted_quantity' => 1,
            ]]]);
            $this->fail('An unexpected product outside the persisted count scope was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $movements = app(StockMovementService::class);
        $movements->store($this->movement($product, $warehouse, 5));
        $count = $counts->create([
            'warehouse_id' => $warehouse->id,
            'stock_states' => ['available'],
            'product_ids' => [$product->id],
        ]);
        $item = $count->items->sole();
        $count = $counts->record($count, ['items' => [[
            'count_item_id' => $item->id,
            'counted_quantity' => 5,
        ]]]);
        $count = $counts->submit($count);
        $movements->store($this->movement($product, $warehouse, 1));
        try {
            $counts->approve($count, 'Count approval');
            $this->fail('A count with changed live stock was approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $tracked = Product::create([
            'company_id' => $company->id, 'default_warehouse_id' => $warehouse->id,
            'name' => 'Tracked product', 'sku' => 'COUNT-TRACKED-1', 'quantity' => 0,
            'unit' => 'pcs', 'price' => 4, 'tracking_mode' => 'batch',
        ]);
        $foreignCompany = \App\Models\Company::factory()->create();
        $foreignProduct = Product::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id, 'name' => 'Foreign product',
            'sku' => 'FOREIGN-LOT-PRODUCT', 'quantity' => 0, 'unit' => 'pcs', 'price' => 1,
            'tracking_mode' => 'batch',
        ]);
        $foreignLot = InventoryLot::withoutGlobalScopes()->create([
            'company_id' => $foreignCompany->id, 'product_id' => $foreignProduct->id,
            'tracking_mode_snapshot' => 'batch', 'identity_key' => 'batch:foreign',
            'lot_number' => 'FOREIGN', 'status' => 'active',
        ]);
        $unexpected = $counts->create([
            'warehouse_id' => $warehouse->id,
            'stock_states' => ['available'],
        ]);
        try {
            $counts->record($unexpected, ['items' => [[
                'product_id' => $tracked->id,
                'inventory_lot_id' => $foreignLot->id,
                'stock_state' => 'available',
                'counted_quantity' => 1,
            ]]]);
            $this->fail('A foreign lot was accepted into a count.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
    }

    public function test_receipt_history_and_location_history_cannot_be_rewritten_or_deleted(): void
    {
        $this->actingAsApiUser('admin');
        [$warehouse, $product] = $this->simpleInventory();
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Historical supplier']));
        $category = Category::create($this->tenantAttributes(['name' => 'Historical category']));
        $product->update(['supplier_id' => $supplier->id, 'category_id' => $category->id]);
        $replacement = Product::create($this->tenantAttributes([
            'supplier_id' => $supplier->id, 'category_id' => $category->id,
            'default_warehouse_id' => $warehouse->id, 'name' => 'Replacement item',
            'sku' => 'HISTORY-REPLACEMENT', 'quantity' => 0, 'unit' => 'pcs', 'price' => 2,
        ]));
        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'status' => 'received',
            'ordered_at' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'description' => $product->name, 'unit' => 'pcs', 'quantity' => 2, 'unit_price' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $order = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'ordered',
            'currency' => 'EUR',
            'exchange_rate' => 1,
            'exchange_rate_date' => now()->toDateString(),
            'exchange_rate_source' => 'EUR base currency',
            'ordered_at' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'description' => $product->name, 'unit' => 'pcs', 'quantity' => 2, 'unit_price' => 1]],
        ])->assertCreated();
        $receiptKey = (string) Str::uuid();
        $receipt = [
            'idempotency_key' => $receiptKey,
            'warehouse_id' => $warehouse->id,
            'items' => [['id' => $order->json('items.0.id'), 'rejected_quantity' => 1]],
        ];
        $this->postJson('/api/purchase-orders/'.$order->json('id').'/receive', $receipt)->assertOk();
        $this->postJson('/api/purchase-orders/'.$order->json('id').'/receive', [
            ...$receipt,
            'items' => [['id' => $order->json('items.0.id'), 'rejected_quantity' => 2]],
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->putJson('/api/purchase-orders/'.$order->json('id'), [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'ordered',
            'currency' => 'EUR',
            'exchange_rate' => 1,
            'exchange_rate_date' => now()->toDateString(),
            'exchange_rate_source' => 'EUR base currency',
            'ordered_at' => now()->toDateString(),
            'change_reason' => 'Attempt to rewrite received identity',
            'items' => [[
                'id' => $order->json('items.0.id'), 'product_id' => $replacement->id,
                'description' => $replacement->name, 'unit' => 'pcs', 'quantity' => 2, 'unit_price' => 1,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $section = $this->postJson('/api/warehouse/sections', [
            'warehouse_id' => $warehouse->id, 'code' => 'AUDIT', 'name' => 'Audit location',
            'floor_level' => 1,
        ])->assertCreated();
        $locationId = $section->json('warehouse_location_id');
        $this->postJson('/api/stock-movements', [
            'product_id' => $replacement->id, 'warehouse_id' => $warehouse->id,
            'location_id' => $locationId, 'type' => 'in', 'quantity' => 1,
            'reason' => 'Create location history', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->postJson('/api/stock-movements', [
            'product_id' => $replacement->id, 'warehouse_id' => $warehouse->id,
            'location_id' => $locationId, 'type' => 'out', 'quantity' => 1,
            'reason' => 'Empty location but retain history', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->deleteJson('/api/warehouse/sections/'.$section->json('id'))
            ->assertUnprocessable()->assertJsonValidationErrors('section');
        $this->deleteJson('/api/warehouse-locations/'.$locationId)
            ->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->deleteJson('/api/warehouses/'.$warehouse->id)
            ->assertUnprocessable()->assertJsonValidationErrors('warehouse');
    }

    private function simpleInventory(float $quantity = 0, array $productAttributes = []): array
    {
        $warehouse = Warehouse::create($this->tenantAttributes([
            'name' => 'Workflow warehouse', 'code' => 'WORKFLOW-WH',
            'is_active' => true, 'is_default' => true,
        ]));
        $product = Product::create($this->tenantAttributes([
            'default_warehouse_id' => $warehouse->id,
            'name' => 'Workflow product', 'sku' => 'WORKFLOW-'.Str::random(8),
            'quantity' => $quantity, 'unit' => 'pcs', 'min_quantity' => 0, 'price' => 10,
            ...$productAttributes,
        ]));

        return [$warehouse, $product];
    }

    private function movement(Product $product, Warehouse $warehouse, float $quantity): array
    {
        return [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'in',
            'quantity' => $quantity,
            'stock_state' => 'available',
            'reason' => 'Count test movement',
            'movement_code' => 'import_adjustment_in',
            'source_type' => 'workflow_test',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
