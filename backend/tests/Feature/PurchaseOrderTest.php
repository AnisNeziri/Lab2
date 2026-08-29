<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_totals_recalculate_when_items_are_edited(): void
    {
        [$supplier, $product] = $this->setupProcurement();
        $created = $this->postJson('/api/purchase-orders', $this->payload($supplier, $product, 10, 2500))
            ->assertCreated()
            ->assertJsonPath('total_amount', '25000.00');

        $orderId = $created->json('id');
        $payload = $this->payload($supplier, $product, 12, 2500);
        $payload['items'][0]['id'] = $created->json('items.0.id');
        $payload['change_reason'] = 'Supplier confirmed two more units.';
        $this->putJson("/api/purchase-orders/{$orderId}", $payload)
            ->assertOk()
            ->assertJsonPath('total_amount', '30000.00');

        $this->assertDatabaseHas('purchase_order_changes', ['purchase_order_id' => $orderId, 'action' => 'updated']);
    }

    public function test_partial_payments_idempotency_and_full_payment_status(): void
    {
        [$supplier, $product] = $this->setupProcurement();
        $orderId = $this->postJson('/api/purchase-orders', $this->payload($supplier, $product, 10, 2500))->assertCreated()->json('id');

        $this->postJson("/api/purchase-orders/{$orderId}/payments", $this->payment(6000, 'payment-1'))->assertCreated()->assertJsonPath('remaining_balance', 19000);
        $this->postJson("/api/purchase-orders/{$orderId}/payments", $this->payment(6000, 'payment-1'))->assertCreated()->assertJsonPath('total_paid', '6000.00');
        $this->postJson("/api/purchase-orders/{$orderId}/payments", $this->payment(10000, 'payment-2'))->assertCreated()->assertJsonPath('remaining_balance', 9000);
        $this->postJson("/api/purchase-orders/{$orderId}/payments", $this->payment(9000, 'payment-3'))->assertCreated()->assertJsonPath('payment_status', 'paid');
        $this->postJson("/api/purchase-orders/{$orderId}/payments", $this->payment(1, 'payment-4'))->assertUnprocessable();

        $this->assertDatabaseCount('purchase_order_payments', 3);
    }

    public function test_edit_cannot_reduce_total_below_existing_payments(): void
    {
        [$supplier, $product] = $this->setupProcurement();
        $created = $this->postJson('/api/purchase-orders', $this->payload($supplier, $product, 10, 2500))->assertCreated();
        $orderId = $created->json('id');
        $this->postJson("/api/purchase-orders/{$orderId}/payments", $this->payment(6000, 'paid-part'))->assertCreated();

        $payload = $this->payload($supplier, $product, 1, 5000);
        $payload['items'][0]['id'] = $created->json('items.0.id');
        $this->putJson("/api/purchase-orders/{$orderId}", $payload)->assertUnprocessable();
        $this->assertSame('25000.00', PurchaseOrder::find($orderId)->total_amount);
    }

    public function test_partial_and_full_receipt_add_stock_once(): void
    {
        [$supplier, $product] = $this->setupProcurement();
        $created = $this->postJson('/api/purchase-orders', $this->payload($supplier, $product, 10, 100))->assertCreated();
        $orderId = $created->json('id');
        $itemId = $created->json('items.0.id');

        $receiptKey = (string) Str::uuid();
        $firstReceipt = $this->receiptPayload($itemId, 4, $receiptKey);
        $this->postJson("/api/purchase-orders/{$orderId}/receive", $firstReceipt)->assertOk()->assertJsonPath('status', 'partially_received');
        $this->postJson("/api/purchase-orders/{$orderId}/receive", $firstReceipt)->assertOk()->assertJsonPath('status', 'partially_received');
        $this->assertSame(4.0, $product->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->postJson("/api/purchase-orders/{$orderId}/receive", $this->receiptPayload($itemId, 6))->assertOk()->assertJsonPath('status', 'received');
        $this->postJson("/api/purchase-orders/{$orderId}/receive", $this->receiptPayload($itemId, 1))->assertUnprocessable();

        $this->assertSame(10.0, $product->fresh()->quantity);
    }

    public function test_unpaid_received_order_can_be_edited_and_receive_new_products(): void
    {
        [$supplier, $product] = $this->setupProcurement();
        $secondProduct = Product::create($this->tenantAttributes([
            'category_id' => $product->category_id,
            'supplier_id' => $supplier->id,
            'name' => 'Monitor',
            'sku' => 'PO-MONITOR',
            'quantity' => 0,
            'unit' => 'pcs',
            'price' => 80,
            'purchase_price' => 80,
        ]));
        $created = $this->postJson('/api/purchase-orders', $this->payload($supplier, $product, 2, 100))->assertCreated();
        $orderId = $created->json('id');
        $originalItemId = $created->json('items.0.id');

        $this->postJson("/api/purchase-orders/{$orderId}/receive", $this->receiptPayload($originalItemId, 2))
            ->assertOk()
            ->assertJsonPath('status', 'received')
            ->assertJsonPath('payment_status', 'unpaid');

        $payload = $this->payload($supplier, $product, 2, 100);
        $payload['items'][0]['id'] = $originalItemId;
        $payload['items'][] = ['product_id' => $secondProduct->id, 'description' => $secondProduct->name, 'unit' => 'pcs', 'quantity' => 3, 'unit_price' => 80];
        $updated = $this->putJson("/api/purchase-orders/{$orderId}", $payload)
            ->assertOk()
            ->assertJsonPath('status', 'partially_received')
            ->assertJsonCount(2, 'items');

        $newItemId = collect($updated->json('items'))->firstWhere('product_id', $secondProduct->id)['id'];
        $this->postJson("/api/purchase-orders/{$orderId}/receive", $this->receiptPayload($newItemId, 3))
            ->assertOk()
            ->assertJsonPath('status', 'received')
            ->assertJsonPath('total_paid', '0.00');

        $this->assertSame(2.0, $product->fresh()->quantity);
        $this->assertSame(3.0, $secondProduct->fresh()->quantity);
    }

    public function test_overdue_filter_and_company_isolation(): void
    {
        [$supplier, $product] = $this->setupProcurement();
        $payload = $this->payload($supplier, $product, 1, 100);
        $payload['ordered_at'] = now()->subDays(10)->toDateString();
        $payload['due_at'] = now()->subDay()->toDateString();
        $orderId = $this->postJson('/api/purchase-orders', $payload)->assertCreated()->json('id');

        $this->getJson('/api/purchase-orders?payment_status=overdue')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.payment_status', 'overdue');

        $foreign = PurchaseOrder::withoutGlobalScopes()->create(['company_id' => Company::factory()->create()->id, 'po_number' => 'FOREIGN', 'status' => 'draft', 'total_amount' => 1, 'total_paid' => 0, 'currency' => 'EUR']);
        $this->getJson("/api/purchase-orders/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/purchase-orders/{$orderId}")->assertOk();
    }

    public function test_only_completed_purchase_orders_can_be_soft_deleted(): void
    {
        [$supplier, $product] = $this->setupProcurement();
        $created = $this->postJson('/api/purchase-orders', $this->payload($supplier, $product, 2, 100))->assertCreated();
        $orderId = $created->json('id');

        $this->deleteJson("/api/purchase-orders/{$orderId}")->assertUnprocessable();
        $this->postJson("/api/purchase-orders/{$orderId}/receive", [
            'items' => [['id' => $created->json('items.0.id'), 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('status', 'received');
        $this->patchJson("/api/purchase-orders/{$orderId}/status", [
            'status' => 'completed',
            'reason' => 'Order fully closed.',
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->deleteJson("/api/purchase-orders/{$orderId}")->assertOk();
        $this->assertSoftDeleted('purchase_orders', ['id' => $orderId]);
        $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id' => $orderId]);
    }

    private function setupProcurement(): array
    {
        $this->actingAsApiUser('admin');
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Supplier']));
        $category = Category::create($this->tenantAttributes(['name' => 'Equipment']));
        $product = Product::create($this->tenantAttributes(['category_id' => $category->id, 'supplier_id' => $supplier->id, 'name' => 'Computer', 'sku' => 'PO-PC', 'quantity' => 0, 'unit' => 'pcs', 'price' => 100, 'purchase_price' => 100]));

        return [$supplier, $product];
    }

    private function payload(Supplier $supplier, Product $product, float $quantity, float $price): array
    {
        return ['supplier_id' => $supplier->id, 'ordered_at' => now()->toDateString(), 'due_at' => now()->addMonth()->toDateString(), 'currency' => 'EUR', 'status' => 'ordered', 'items' => [['product_id' => $product->id, 'description' => $product->name, 'unit' => 'pcs', 'quantity' => $quantity, 'unit_price' => $price]]];
    }

    private function payment(float $amount, string $key): array
    {
        return ['amount' => $amount, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer', 'idempotency_key' => $key];
    }

    private function receiptPayload(int $itemId, float $quantity, ?string $key = null): array
    {
        return [
            'items' => [['id' => $itemId, 'quantity' => $quantity]],
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ];
    }
}
