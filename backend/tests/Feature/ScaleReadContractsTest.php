<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Services\AssistantEntityResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class ScaleReadContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_search_is_bounded_and_keeps_bin_adjustment_fields(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $category = Category::create(['name' => 'Reference']);
        for ($i = 0; $i < 130; $i++) {
            Product::create(['name' => 'Reference '.$i, 'sku' => 'REF-'.$i, 'category_id' => $category->id, 'quantity' => 10, 'price' => 5, 'min_quantity' => 1, 'unit' => 'pcs', 'tracking_mode' => 'batch_expiry', 'expiration_controlled' => true, 'default_shelf_life_days' => 30, 'shelf_life_basis' => 'manufacture_date']);
        }
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $r = $this->getJson('/api/products?reference_only=1&per_page=50')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('total', 130);
        $this->assertLessThan(20, $queries);
        $this->assertLessThan(70000, strlen($r->getContent()));
        $r->assertJsonPath('data.0.expiration_controlled', true)->assertJsonPath('data.0.default_shelf_life_days', 30)->assertJsonPath('data.0.available_quantity', 10);
        $this->getJson('/api/products?reference_only=1&search=REF-129')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.sku', 'REF-129');
        $this->getJson('/api/products?per_page=-1')->assertUnprocessable();
    }

    public function test_stock_and_shipment_pages_preserve_all_records_in_stable_order(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $p = Product::create(['name' => 'Paged', 'sku' => 'PAGED', 'quantity' => 0, 'price' => 5]);
        for ($i = 0; $i < 130; $i++) {
            StockMovement::create(['product_id' => $p->id, 'type' => 'in', 'quantity' => 1, 'quantity_before' => 0, 'quantity_after' => 1, 'reason' => 'Page '.$i, 'occurred_at' => today()]);
            Shipment::create(['tracking_number' => 'PAGE-'.$i, 'status' => 'in_transit', 'tracking_mode' => 'aisstream', 'tracking_provider' => 'aisstream']);
        }
        foreach (['/stock-movements', '/shipments'] as $route) {
            $ids = [];
            for ($page = 1; $page <= 3; $page++) {
                $r = $this->getJson('/api'.$route.'?page='.$page.'&per_page=50')->assertOk()->assertJsonPath('total', 130);
                $ids = array_merge($ids, array_column($r->json('data'), 'id'));
            }
            $this->assertCount(130, array_unique($ids));
            $sorted = $ids;
            rsort($sorted);
            $this->assertSame($sorted, $ids);
            $this->getJson('/api'.$route.'?page=1&per_page=101')->assertUnprocessable();
        }
        $stream = app(StockMovementRepositoryInterface::class)->exportList([]);
        StockMovement::create(['product_id' => $p->id, 'type' => 'in', 'quantity' => 1, 'quantity_before' => 0, 'quantity_after' => 1, 'reason' => 'After export snapshot', 'occurred_at' => today()]);
        $this->assertCount(130, $stream->all());
    }

    public function test_purchase_order_revision_rejects_stale_edits_and_advances_after_payment(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $supplier = Supplier::create(['name' => 'Versioned']);
        $p = Product::create(['name' => 'Product', 'sku' => 'VER', 'quantity' => 0, 'price' => 5]);
        $payload = ['supplier_id' => $supplier->id, 'ordered_at' => today()->toDateString(), 'currency' => 'EUR', 'status' => 'ordered', 'items' => [['product_id' => $p->id, 'description' => 'Product', 'unit' => 'pcs', 'quantity' => 10, 'unit_price' => 2]]];
        $po = $this->postJson('/api/purchase-orders', $payload)->assertCreated()->json();
        $payload['items'][0]['id'] = $po['items'][0]['id'];
        $payload['revision'] = $po['revision'];
        $payload['notes'] = 'First edit';
        $updated = $this->putJson('/api/purchase-orders/'.$po['id'], $payload)->assertOk()->json();
        $this->assertGreaterThan($po['revision'], $updated['revision']);
        $payload['notes'] = 'Stale edit';
        $this->putJson('/api/purchase-orders/'.$po['id'], $payload)->assertConflict();
        $this->assertSame('First edit', PurchaseOrder::find($po['id'])->notes);
        $payload['revision'] = $updated['revision'];
        $this->postJson('/api/purchase-orders/'.$po['id'].'/payments', ['amount' => 1, 'payment_date' => today()->toDateString(), 'payment_method' => 'cash', 'idempotency_key' => 'version-payment'])->assertCreated();
        $this->putJson('/api/purchase-orders/'.$po['id'], $payload)->assertConflict();
    }

    public function test_database_errors_log_codes_without_sql_bindings_or_payloads(): void
    {
        config(['logging.default' => 'pr2-test', 'logging.channels.pr2-test' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        app('log')->forgetChannel('pr2-test');
        Route::get('/api/pr2-error-contract', function () {
            throw new QueryException('sqlite', 'insert into evidence values (?)', ['PRIVATE_BUSINESS_DATA'.str_repeat('x', 100000)], new \PDOException('Database error'));
        });
        $r = $this->getJson('/api/pr2-error-contract')->assertStatus(500);
        $this->assertStringNotContainsString('PRIVATE_BUSINESS_DATA', $r->getContent());
        $records = Log::channel('pr2-test')->getLogger()->getHandlers()[0]->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('Database request failed.', $records[0]->message);
        $this->assertStringNotContainsString('PRIVATE_BUSINESS_DATA', json_encode($records[0]->context));
        $this->assertArrayHasKey('sql_state', $records[0]->context);
    }

    public function test_exact_entity_resolution_survives_a_large_common_candidate_prefix(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        for ($i = 0; $i < 210; $i++) {
            Product::create(['name' => 'Search Product '.$i, 'sku' => 'SEARCH-A-'.$i, 'quantity' => 0, 'price' => 5]);
        }
        $late = Product::create(['name' => 'Search Product late', 'sku' => 'SEARCH-LATE-999', 'quantity' => 0, 'price' => 5]);
        $r = app(AssistantEntityResolver::class)->resolve('SEARCH-LATE-999','product');
        $this->assertSame($late->id,$r['entity']['id'] ?? null);
    }
}
