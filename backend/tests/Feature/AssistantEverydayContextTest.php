<?php
namespace Tests\Feature;

use App\Models\{Company,Customer,User};
use App\Services\AssistantConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AssistantEverydayContextTest extends TestCase
{
 use RefreshDatabase;
 public function test_stock_question_units_and_albanian_availability_are_not_entity_or_warehouse_names():void{
  foreach(['Sa metra Milano 04 kemi në dispozicion?'=>'Milano 04','Sa metra Oslo 20 kemi?'=>'Oslo 20','How much fabric Oslo 20 do we have?'=>'Oslo 20'] as $question=>$name){
   $plan=app(\App\Services\AssistantPlanner::class)->plan($question);
   $this->assertSame($name,$plan['term']);$this->assertSame('product',$plan['type']);$this->assertEmpty($plan['warehouse_term']);
  }
 }
 public function test_explanation_rejects_invented_currency_and_partial_numeric_matches():void{
  config(['assistant.local_url'=>'http://127.0.0.1:11438','assistant.local_model'=>'test','assistant.model_timeout_seconds'=>5]);
  foreach(['Debt is $100.00.','Debt is 100 EUR.'] as $explanation){
   \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['message'=>['content'=>json_encode(['explanation'=>$explanation])]])]);
   $this->assertNull(app(\App\Services\LocalAssistantProvider::class)->explain(['amount'=>'1000.00','currency'=>'EUR'],false));
  }
 }
 public function test_terse_debt_question_has_explicit_receivables_scope():void{
  $this->assertSame('finance_receivables',app(\App\Services\FinanceAssistantFacts::class)->intent('borxhet?','test'));
 }
 public function test_warehouse_day_without_task_permission_uses_only_inventory_facts():void{
  $this->actor(['inventory.view']);
  $reply=app(\App\Services\OperatingAssistantFacts::class)->reply(['question'=>'Qka duhet me bo sot ne depo?','language'=>'sq'],'test');
  $this->assertSame('inventory_health',$reply['intent']);$this->assertTrue($reply['read_only']);
  $this->assertSame('get_inventory_health',$reply['tools'][0]['tool']);
 }
 private function actor(array $permissions):Company{
  $this->seed(\Database\Seeders\RolePermissionSeeder::class);
  $company=Company::factory()->create(['role_permissions'=>['staff'=>$permissions]]);
  Auth::setUser(User::factory()->create(['company_id'=>$company->id,'role'=>'staff']));return $company;
 }
 public function test_plural_reference_reads_only_shown_customers_and_refreshes_authoritative_balances():void{
  $c=$this->actor(['debts.view']);$customer=Customer::create(['company_id'=>$c->id,'name'=>'Shown customer']);
  Customer::create(['company_id'=>$c->id,'name'=>'Not shown']);
  $context=['result_entities'=>[['type'=>'customer','id'=>$customer->id,'title'=>$customer->name]]];
  $r=app(AssistantConversation::class)->referencedCustomers(['question'=>'Do those customers owe us money?','language'=>'en'],'test',$context);
  $this->assertCount(1,$r['cards']);$this->assertSame($customer->id,$r['cards'][0]['entity']['id']);
  $this->assertSame('get_customer',$r['tools'][0]['tool']);$this->assertSame($customer->id,$r['tools'][0]['arguments']['customer_id']);
  $this->assertTrue($r['read_only']);$this->assertDatabaseCount('journal_entries',0);
 }
 public function test_ordinal_uses_product_order_not_a_customer_or_model_id():void{
  $s=app(AssistantConversation::class);$context=['result_entities'=>[['type'=>'customer','id'=>99],['type'=>'product','id'=>3],['type'=>'product','id'=>7]]];
  $this->assertSame(7,$s->selected('The second product',$context)['id']);
  $this->assertNull($s->selected('The third product',$context));
  $this->assertNull($s->referencedCustomers(['question'=>'Do they owe us money?'],'test',[]));
 }
 public function test_product_rankings_keep_source_entities_and_summary_separate():void{
  $this->actor(['inventory.view']);
  $r=app(\App\Services\AssistantComposer::class)->compose([['tool'=>'get_sales_analytics','data'=>['metrics'=>['revenue'=>'120.00'],'period'=>['from'=>'2026-10-01','to'=>'2026-10-31'],'rows'=>[['id'=>7,'name'=>'Second item','unit'=>'m','revenue'=>'20.00']]]]],false);
  $this->assertSame(['type'=>'product','id'=>7],$r['cards'][0]['entity']);
  $this->assertSame('120.00',$r['presentation']['total']);$this->assertSame('operational_sales',$r['presentation']['basis']);
 }
 public function test_reference_cannot_bypass_debt_permission():void{
  $c=$this->actor(['inventory.view']);$x=Customer::create(['company_id'=>$c->id,'name'=>'Not permitted']);
  $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
  app(AssistantConversation::class)->referencedCustomers(['question'=>'Do they owe us money?'],'test',['result_entities'=>[['type'=>'customer','id'=>$x->id]]]);
 }
 public function test_customer_reference_cannot_cross_tenants():void{
  $this->actor(['debts.view']);$actor=Auth::user();$other=Company::factory()->create();
  Auth::setUser(User::factory()->create(['company_id'=>$other->id,'role'=>'admin']));
  $x=Customer::create(['name'=>'Other tenant']);$this->assertSame($other->id,$x->company_id);Auth::setUser($actor);
  $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
  app(AssistantConversation::class)->referencedCustomers(['question'=>'Do they owe us money?'],'test',['result_entities'=>[['type'=>'customer','id'=>$x->id]]]);
 }
}
