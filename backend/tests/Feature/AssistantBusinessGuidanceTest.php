<?php
namespace Tests\Feature;

use App\Models\{Category,Product};
use App\Services\{AssistantConversation,AssistantToolRunner,OperatingAssistantFacts};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantBusinessGuidanceTest extends TestCase
{
 use RefreshDatabase;

 public function test_payment_followup_reads_the_explicit_customer_statement(): void
 {
     $this->actingAsApiUser('admin');
     $this->getJson('/api/me')->assertOk();
     $customer = \App\Models\Customer::create($this->tenantAttributes(['name' => 'Statement customer']));
     $this->postJson('/api/intelligence-assistant/ask', [
         'question' => 'Have they paid recently?',
         'entity' => ['type' => 'customer', 'id' => $customer->id],
     ])->assertOk()
         ->assertJsonPath('intent', 'customer_payment_activity')
         ->assertJsonPath('tools.0.arguments.customer_id', $customer->id)
         ->assertJsonPath('context.id', $customer->id);
 }

 public function test_business_intent_cannot_substitute_inventory_or_sales_evidence(): void
 {
     $this->actingAsApiUser('admin');
     $this->getJson('/api/me')->assertOk();
     app(\App\Services\AccountingService::class)->initialize();
     config(['assistant.model_tools' => true, 'assistant.provider' => 'ollama', 'assistant.local_model' => 'test']);
     Http::fake();
     $questions = [
         'S’po niset kjo porosi, pse?' => ['work_blocked', null],
         'Which vendor receipts are late?' => ['overdue_supply', 'get_incoming_supply'],
         'Which vendor invoices fall due in seven days?' => ['finance_payables', 'get_accounting_payables'],
         'Rank clients by outstanding balances' => ['finance_receivables', 'get_accounting_receivables'],
     ];
     foreach ($questions as $question => [$intent, $tool]) {
         $response = $this->postJson('/api/intelligence-assistant/ask', ['question' => $question])
             ->assertOk()->assertJsonPath('intent', $intent)->assertJsonPath('read_only', true);
         if ($tool) {
             $response->assertJsonPath('tools.0.tool', $tool);
         } else {
             $response->assertJsonPath('state', 'needs_clarification')->assertJsonPath('tools', []);
         }
     }
     $this->postJson('/api/intelligence-assistant/ask', ['question' => 'Which vendor bills fall due in the next 90 days?'])
         ->assertOk()->assertJsonPath('state', 'insufficient')->assertJsonPath('tools', []);
     Http::assertNothingSent();
 }

 public function test_schema_intent_cannot_supply_a_tool_id_or_choose_another_domain(): void
 {
     config(['assistant.model_tools' => true, 'assistant.provider' => 'ollama', 'assistant.local_model' => 'test']);
     Http::fakeSequence()
         ->push(['message' => ['content' => '{"route":"receivables","ambiguous":false,"phrase":"vendor receipt"}']])
         ->push(['message' => ['content' => '{"route":"receipts","ambiguous":false,"phrase":"vendor receipt","company_id":55}']])
         ->push(['message' => ['content' => '{"route":"receipts","ambiguous":false,"phrase":"vendor receipt"}']]);
     $provider = app(\App\Services\LocalAssistantProvider::class);
     $this->assertNull($provider->businessIntent('I am still waiting on a vendor receipt', ['receipts']));
     $this->assertNull($provider->businessIntent('I am still waiting on a vendor receipt', ['receipts']));
     $this->assertSame('receipts', $provider->businessIntent('I am still waiting on a vendor receipt', ['receipts'])['route']);
 }
 public function test_sellable_is_availability_not_a_sales_word_prefix():void{
  $p=app(\App\Services\AssistantPlanner::class)->plan('Check sellable meters for Walnut 01.');
  $this->assertSame('record',$p['intent']);$this->assertSame('product',$p['type']);
 }
 public function test_product_risk_question_uses_the_current_forecast_list(): void
 {
     $this->actingAsApiUser('admin');
     $this->getJson('/api/me')->assertOk();
     $this->postJson('/api/intelligence-assistant/ask', ['question' => 'Which products are at risk of running out?'])
         ->assertOk()->assertJsonPath('tools.0.tool', 'get_inventory_intelligence_recommendations')
         ->assertJsonPath('tools.0.arguments.risk', 'high')->assertJsonPath('read_only', true);
 }
 public function test_named_period_uses_the_requested_month_and_year():void{
  $p=app(\App\Services\AssistantPlanner::class)->period('close October 2025 books','UTC');
  $this->assertSame('2025-10-01',$p['from']);$this->assertSame('2025-10-31',$p['to']);
  $p=app(\App\Services\AssistantPlanner::class)->period('Shqyrto periudhën e tetorit 2025','UTC');
  $this->assertSame('2025-10-01',$p['from']);$this->assertSame('2025-10-31',$p['to']);
 }

 private function product(string $name,string $unit='m'):Product{return Product::create(['name'=>$name,'sku'=>$name,'unit'=>$unit,'quantity'=>5,'min_quantity'=>10,'price'=>5,'purchase_price'=>2,'category_id'=>Category::firstOrCreate(['name'=>'Materials'])->id]);}
 public function test_simple_stock_uses_authority_without_any_model_request():void{
  $this->actingAsApiUser('admin');$this->getJson('/api/me')->assertOk();$p=$this->product('Walnut 01');
  config(['assistant.model_tools'=>true,'assistant.provider'=>'ollama','assistant.local_model'=>'test']);Http::fake(fn()=>throw new \RuntimeException('A model must not be needed'));
  $r=$this->postJson('/api/intelligence-assistant/ask',['question'=>'How much Walnut 01 do we have?'])->assertOk()->json();
  $this->assertSame('deterministic',$r['provider']);$this->assertSame($p->id,$r['cards'][0]['entity']['id']);$this->assertStringContainsString('available.',$r['text']);Http::assertNothingSent();
 }
 public function test_packed_order_guidance_is_review_not_a_delivery_promise():void{
  $this->actingAsApiUser('admin');$this->getJson('/api/me')->assertOk();$r=app(OperatingAssistantFacts::class)->reply(['question'=>'Which orders can we deliver today?'],'test');
  $this->assertSame('fulfillment_review',$r['intent']);$this->assertSame('packed',$r['tools'][0]['arguments']['view']);$this->assertTrue($r['read_only']);
  $this->assertStringContainsString('not a delivery promise',$r['meaning']);$this->assertDatabaseCount('stock_movements',0);
 }
 public function test_hypothetical_delay_is_not_reported_as_an_actual_overdue_receipt():void{
  $this->actingAsApiUser('admin');$this->getJson('/api/me')->assertOk();$this->assertNull(app(OperatingAssistantFacts::class)->reply(['question'=>'What if supplier Duplicate is late 2 days?'],'test'));
 }
 public function test_two_product_comparison_revalidates_sources_and_keeps_units_separate():void{
  $this->actingAsApiUser('admin');$this->getJson('/api/me')->assertOk();$a=$this->product('Walnut 01','m');$b=$this->product('Hardware 02','pcs');
  $r=app(AssistantConversation::class)->compareReferences(['question'=>'Which one has more available?'],'test',['result_entities'=>[['type'=>'product','id'=>$a->id],['type'=>'product','id'=>$b->id]]]);
  $this->assertCount(2,$r['cards']);$this->assertStringContainsString('Units remain separate',$r['text']);
  $this->assertSame([$a->id,$b->id],array_column(array_column($r['tools'],'arguments'),'product_id'));
 }
 public function test_product_why_uses_current_position_without_inventing_a_shrinkage_cause():void{
  $this->actingAsApiUser('admin');$this->getJson('/api/me')->assertOk();$p=$this->product('Walnut 01');
  $r=app(AssistantConversation::class)->productExplanation(['question'=>'Why?'],'test',['entity'=>['type'=>'product','id'=>$p->id,'title'=>$p->name]]);
  $this->assertSame('availability_explanation',$r['intent']);$this->assertStringContainsString('Check movements',$r['meaning']);$this->assertTrue($r['read_only']);
 }
 public function test_incoming_tool_cannot_accept_an_unimplemented_country_filter():void{
  $this->actingAsApiUser('admin');$this->getJson('/api/me')->assertOk();$this->expectException(\Illuminate\Validation\ValidationException::class);
  app(AssistantToolRunner::class)->execute('get_incoming_supply',['country'=>'CN']);
 }

 public function test_work_proposal_uses_authorized_queue_and_rejects_extra_arguments(): void
 {
     $this->actingAsApiUser('admin');
     $this->getJson('/api/me')->assertOk();
     config(['assistant.model_tools' => true, 'assistant.provider' => 'ollama', 'assistant.local_model' => 'test']);
     $proposal = fn (array $arguments) => Http::response(['message' => ['tool_calls' => [
         ['function' => ['name' => 'propose_work_topic', 'arguments' => $arguments]],
     ]]]);
     Http::fakeSequence()->pushResponse($proposal(['topic' => 'count']))->pushResponse($proposal(['topic' => 'count', 'company_id' => 999]));
     $question = ['question' => 'Where should I start this shift?'];
     $guide = app(\App\Services\AssistantWorkGuidance::class);
     $answer = $guide->reply($question, 'test', []);
     $this->assertSame('ollama', $answer['provider']);
     $this->assertSame('get_count_reviews', $answer['tools'][0]['tool']);
     $this->assertTrue($answer['read_only']);
     $this->assertNull($guide->reply($question, 'test', []));
 }

 public function test_work_queue_permissions_and_order_clarification_are_source_specific(): void
 {
     $this->actingAsApiUser('hr');
     $this->postJson('/api/capabilities/get_count_reviews/execute', ['input' => []])->assertForbidden();

     $this->actingAsApiUser('admin');
     $this->postJson('/api/intelligence-assistant/ask', ['question' => "Why can't I ship this order yet?"])
         ->assertOk()->assertJsonPath('state', 'needs_clarification')->assertJsonPath('tools', []);
     $this->assertDatabaseCount('stock_movements', 0);
 }
}
