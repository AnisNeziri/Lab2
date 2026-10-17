<?php

namespace Tests\Feature;

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\AimsToolRegistry;
use App\Services\AssistantPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningFactsAndClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_stock_uses_available_balances_in_english_albanian_and_mixed_questions(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $product = Product::create($this->tenantAttributes(['name' => 'Reserved fabric', 'sku' => 'FACT-LOW', 'unit' => 'm', 'price' => 5, 'quantity' => 500, 'min_quantity' => 10]));
        $w = Warehouse::create($this->tenantAttributes(['code' => 'FACT', 'name' => 'Facts warehouse', 'is_default' => true]));
        WarehouseStock::create($this->tenantAttributes(['warehouse_id' => $w->id, 'product_id' => $product->id, 'quantity' => 500, 'available_quantity' => 2, 'reserved_quantity' => 498]));
        foreach ([['whats low stock', 'en'], ['cilat produkte kane stok te ulet', 'sq'], ['cilat products low stock', 'sq']] as [$question,$language]) {
            $this->postJson('/api/intelligence-assistant/ask', compact('question', 'language'))->assertOk()->assertJsonPath('intent', 'low_stock')->assertJsonPath('read_only', true)->assertJsonPath('is_prediction', false)->assertJsonPath('cards.0.metrics.0.value', 2)->assertJsonPath('cards.0.metrics.1.value', 10);
        }
        $this->assertSame('stockout', app(AssistantPlanner::class)->plan('what will run out next month')['intent']);
        $this->assertSame('stockout', app(AssistantPlanner::class)->plan('low stock next month')['intent']);
        $this->assertSame(0, StockMovement::count());
        $catalog = collect(app(AimsToolRegistry::class)->catalog())->keyBy('name');
        $this->assertTrue($catalog['get_low_stock_facts']['read_only']);
    }

    public function test_unused_draft_delete_and_submitted_withdraw_preserve_approval_history(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $draft = PurchaseRequest::create($this->tenantAttributes(['request_number' => 'PR-UNUSED', 'status' => 'draft', 'requested_by' => auth()->id(), 'requested_at' => today(), 'estimated_total' => 0, 'currency' => 'EUR']));
        $this->deleteJson('/api/purchase-requests/'.$draft->id)->assertNoContent();
        $this->assertSoftDeleted($draft);
        $request = PurchaseRequest::create($this->tenantAttributes(['request_number' => 'PR-WITHDRAW', 'status' => 'submitted', 'requested_by' => auth()->id(), 'requested_at' => today(), 'estimated_total' => 10, 'currency' => 'EUR']));
        $approval = ApprovalRequest::create($this->tenantAttributes(['entity_type' => 'purchase_request', 'entity_id' => $request->id, 'rule_type' => 'purchase_request', 'requested_by' => auth()->id(), 'requested_at' => now(), 'required_role' => 'admin', 'requested_amount' => 10, 'currency' => 'EUR', 'status' => 'pending']));
        $request->update(['approval_request_id' => $approval->id]);
        $this->deleteJson('/api/purchase-requests/'.$request->id)->assertUnprocessable();
        $this->postJson('/api/purchase-requests/'.$request->id.'/withdraw', ['reason' => 'No longer required by requester'])->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->assertSame('cancelled', $approval->fresh()->status);
        $this->assertSame(1, ApprovalDecision::count());
        $this->postJson('/api/purchase-requests/'.$request->id.'/withdraw', ['reason' => 'No longer required by requester'])->assertOk();
        $this->assertSame(1, ApprovalDecision::count());
        $this->postJson('/api/approvals/'.$approval->id.'/decision', ['decision' => 'approved', 'comment' => 'Too late'])->assertUnprocessable();
        $this->postJson('/api/purchase-requests/'.$request->id.'/rfqs', ['supplier_ids' => []])->assertUnprocessable();
    }

    public function test_rfq_closure_blocks_new_quotes_and_awards_and_preserves_record(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $request = PurchaseRequest::create($this->tenantAttributes(['request_number' => 'PR-CLOSE', 'status' => 'sourcing', 'requested_by' => auth()->id(), 'requested_at' => today(), 'estimated_total' => 10, 'currency' => 'EUR']));
        $rfq = Rfq::create($this->tenantAttributes(['purchase_request_id' => $request->id, 'rfq_number' => 'RFQ-CLOSE', 'status' => 'issued', 'created_by' => auth()->id()]));
        $this->postJson('/api/rfqs/'.$rfq->id.'/close', ['status' => 'closed', 'reason' => 'Sourcing is no longer required'])->assertOk()->assertJsonPath('status', 'closed');
        $this->postJson('/api/rfqs/'.$rfq->id.'/issue')->assertUnprocessable();
        $this->postJson('/api/rfqs/'.$rfq->id.'/award', ['quote_item_ids' => [999]])->assertUnprocessable();
        $this->postJson('/api/purchase-requests/'.$request->id.'/withdraw', ['reason' => 'Sourcing closed without commitment'])->assertOk();
        $this->assertDatabaseHas('rfqs',['id' => $rfq->id, 'status' => 'closed']);
    }
}
