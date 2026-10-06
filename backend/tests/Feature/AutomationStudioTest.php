<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Automation, AutomationExecution, AutomationVersion, OperationalTask, BusinessEvent, Category, Product, User, Company, Customer};
use App\Services\{AutomationService, AutomationConditionEngine, BusinessEventService, AutomationScheduler};
use Illuminate\Support\Facades\{Auth, Queue};

class AutomationStudioTest extends TestCase
{
    use RefreshDatabase;

    private function setupRule(string $trigger='inventory.low_stock',array $actions=[]): array
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $category=Category::create($this->tenantAttributes(['name'=>'Hardware']));
        $p=Product::create($this->tenantAttributes(['name'=>'Handle','sku'=>'AUTO-H','category_id'=>$category->id,'unit'=>'pcs','quantity'=>10,'min_quantity'=>20,'purchase_price'=>2,'selling_price'=>5,'price'=>5]));
        $definition=['name'=>'Review low stock','trigger'=>$trigger,'priority'=>'high','conditions'=>['all'=>[]],'actions'=>$actions ?: [['type'=>'create_task','title'=>'Review replenishment','due_days'=>1],['type'=>'notify','title'=>'Low stock review']]];
        $rule=$this->postJson('/api/automations',$definition)->assertCreated()->assertJsonPath('enabled',false)->json();
        $this->postJson('/api/automations/'.$rule['id'].'/toggle',['enabled'=>true])->assertOk();
        return [$p,Automation::find($rule['id']),$definition];
    }

    private function event(Product $p,string $key='first',array $metadata=[]): BusinessEvent
    {
        return app(BusinessEventService::class)->record('inventory.low_stock',$p,$p->name,$metadata+['available'=>'10.000','minimum'=>'20.000','shortage'=>'10.000'],$key);
    }

    public function test_event_actions_are_idempotent_and_task_outcome_is_audited(): void
    {
        [$p,$rule]=$this->setupRule();
        $event=$this->event($p); app(AutomationService::class)->consume($event); $this->event($p);
        // RefreshDatabase wraps transactions, so explicit consume mirrors the after-commit worker.
        $this->assertDatabaseCount('operational_tasks',1); $this->assertDatabaseCount('automation_executions',1);
        $this->assertDatabaseHas('automation_executions',['status'=>'succeeded']);
        $this->assertDatabaseHas('notifications',['type'=>'automation']);
        $task=OperationalTask::first();
        $this->getJson('/api/action-center')->assertOk()->assertJsonPath('tasks.data.0.url','/products?product='.$p->id);
        $this->patchJson('/api/operational-tasks/'.$task->id,['status'=>'completed','outcome_note'=>'Reviewed stock'])->assertOk()->assertJsonPath('outcome.note','Reviewed stock');
        $this->assertDatabaseHas('audit_logs',['action'=>'task.completed']);
        $this->assertEquals(10,$p->fresh()->quantity);
        $this->assertDatabaseCount('daily_sales',0); $this->assertDatabaseCount('journal_entries',0);
    }

    public function test_conditions_and_read_only_simulation_version_history(): void
    {
        [$p,$rule,$definition]=$this->setupRule();
        Queue::fake([\App\Jobs\RunBusinessAutomations::class]);
        $event=$this->event($p);
        $this->postJson('/api/automations/'.$rule->id.'/simulate',[])->assertOk()->assertJsonPath('matched',1);
        $this->assertDatabaseCount('operational_tasks',0);
        $definition['conditions']=['all'=>[['field'=>'available','operator'=>'gt','value'=>100]]];
        $this->putJson('/api/automations/'.$rule->id,$definition)->assertOk()->assertJsonPath('enabled',false)->assertJsonPath('version',2);
        $this->assertDatabaseCount('automation_versions',2);
        app(AutomationService::class)->run($rule->fresh(),$event);
        $this->assertDatabaseHas('automation_executions',['status'=>'skipped','version'=>2]);
        $this->assertDatabaseCount('operational_tasks',0);
    }

    public function test_safe_nested_condition_comparisons_and_missing_fields(): void
    {
        $engine=app(AutomationConditionEngine::class);
        $tree=['all'=>[['field'=>'amount','operator'=>'gte','value'=>'0.30'],['any'=>[['field'=>'status','operator'=>'equals','value'=>'paid'],['field'=>'status','operator'=>'in','value'=>['ready','packed']]]]]];
        $engine->validate($tree,['amount','status']);
        $this->assertTrue($engine->evaluate($tree,['amount'=>'0.300','status'=>'paid'])['matched']);
        $this->assertFalse($engine->evaluate($tree,['status'=>'paid'])['matched']);
        foreach (['equals','not_equals','gt','gte','lt','lte','contains','not_contains','in','not_in','empty','not_empty','changed_from','changed_to'] as $op) {
            $value=in_array($op,['in','not_in'])?['new']:'new';
            $r=$engine->evaluate(['field'=>'state','operator'=>$op,'value'=>$value],['state'=>'new','previous.state'=>'old']);
            $this->assertIsBool($r['matched']);
        }
        $this->assertTrue($engine->evaluate(['field'=>'state','operator'=>'changed_from','value'=>'old'],['state'=>'new','previous.state'=>'old'])['matched']);
    }

    public function test_permissions_tenant_isolation_and_dangerous_actions_blocked(): void
    {
        [$p,$rule,$definition]=$this->setupRule();
        $definition['actions']=[['type'=>'approve_po','title'=>'Bad']];
        $this->postJson('/api/automations',$definition)->assertUnprocessable();
        $definition['actions']=[['type'=>'create_task','title'=>'Task','assigned_user_id'=>User::factory()->create(['company_id'=>Company::factory()->create()->id])->id]];
        $this->postJson('/api/automations',$definition)->assertUnprocessable();
        $this->actingAsApiUser('staff');
        $this->getJson('/api/automations')->assertForbidden();
        $this->postJson('/api/automations',$definition)->assertForbidden();
        $this->getJson('/api/action-center')->assertOk()->assertJsonPath('tasks.total',0);
        $other=User::factory()->create(['company_id'=>Company::factory()->create()->id,'role'=>'admin','api_token'=>hash('sha256','other-admin')]);
        $this->withHeader('Authorization','Bearer other-admin')->getJson('/api/automations/'.$rule->id)->assertNotFound();
    }

    public function test_creator_permission_revocation_and_loop_protection(): void
    {
        [$p,$rule]=$this->setupRule();
        $event=$this->event($p,'loop',['automation_depth'=>4,'automation_correlation'=>'test-chain']);
        app(AutomationService::class)->consume($event);
        $this->assertDatabaseHas('automation_executions',['status'=>'blocked','correlation_id'=>'test-chain']);
        $this->assertDatabaseCount('operational_tasks',0);
        $user=Auth::user();$user->update(['role'=>'staff']);
        $event=$this->event($p,'revoked');app(AutomationService::class)->consume($event);
        $this->assertDatabaseHas('automation_executions',['error'=>'Creator permission was revoked.']);
        $this->assertDatabaseCount('operational_tasks',0);
    }

    public function test_failed_action_rolls_back_side_effects_preserves_origin_and_retry_is_safe(): void
    {
        [$p,$rule]=$this->setupRule();
        Queue::fake([\App\Jobs\RunBusinessAutomations::class]);
        $event=$this->event($p);
        $this->mock(\App\Services\NotificationService::class,function($mock){$mock->shouldReceive('createAutomationNotice')->once()->andThrow(new \RuntimeException('Temporary outage'));});
        app(AutomationService::class)->consume($event);
        $this->assertDatabaseHas('business_events',['id'=>$event->id]);
        $this->assertDatabaseHas('automation_executions',['status'=>'failed']);
        $this->assertDatabaseCount('operational_tasks',0);
        $this->app->forgetInstance(\App\Services\NotificationService::class);
        $run=AutomationExecution::first();
        $this->postJson('/api/automation-executions/'.$run->id.'/retry')->assertOk()->assertJsonPath('status','succeeded');
        $this->assertDatabaseCount('operational_tasks',1);
        $this->postJson('/api/automation-executions/'.$run->id.'/retry')->assertUnprocessable();
    }

    public function test_scheduler_deduplicates_unresolved_tasks_and_records_draft_without_approval(): void
    {
        [$p,$rule]=$this->setupRule('inventory.low_stock',[['type'=>'purchase_request_draft','title'=>'Restock handles','quantity'=>'10']]);
        app(AutomationScheduler::class)->tick(); app(AutomationScheduler::class)->tick();
        $this->assertSame('succeeded',AutomationExecution::first()?->status,AutomationExecution::first()?->toJson() ?? 'No execution: '.BusinessEvent::all()->toJson());
        $this->assertDatabaseCount('purchase_requests',1);
        $this->assertDatabaseHas('purchase_requests',['status'=>'draft']);
        $this->assertDatabaseCount('purchase_orders',0);
        $this->assertDatabaseCount('approval_decisions',0);
        $this->assertDatabaseCount('operational_tasks',1);
    }

    public function test_encrypted_backup_restores_rules_disabled_and_never_replays_history(): void
    {
        [$p,$rule]=$this->setupRule();$this->event($p);
        $backup=$this->postJson('/api/backup/export',['modules'=>['automations'],'passphrase'=>'automation-backup-passphrase'])->assertOk()->getContent();
        $this->assertStringNotContainsString('Review low stock',$backup);
        $company=Company::factory()->create();
        $user=User::factory()->create(['company_id'=>$company->id,'role'=>'admin','api_token'=>hash('sha256','restore-auto')]);
        $this->withHeader('Authorization','Bearer restore-auto');
        $this->post('/api/backup/import',['file'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('auto.aimsbackup',$backup),'passphrase'=>'automation-backup-passphrase','mode'=>'merge'])->assertOk();
        $restored=Automation::where('company_id',$company->id)->firstOrFail();
        $this->assertFalse($restored->enabled);
        $this->assertEquals(1,AutomationVersion::where('automation_id',$restored->id)->count());
        app(AutomationScheduler::class)->tick();
        $this->assertEquals(1,OperationalTask::where('company_id',$company->id)->count());
    }

    public function test_customer_overdue_document_expiry_and_delayed_shipment_use_real_sources(): void
    {
        [$p,$rule,$definition]=$this->setupRule();
        $c=Customer::create($this->tenantAttributes(['name'=>'Buyer','current_debt'=>50,'current_credit'=>0,'is_active'=>true]));
        $c->debtTransactions()->create($this->tenantAttributes(['type'=>'debt_added','amount'=>50,'balance_before'=>0,'balance_after'=>50,'credit_before'=>0,'credit_after'=>0,'source'=>'manual','transaction_date'=>today()->subDays(20),'due_date'=>today()->subDay(),'user_id'=>Auth::id()]));
        $this->getJson('/api/documents/config')->assertOk();
        $document=$this->post('/api/documents',['title'=>'Certificate','document_type_id'=>\App\Models\DocumentType::first()->id,'expiry_date'=>today()->addDays(20)->toDateString(),'file'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('cert.txt','Expiry evidence')],['Accept'=>'application/json'])->assertCreated()->json();
        $ship=\App\Models\Shipment::create($this->tenantAttributes(['tracking_number'=>'AUTO-SHIP','vessel_name'=>'Real record','transport_mode'=>'sea','tracking_provider'=>'aisstream','status'=>'in_transit','eta'=>now()->subDay()]));
        foreach(['customer.payment_overdue','document.expiring','shipment.delayed'] as $trigger) {
            $d=$definition;$d['name']=$trigger;$d['trigger']=$trigger;
            $r=$this->postJson('/api/automations',$d)->assertCreated()->json();
            $this->postJson('/api/automations/'.$r['id'].'/toggle',['enabled'=>true])->assertOk();
        }
        app(AutomationScheduler::class)->tick();app(AutomationScheduler::class)->tick();
        foreach(['Customer','Document','Shipment'] as $type) $this->assertEquals(1,OperationalTask::where('source_type',$type)->count(),$type.' tasks: '.AutomationExecution::all()->toJson());
        $this->assertSame('in_transit',$ship->fresh()->status);
        $this->assertEquals('50.00',$c->fresh()->current_debt);
        \App\Models\Document::find($document['id'])->update(['expiry_date'=>today()->addYear()]);
        app(AutomationScheduler::class)->tick();
        $this->assertDatabaseHas('operational_tasks',['source_type'=>'Document','source_id'=>$document['id'],'status'=>'completed']);
    }

    public function test_document_approval_action_reuses_existing_engine_and_self_approval_is_rejected(): void
    {
        [$p,$rule,$d]=$this->setupRule();
        $d['name']='Review document';$d['trigger']='document.expiring';$d['actions']=[['type'=>'request_document_review','title'=>'Review certificate']];
        $a=$this->postJson('/api/automations',$d)->assertCreated()->json();
        $this->postJson('/api/automations/'.$a['id'].'/toggle',['enabled'=>true])->assertOk();
        $this->getJson('/api/documents/config')->assertOk();
        $doc=$this->post('/api/documents',['title'=>'Approval certificate','document_type_id'=>\App\Models\DocumentType::first()->id,'expiry_date'=>today()->addDay()->toDateString(),'file'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('approval.txt','Approval evidence')],['Accept'=>'application/json'])->assertCreated()->json();
        app(AutomationScheduler::class)->tick();app(AutomationScheduler::class)->tick();
        $this->assertDatabaseCount('approval_requests',1);
        $approval=\App\Models\ApprovalRequest::firstOrFail();
        $this->assertSame('document_version',$approval->entity_type);
        $this->assertSame('pending',$approval->status);
        $this->assertDatabaseHas('documents',['id'=>$doc['id'],'status'=>'under_review']);
        $this->getJson('/api/action-center')->assertOk();
        try { app(\App\Services\ApprovalService::class)->decide($approval,'approved',null); $this->fail('Self approval was accepted.'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('own request',json_encode($e->errors())); }
        $event=BusinessEvent::where('event_type','document.review_requested')->firstOrFail();
        $this->assertSame(1,$event->metadata['automation_depth']);
        $this->assertNotEmpty($event->metadata['automation_correlation']);
    }

    public function test_scheduled_time_window_and_immutable_versions(): void
    {
        [$p,$rule,$d]=$this->setupRule();
        $d['schedule']=['frequency'=>'daily','time'=>'08:00'];
        $this->putJson('/api/automations/'.$rule->id,$d)->assertOk();
        $this->postJson('/api/automations/'.$rule->id.'/toggle',['enabled'=>true])->assertOk();
        $this->travelTo(today()->setTime(7,0));app(AutomationScheduler::class)->tick();
        $this->assertDatabaseCount('operational_tasks',0);
        $this->travelTo(today()->setTime(8,0));app(AutomationScheduler::class)->tick();
        $this->assertDatabaseCount('operational_tasks',1);
        $manual=$this->postJson('/api/operational-tasks',['title'=>'Overdue manual review','due_at'=>now()->subDays(3)->toIso8601String()])->assertCreated()->json();
        $escalation=$this->postJson('/api/automations',[
            'name'=>'Escalate late task','trigger'=>'task.overdue','priority'=>'high','conditions'=>['all'=>[]],
            'actions'=>[['type'=>'create_task','title'=>'Manager review','assigned_role'=>'admin']],
        ])->assertCreated()->json();
        $this->postJson('/api/automations/'.$escalation['id'].'/toggle',['enabled'=>true])->assertOk();
        app(AutomationScheduler::class)->tick();app(AutomationScheduler::class)->tick();
        $this->assertSame(1,OperationalTask::where('source_type','OperationalTask')->where('source_id',$manual['id'])->count());
        $this->assertNotNull(OperationalTask::find($manual['id'])->escalated_at);
        $this->expectException(\LogicException::class);
        AutomationVersion::first()->update(['definition'=>[]]);
    }

    public function test_confirmed_order_creates_one_task_without_issuing_stock_or_posting_finance(): void
    {
        [$product,$rule]=$this->setupRule('sales_order.confirmed', [['type'=>'create_task','title'=>'Review fulfillment']]);
        $customer=Customer::create($this->tenantAttributes(['name'=>'Cash buyer','current_debt'=>0,'current_credit'=>0]));
        $order=$this->postJson('/api/sales-orders',[
            'customer_id'=>$customer->id,'order_date'=>today()->toDateString(),'payment_type'=>'cash',
            'idempotency_key'=>(string)\Illuminate\Support\Str::uuid(),
            'items'=>[['product_id'=>$product->id,'quantity'=>2,'unit'=>'pcs','unit_price'=>5]],
        ])->assertCreated()->json();
        $key=(string)\Illuminate\Support\Str::uuid();
        $this->postJson('/api/sales-orders/'.$order['id'].'/confirm',['idempotency_key'=>$key])->assertOk();
        $event=BusinessEvent::where('event_type','sales_order.confirmed')->firstOrFail();
        app(AutomationService::class)->consume($event);
        app(AutomationService::class)->consume($event);
        $this->assertDatabaseCount('operational_tasks',1);
        $this->getJson('/api/action-center')->assertOk()->assertJsonPath('tasks.data.0.url','/fulfillment?order='.$order['id']);
        $this->assertEquals(10,$product->fresh()->quantity);
        $this->assertDatabaseCount('daily_sales',0);
        $this->assertDatabaseCount('journal_entries',0);
        $this->assertEquals('0.00',$customer->fresh()->current_debt);
    }
}
