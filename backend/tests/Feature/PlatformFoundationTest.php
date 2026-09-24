<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_is_permission_aware_directly_navigable_and_tenant_scoped(): void
    {
        $this->actingAsApiUser('admin');
        $product = Product::create($this->tenantAttributes($this->productData('AIMS Search Product', 'SEARCH-1')));
        PurchaseRequest::create($this->tenantAttributes(['request_number' => 'PR-SEARCH-1', 'status' => 'draft', 'requested_by' => auth()->id(), 'requested_at' => now(), 'currency' => 'EUR']));

        $other = Company::factory()->create();
        Product::withoutEvents(fn () => Product::create(array_merge($this->productData('Foreign Search Product', 'SEARCH-FOREIGN'), ['company_id' => $other->id])));

        $this->getJson('/api/search?q=Search')->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.url', '/products?product='.$product->id)
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('purchase_requests.0.title', 'PR-SEARCH-1');

        $this->actingAsApiUser('staff');
        PurchaseRequest::withoutEvents(fn () => PurchaseRequest::create($this->tenantAttributes(['request_number' => 'PR-STAFF-SEARCH', 'status' => 'draft', 'requested_by' => auth()->id(), 'requested_at' => now(), 'currency' => 'EUR'])));
        $this->getJson('/api/search?q=STAFF')->assertOk()->assertJsonMissingPath('purchase_requests');
    }

    public function test_capability_catalog_and_execution_enforce_permissions_and_company_scope(): void
    {
        $this->actingAsApiUser('staff');
        $product = Product::create($this->tenantAttributes($this->productData('Capability Product', 'CAP-1')));

        $this->getJson('/api/capabilities')->assertOk()
            ->assertJsonFragment(['name' => 'get_product'])
            ->assertJsonMissing(['name' => 'get_financial_summary']);
        $this->postJson('/api/capabilities/get_product/execute', ['input' => ['product_id' => $product->id]])
            ->assertOk()->assertJsonPath('data.id', $product->id)->assertJsonPath('data.name', 'Capability Product');
        $this->postJson('/api/capabilities/get_financial_summary/execute', ['input' => []])->assertForbidden();

        $other = Company::factory()->create();
        $foreign = Product::withoutEvents(fn () => Product::create(array_merge($this->productData('Foreign Capability', 'CAP-2'), ['company_id' => $other->id])));
        $this->postJson('/api/capabilities/get_product/execute', ['input' => ['product_id' => $foreign->id]])->assertNotFound();
    }

    public function test_entity_context_is_permission_aware_and_tenant_scoped(): void
    {
        $this->actingAsApiUser('admin');
        $product = Product::create($this->tenantAttributes($this->productData('Context Product', 'CONTEXT-1')));
        StockMovement::withoutEvents(fn () => StockMovement::create($this->tenantAttributes([
            'product_id' => $product->id,
            'type' => 'in',
            'movement_code' => 'PURCHASE_RECEIPT',
            'quantity' => 4,
            'quantity_before' => 0,
            'quantity_after' => 4,
            'occurred_at' => now(),
        ])));

        $this->getJson('/api/entity-context/product/'.$product->id)->assertOk()
            ->assertJsonPath('entity.label', 'Context Product')
            ->assertJsonPath('activity.0.title', 'Stock received');

        $other = Company::factory()->create();
        $foreign = Product::withoutEvents(fn () => Product::create(array_merge($this->productData('Foreign Context', 'CONTEXT-2'), ['company_id' => $other->id])));
        $this->getJson('/api/entity-context/product/'.$foreign->id)->assertNotFound();
    }

    public function test_integrity_endpoint_reports_real_checks_and_propagates_request_id(): void
    {
        $this->actingAsApiUser('admin');
        $response = $this->withHeader('X-Request-ID', 'platform-test-123')->getJson('/api/system-integrity')->assertOk();
        $response->assertHeader('X-Request-ID', 'platform-test-123')
            ->assertJsonStructure(['status', 'checked_at', 'request_id', 'summary' => ['healthy', 'attention', 'critical'], 'checks' => [['key', 'label', 'status', 'count', 'url', 'detail']]])
            ->assertJsonPath('request_id', 'platform-test-123');
    }

    private function productData(string $name, string $sku): array
    {
        return ['name' => $name, 'sku' => $sku, 'quantity' => 0, 'unit' => 'pcs', 'min_quantity' => 0, 'high_stock_threshold' => 0, 'price' => 0, 'purchase_price' => 0, 'selling_price' => 0, 'lifecycle_status' => 'active'];
    }
}
