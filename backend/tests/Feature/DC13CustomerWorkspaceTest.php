<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Category,Customer,Product,OrderChannel,SalesOrder,CustomerQuotation,CustomerDebtTransaction,JournalEntry,Company,User};
use Illuminate\Support\Facades\DB;

class DC13CustomerWorkspaceTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array
    {
        $this->actingAsApiUser();$user=User::where('company_id',$this->apiCompany->id)->first();
        $category=Category::create($this->tenantAttributes(['name'=>'Wholesale']));
        $warehouse=$this->postJson('/api/warehouses',['name'=>'CRM warehouse','code'=>'CRM','is_default'=>true])->assertCreated()->json();
        $product=$this->postJson('/api/products',['name'=>'Milano 04','sku'=>'CRM-M04','category_id'=>$category->id,'default_warehouse_id'=>$warehouse['id'],'unit'=>'pcs','quantity'=>50,'min_quantity'=>1,'purchase_price'=>2,'selling_price'=>10,'price'=>10])->assertCreated()->json();
        $customer=Customer::create($this->tenantAttributes(['name'=>'ABC Furniture','business_name'=>'ABC Furniture LLC','is_active'=>true,'account_owner_id'=>$user->id,'current_debt'=>0,'current_credit'=>0,'credit_limit'=>15]));
        $channel=OrderChannel::create($this->tenantAttributes(['name'=>'Manual','type'=>'manual','enabled'=>true,'currency'=>'EUR','allow_guest'=>false,'acceptance'=>'review','oversale_policy'=>'accept_backorder','configuration'=>['price_rules'=>[['name'=>'Customer volume price','enabled'=>true,'kind'=>'percentage','value'=>10,'customer_id'=>$customer->id,'minimum_quantity'=>2]]]]));
        $payload=['customer_id'=>$customer->id,'order_channel_id'=>$channel->id,'quote_date'=>today()->toDateString(),'expires_on'=>today()->addDays(7)->toDateString(),'payment_type'=>'credit','idempotency_key'=>'quote-first','items'=>[['product_id'=>$product['id'],'quantity'=>2]]];
        return [$customer,$product,$channel,$payload];
    }
    public function test_quotation_conversion_retries_reuse_pricing_and_credit_without_stock_or_money_effects(): void
    {
        [$c,$p,$channel,$payload]=$this->fixture();$before=[DB::table('stock_movements')->count(),JournalEntry::count(),Product::find($p['id'])->quantity];
        $quote=$this->postJson('/api/customer-workspace/quotations',$payload)->assertCreated()->assertJsonPath('total_amount','18.00')->assertJsonPath('items.0.pricing_snapshot.rule.name','Customer volume price')->json();
        $this->postJson('/api/customer-workspace/quotations',[...$payload,'idempotency_key'=>'price-injection','items'=>[['product_id'=>$p['id'],'quantity'=>2,'unit_price'=>'0.01']]])->assertUnprocessable();
        $this->postJson('/api/customer-workspace/quotations',$payload)->assertCreated()->assertJsonPath('id',$quote['id']);
        $this->assertEquals($before,[DB::table('stock_movements')->count(),JournalEntry::count(),Product::find($p['id'])->quantity]);
        $path='/api/customer-workspace/quotations/'.$quote['id'];$this->postJson($path.'/convert')->assertUnprocessable();
        $edit=$payload+['expected_revision'=>$quote['edit_revision']];$edit['notes']='Reviewed delivery terms';
        $this->putJson($path,$edit)->assertOk()->assertJsonPath('notes','Reviewed delivery terms');
        $this->putJson($path,$edit)->assertConflict();$this->assertDatabaseCount('customer_quotation_items',1);
        $this->postJson($path.'/send')->assertOk();$this->postJson($path.'/accept')->assertOk();
        $this->putJson($path,$edit)->assertUnprocessable();
        $converted=$this->postJson($path.'/convert')->assertOk()->assertJsonPath('effective_status','converted')->json();$this->postJson($path.'/convert')->assertOk()->assertJsonPath('converted_order_id',$converted['converted_order_id']);
        $this->assertArrayNotHasKey('customer',$converted); // Never serialize a lazily loaded financial customer model.
        $this->assertEquals(1,SalesOrder::count());$this->assertEquals('18.00',SalesOrder::first()->total_amount);$this->assertSame($quote['id'],SalesOrder::first()->metadata['quotation_id']);
        $intake=\App\Models\OrderIntake::where('sales_order_id',$converted['converted_order_id'])->first();
        $this->postJson('/api/order-hub/intakes/'.$intake->id.'/confirm',['idempotency_key'=>'credit-block'])->assertUnprocessable();
        $this->assertEquals($before,[DB::table('stock_movements')->count(),JournalEntry::count(),Product::find($p['id'])->quantity]);
        $this->assertEquals(0,CustomerDebtTransaction::count());$this->assertNull(SalesOrder::first()->confirmed_at);
        $this->get('/api/customer-workspace/quotations/'.$quote['id'].'/pdf')->assertOk()->assertHeader('Content-Type','application/pdf');
    }
    public function test_stale_or_expired_quotes_do_not_create_orders_and_request_keys_reject_changed_intent(): void
    {
        [$c,$p,$channel,$payload]=$this->fixture();$q=$this->postJson('/api/customer-workspace/quotations',$payload)->assertCreated()->json();
        $this->postJson('/api/customer-workspace/quotations',[...$payload,'notes'=>'Changed'])->assertUnprocessable();
        $path='/api/customer-workspace/quotations/'.$q['id'];$this->postJson($path.'/send')->assertOk();$this->postJson($path.'/accept')->assertOk();
        Product::find($p['id'])->update(['selling_price'=>20]);$this->postJson($path.'/convert')->assertUnprocessable();$this->assertEquals(0,SalesOrder::count());
        $model=CustomerQuotation::find($q['id']);$model->update(['expires_on'=>today()->subDay()]);$this->getJson($path)->assertOk()->assertJsonPath('effective_status','expired');$this->postJson($path.'/convert')->assertUnprocessable();
    }
    public function test_customer_projection_reuses_finance_and_relationships_followups_are_scoped_and_retry_safe(): void
    {
        [$c]=$this->fixture();$path='/api/customer-workspace/customers/'.$c->id;
        $this->postJson('/api/customers/'.$c->id.'/debts',['amount'=>12,'transaction_date'=>today()->subDays(10)->toDateString(),'due_date'=>today()->subDay()->toDateString(),'idempotency_key'=>'ar'])->assertCreated();
        $expected=app(\App\Services\CustomerCreditService::class)->exposure($c->fresh());$this->assertEquals($expected,$this->getJson($path)->assertOk()->json('finance'));
        $this->postJson($path.'/contacts',['name'=>'Mira','relationship_role'=>'Purchasing','email'=>'mira@example.test'])->assertCreated();
        $this->postJson($path.'/locations',['label'=>'Receiving','kind'=>'delivery','address'=>'Industrial Road'])->assertCreated();
        $note=['kind'=>'call','note'=>'Requested a revised quotation','occurred_at'=>now()->subMinute()->toDateTimeString(),'idempotency_key'=>'call'];$this->postJson($path.'/interactions',$note)->assertCreated();$this->postJson($path.'/interactions',$note)->assertCreated();$this->assertDatabaseCount('customer_interactions',1);
        $follow=['title'=>'Review promised payment','due_at'=>now()->addDay()->toDateTimeString(),'priority'=>'normal','idempotency_key'=>'follow'];$this->postJson($path.'/followups',$follow)->assertCreated();$this->postJson($path.'/followups',$follow)->assertCreated();$this->assertDatabaseCount('operational_tasks',1);
        $this->getJson($path.'/timeline')->assertOk()->assertJsonPath('total',2);
        $other=Company::factory()->create();$foreignId=DB::table('customers')->insertGetId(['company_id'=>$other->id,'name'=>'Private tenant','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);$foreign=(object)['id'=>$foreignId];
        $this->getJson('/api/customer-workspace/customers/'.$foreign->id)->assertNotFound();$this->postJson($path.'/contacts',['name'=>'Wrong','company_id'=>$other->id])->assertCreated();$this->assertDatabaseMissing('customer_contacts',['company_id'=>$other->id]);
        $this->putJson($path.'/owner',['account_owner_id'=>User::factory()->create(['company_id'=>$other->id])->id])->assertNotFound();
        $quote=$this->postJson('/api/customer-workspace/quotations',[...$this->fixturePayload($c),'customer_id'=>$foreign->id])->assertNotFound();
        $this->apiCompany->update(['role_permissions'=>['admin'=>['customers.manage']]]);$this->getJson($path)->assertOk()->assertJsonPath('finance',null)->assertJsonPath('recent_purchases',[]);$this->getJson('/api/customer-workspace/quotations')->assertForbidden();
        $this->apiCompany->update(['capability_settings'=>['package'=>'core-accounting','capabilities'=>['core','accounting']]]);$this->getJson($path)->assertForbidden();
    }
    public function test_opportunity_lineage_and_read_only_sales_copilot_preserve_human_boundaries(): void
    {
        [$c,$p,$channel,$payload]=$this->fixture();
        $opportunity=$this->postJson('/api/customer-workspace/opportunities',['customer_id'=>$c->id,'title'=>'Summer fabric reorder','stage'=>'qualified','expected_value'=>18,'next_action'=>'Review quotation','next_action_on'=>today()->toDateString()])->assertCreated()->json();
        $quote=$this->postJson('/api/customer-workspace/quotations',$payload+['opportunity_id'=>$opportunity['id']])->assertCreated()->json();
        $this->postJson('/api/customer-workspace/quotations/'.$quote['id'].'/send')->assertOk();$this->postJson('/api/customer-workspace/quotations/'.$quote['id'].'/send')->assertOk();
        $this->assertDatabaseHas('sales_opportunities',['id'=>$opportunity['id'],'stage'=>'quoted']);$this->assertEquals(1,\App\Models\BusinessEvent::where('event_type','quotation.sent')->count());
        $before=[SalesOrder::count(),JournalEntry::count(),Product::find($p['id'])->quantity];
        $answer=app(\App\Services\IntelligenceAssistantService::class)->ask(['question'=>'What follow-up is appropriate for this customer?','entity'=>['type'=>'customer','id'=>$c->id]]);$this->assertTrue($answer['read_only']);$this->assertSame('get_customer_relationship',$answer['tools'][0]['tool']);
        $answer=app(\App\Services\IntelligenceAssistantService::class)->ask(['question'=>'Cilëve klientë duhet t’u bëjmë follow-up sot?','language'=>'sq']);$this->assertTrue($answer['read_only']);$this->assertSame('get_sales_followups',$answer['tools'][0]['tool']);
        $answer=app(\App\Services\IntelligenceAssistantService::class)->ask(['question'=>'Approve discount and confirm order']);$this->assertTrue($answer['read_only']);$this->assertSame([],$answer['tools']);
        $this->assertEquals($before,[SalesOrder::count(),JournalEntry::count(),Product::find($p['id'])->quantity]);
    }
    public function test_uninstalled_crm_is_actionable_and_does_not_break_existing_action_center(): void
    {
        $this->fixture();$this->partialMock(\App\Services\CustomerWorkspaceService::class,fn($m)=>$m->shouldReceive('installed')->andReturn(false));
        $this->getJson('/api/customer-workspace')->assertStatus(503);
        $this->getJson('/api/action-center')->assertOk();
    }
    private function fixturePayload(Customer $c): array {return ['order_channel_id'=>OrderChannel::first()->id,'quote_date'=>today()->toDateString(),'expires_on'=>today()->addDays(7)->toDateString(),'payment_type'=>'credit','idempotency_key'=>'foreign','items'=>[['product_id'=>Product::first()->id,'quantity'=>1]]];}
}
