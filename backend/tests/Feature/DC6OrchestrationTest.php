<?php

namespace Tests\Feature;

use App\Jobs\RunBusinessAutomations;
use App\Models\AnalyticsSnapshot;
use App\Models\ApprovalRule;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\BusinessEvent;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OperationalTask;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AutomationScheduler;
use App\Services\AutomationService;
use App\Services\InventorySnapshotService;
use App\Services\NotificationService;
use App\Services\OperatingAssistantFacts;
use App\Services\StockMovementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DC6OrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        Queue::fake([RunBusinessAutomations::class]);
        $c = Category::create($this->tenantAttributes(['name' => 'DC6 Fabric']));
        $p = Product::create($this->tenantAttributes(['name' => 'Milano 04', 'sku' => 'DC6-M04', 'category_id' => $c->id, 'unit' => 'm', 'quantity' => 0, 'min_quantity' => 2000, 'purchase_price' => 2, 'price' => 5]));
        $p->forceFill(['created_at' => now()->subYear()])->save();
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'DC6 Textiles', 'is_active' => true]));
        ProductSupplier::create($this->tenantAttributes(['product_id' => $p->id, 'supplier_id' => $supplier->id, 'purchase_price' => 2, 'currency' => 'EUR', 'exchange_rate_to_base' => 1, 'usual_lead_time_days' => 3, 'pack_size' => 50, 'minimum_order_quantity' => 100, 'is_active' => true]));
        for ($d = 1; $d <= 90; $d++) {
            AnalyticsSnapshot::create($this->tenantAttributes(['entity_type' => 'demand_observation', 'entity_id' => $p->id, 'warehouse_id' => 0, 'snapshot_date' => today()->subDays($d), 'observed_at' => today()->subDays($d)->endOfDay(), 'feature_version' => 'observed-v2', 'facts' => ['unit' => 'm', 'demand' => 100, 'sales_quantity' => 100, 'returns_quantity' => 0, 'available' => 5000, 'stockout' => false, 'quality' => 'observed', 'complete' => true, 'potentially_censored' => false]]));
        }
        $this->stock($p, 5000);

        return $p;
    }

    private function stock(Product $p, float $quantity): void
    {
        app(StockMovementService::class)->store(['product_id' => $p->id, 'type' => $quantity > 0 ? 'in' : 'out', 'quantity' => abs($quantity), 'stock_state' => 'available',
            'movement_code' => $quantity > 0 ? 'import_adjustment_in' : 'import_adjustment_out', 'reason' => 'Synthetic DC6 stock evidence', 'idempotency_key' => (string) Str::uuid()]);
    }

    private function rule(string $trigger = 'inventory.low_stock', string $team = 'purchasing'): Automation
    {
        $r = $this->postJson('/api/automations', ['name' => 'DC6 review', 'trigger' => $trigger, 'priority' => 'high', 'conditions' => ['all' => []],
            'actions' => [['type' => 'create_task', 'title' => 'Review source and evidence', 'assigned_team' => $team, 'due_days' => 1, 'escalate_after_days' => 0]]])->assertCreated()->json();
        $this->postJson('/api/automations/'.$r['id'].'/toggle', ['enabled' => true])->assertOk();

        return Automation::findOrFail($r['id']);
    }

    private function lowEvent(Product $p): BusinessEvent
    {
        // RefreshDatabase defers after-commit callbacks. Invoke the established
        // committed stock-event adapter, which reads authoritative availability.
        app(NotificationService::class)->createLowStockAlert($p->fresh());

        return BusinessEvent::where('event_type', 'inventory.low_stock')->latest('id')->firstOrFail();
    }

    public function test_inventory_event_claim_stale_review_draft_approval_receipt_recovery_and_recurrence(): void
    {
        $p = $this->product();
        $rule = $this->rule();
        $this->stock($p, -3550);
        $event = $this->lowEvent($p);
        app(AutomationService::class)->consume($event);
        app(AutomationService::class)->consume($event);
        $this->assertDatabaseCount('operational_tasks', 1);
        $task = OperationalTask::firstOrFail();
        $this->assertSame('purchasing', $task->assigned_team);
        $this->postJson('/api/operational-tasks/'.$task->id.'/claim')->assertOk()->assertJsonPath('assigned_user_id', Auth::id());
        $this->getJson('/api/operational-tasks/'.$task->id.'/review')->assertOk()->assertJsonPath('current_evidence.available', 1450)->assertJsonPath('can_prepare', true);
        $preview = $this->postJson('/api/operational-tasks/'.$task->id.'/prepare')->assertOk()->assertJsonPath('confirmation_required', true)->json('review');
        $this->assertDatabaseCount('purchase_requests', 0);
        $this->stock($p, 100);
        $this->postJson('/api/operational-tasks/'.$task->id.'/confirm-draft', ['confirmation_id' => $preview['confirmation_id'], 'confirm' => true])->assertStatus(409)->assertJsonPath('stale', true);
        $this->assertDatabaseCount('purchase_requests', 0);
        $preview = $this->postJson('/api/operational-tasks/'.$task->id.'/prepare')->assertOk()->json('review');
        $confirm = ['confirmation_id' => $preview['confirmation_id'], 'confirm' => true];
        $draft = $this->postJson('/api/operational-tasks/'.$task->id.'/confirm-draft', $confirm)->assertOk()->json('draft');
        $this->postJson('/api/operational-tasks/'.$task->id.'/confirm-draft', $confirm)->assertOk()->assertJsonPath('reused', true);
        $this->assertDatabaseCount('purchase_requests', 1);
        $this->assertDatabaseCount('purchase_orders', 0);
        $pr = PurchaseRequest::findOrFail($draft['id']);
        $this->assertSame('draft', $pr->status);
        ApprovalRule::create($this->tenantAttributes(['rule_type' => 'purchase_request', 'threshold_amount' => 0, 'currency' => 'EUR', 'required_role' => 'manager', 'separation_of_duties' => true, 'is_active' => true]));
        $this->postJson('/api/purchase-requests/'.$pr->id.'/submit')->assertOk()->assertJsonPath('status', 'submitted');
        $approval = $pr->fresh()->approval_request_id;
        $this->postJson('/api/approvals/'.$approval.'/decision', ['decision' => 'approved'])->assertUnprocessable();
        User::factory()->create(['company_id' => $this->apiCompany->id, 'role' => 'manager', 'email_verified_at' => now(), 'api_token' => hash('sha256', 'dc6-approver')]);
        $this->withToken('dc6-approver')->postJson('/api/approvals/'.$approval.'/decision', ['decision' => 'approved', 'comment' => 'Reviewed stock and supplier evidence'])->assertOk();
        $supplier = ProductSupplier::where('product_id', $p->id)->value('supplier_id');
        $rfq = $this->postJson('/api/purchase-requests/'.$pr->id.'/rfqs', ['supplier_ids' => [$supplier]])->assertCreated()->json();
        $item = $pr->items()->first();
        $quote = $this->postJson('/api/rfqs/'.$rfq['id'].'/quotes', ['supplier_id' => $supplier, 'currency' => 'EUR', 'exchange_rate' => 1, 'items' => [['purchase_request_item_id' => $item->id, 'offered_quantity' => $item->quantity, 'unit_price' => 2, 'minimum_order_quantity' => 50, 'lead_time_days' => 3]]])->assertCreated()->json();
        $this->postJson('/api/rfqs/'.$rfq['id'].'/award', ['quote_item_ids' => [$quote['items'][0]['id']]])->assertOk();
        $poId = $this->postJson('/api/rfqs/'.$rfq['id'].'/convert', ['idempotency_key' => (string) Str::uuid()])->assertOk()->json('purchase_order_ids.0');
        $this->patchJson('/api/purchase-orders/'.$poId.'/status', ['status' => 'ordered'])->assertOk();
        $po = $this->getJson('/api/purchase-orders/'.$poId)->assertOk()->json();
        $this->postJson('/api/purchase-orders/'.$poId.'/receive', ['warehouse_id' => $p->fresh()->default_warehouse_id, 'idempotency_key' => (string) Str::uuid(),
            'items' => [['id' => $po['items'][0]['id'], 'accepted_quantity' => $po['items'][0]['quantity']]]])->assertOk();
        // Consume the durable receipt event as the worker does after commit.
        app(AutomationService::class)->consume(BusinessEvent::where('event_type', 'inventory.received')->latest('id')->firstOrFail());
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNull($task->fresh()->dedupe_key);
        $this->assertEquals($pr->id, $task->fresh()->outcome['draft']['id']);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'OperationalTask', 'entity_id' => $task->id, 'action' => 'task.source_resolved']);
        $available = app(InventorySnapshotService::class)->forProduct($p->fresh())['available'];
        $this->stock($p, -($available - 1450));
        app(AutomationScheduler::class)->tick();
        app(AutomationScheduler::class)->tick();
        $this->assertDatabaseCount('operational_tasks', 2);
        $this->assertDatabaseCount('purchase_requests', 1);
    }

    public function test_collection_promise_escalates_same_issue_and_payment_resolves_it(): void
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $customer = Customer::create($this->tenantAttributes(['name' => 'DC6 Furniture', 'current_debt' => 0, 'current_credit' => 0, 'is_active' => true]));
        $this->postJson('/api/customers/'.$customer->id.'/debts', ['amount' => 5000, 'transaction_date' => today()->subDays(40)->toDateString(), 'due_date' => today()->subDays(31)->toDateString(), 'note' => 'Synthetic customer obligation', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $rule = $this->rule('customer.payment_overdue', 'sales');
        app(AutomationScheduler::class)->tick();
        app(AutomationScheduler::class)->tick();
        $task = OperationalTask::where('source_type', 'Customer')->firstOrFail();
        $this->assertDatabaseCount('operational_tasks', 1);
        $this->postJson('/api/operational-tasks/'.$task->id.'/claim')->assertOk();
        $this->postJson('/api/operational-tasks/'.$task->id.'/promise', ['due_on' => today()->addDay()->toDateString(), 'amount' => 5000, 'note' => 'Customer promised full settlement'])->assertOk();
        $this->travel(2)->days();
        app(AutomationScheduler::class)->tick();
        app(AutomationScheduler::class)->tick();
        $this->assertDatabaseCount('operational_tasks', 1);
        $this->assertSame('urgent', $task->fresh()->priority);
        $this->assertNotNull($task->fresh()->escalated_at);
        $answer = app(OperatingAssistantFacts::class)->reply(['question' => 'Which customer followups are overdue?', 'language' => 'en'], (string) Str::uuid());
        $this->assertTrue($answer['read_only']);
        $this->assertCount(1, $answer['cards']);
        $this->assertSame('get_operating_brief', $answer['cards'][0]['tool']);
        // A fulfilled partial promise must not escalate merely because other AR remains.
        $this->postJson('/api/operational-tasks/'.$task->id.'/promise', ['due_on' => today()->addDay()->toDateString(), 'amount' => 700, 'note' => 'Customer now promises a partial payment'])->assertOk();
        $this->postJson('/api/customers/'.$customer->id.'/payments', ['amount' => 700, 'payment_method' => 'cash', 'transaction_date' => today()->toDateString(), 'note' => 'Received partial promise', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->travel(2)->days();
        app(AutomationScheduler::class)->tick();
        $this->assertNull($task->fresh()->escalated_at);
        $this->assertTrue($task->fresh()->outcome['collection']['promise_progress']['fulfilled']);
        $this->assertDatabaseCount('operational_tasks', 1);
        $this->postJson('/api/customers/'.$customer->id.'/payments', ['amount' => 4300, 'payment_method' => 'cash', 'transaction_date' => today()->toDateString(), 'note' => 'Received remaining payment', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        app(AutomationService::class)->consume(BusinessEvent::where('event_type', 'customer.payment_received')->latest('id')->firstOrFail());
        $this->assertEquals(0, $customer->fresh()->current_debt);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNull($task->fresh()->dedupe_key);
        $this->assertNotNull($task->fresh()->outcome['collection']['promise']);
        $this->assertDatabaseCount('operational_tasks', 1);
        $this->travelBack();
    }

    public function test_company_pause_current_state_queued_resume_and_tenant_boundaries(): void
    {
        $p = $this->product();
        $rule = $this->rule();
        $this->stock($p, -3550);
        $event = $this->lowEvent($p);
        $pause = ['paused' => true, 'paused_categories' => [], 'reason' => 'Controlled maintenance pause'];
        $this->postJson('/api/automation-controls', $pause)->assertOk();
        app(AutomationService::class)->consume($event);
        $this->assertDatabaseCount('operational_tasks', 0);
        $this->stock($p, 100); // Manual authority still operates while automation is paused.
        $this->assertEquals(1550, app(InventorySnapshotService::class)->forProduct($p->fresh())['available']);
        $this->postJson('/api/automation-controls', [...$pause, 'paused' => false])->assertOk();
        // A durable queued execution left by a stopped worker is resumable.
        AutomationExecution::create($this->tenantAttributes(['automation_id' => $rule->id, 'version' => $rule->version, 'business_event_id' => $event->id,
            'execution_key' => hash('sha256', $rule->id.':'.$rule->version.':'.$event->id), 'status' => 'queued', 'context' => [], 'correlation_id' => $event->event_id]));
        app(AutomationService::class)->consume($event);
        app(AutomationService::class)->consume($event);
        $this->assertDatabaseCount('operational_tasks', 1);
        $task = OperationalTask::first();
        $this->assertEquals(1550, $task->outcome['automation']['latest_evidence']['available']);
        $this->getJson('/api/automation-health')->assertOk()->assertJsonPath('manual_operations_available', true);
        $other = Company::factory()->create();
        User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'email_verified_at' => now(), 'api_token' => hash('sha256', 'dc6-other')]);
        $this->withToken('dc6-other')->getJson('/api/operational-tasks/'.$task->id.'/review')->assertNotFound();
        $this->postJson('/api/operational-tasks/'.$task->id.'/claim')->assertNotFound();
        $this->postJson('/api/operational-tasks/'.$task->id.'/confirm-draft', ['confirmation_id' => (string) Str::uuid(), 'confirm' => true])->assertNotFound();
        $this->getJson('/api/automation-health')->assertOk()->assertJsonPath('active_rules', 0)->assertJsonPath('queue.pending', 0);
    }

    public function test_high_risk_actions_are_unregistered_and_disabled_work_cannot_execute(): void
    {
        $p = $this->product();
        $rule = $this->rule();
        $this->stock($p, -3550);
        $event = $this->lowEvent($p);
        foreach (['post_journal', 'pay_supplier', 'post_payroll', 'fiscalize', 'adjust_stock', 'execute_class'] as $action) {
            $this->postJson('/api/automations', ['name' => 'Unsafe', 'trigger' => 'inventory.low_stock', 'priority' => 'high', 'conditions' => ['all' => []], 'actions' => [['type' => $action, 'title' => 'Reject unsafe action']]])->assertUnprocessable();
        }
        $this->postJson('/api/automations/'.$rule->id.'/toggle', ['enabled' => false])->assertOk();
        app(AutomationService::class)->run($rule, $event);
        $this->assertDatabaseCount('operational_tasks', 0);
        $this->assertDatabaseHas('automation_executions', ['status' => 'blocked', 'failure_class' => 'paused_or_changed']);
        $this->assertDatabaseCount('purchase_requests', 0);
        $this->assertDatabaseCount('payroll_runs', 0);
        $this->assertDatabaseCount('fiscal_receipts', 0);
    }

    public function test_unknown_database_outcome_blocks_replay_until_human_verification(): void
    {
        $p = $this->product();
        $rule = $this->rule();
        $definition = $rule->only(['name', 'description', 'trigger', 'conditions', 'actions', 'priority', 'schedule']);
        $definition['actions'][] = ['type' => 'notify', 'title' => 'Review notice'];
        $rule = app(AutomationService::class)->save($definition, $rule);
        app(AutomationService::class)->toggle($rule, true);
        $this->stock($p, -3550);
        $event = $this->lowEvent($p);
        $previous = new \PDOException('Synthetic connection lost');
        $previous->errorInfo = ['HY000', 2013, 'Synthetic connection lost'];
        $error = new QueryException('mysql', 'synthetic', [], $previous);
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('createAutomationNotice')->once()->andThrow($error));
        app(AutomationService::class)->consume($event);
        $execution = AutomationExecution::where('automation_id', $rule->id)->firstOrFail();
        $this->assertSame('needs_verification', $execution->status);
        $this->assertNull($execution->next_retry_at);
        $this->assertDatabaseCount('operational_tasks', 0);
        $this->postJson('/api/automation-executions/'.$execution->id.'/retry')->assertUnprocessable();
        $this->postJson('/api/automation-executions/'.$execution->id.'/verification', ['outcome' => 'no_effect', 'reason' => 'Checked task and notification history; no effect was committed.'])->assertOk()->assertJsonPath('failure_class', 'human_verified');
        $this->app->forgetInstance(NotificationService::class);
        $this->postJson('/api/automation-executions/'.$execution->id.'/retry')->assertOk()->assertJsonPath('status', 'succeeded');
        $this->postJson('/api/automation-executions/'.$execution->id.'/retry')->assertUnprocessable();
        $this->assertDatabaseCount('operational_tasks', 1);
    }
}
