<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Models\{Category,Product,SupplyOptimizationPlan};
use App\Services\{AssistantPlanner,AimsToolRegistry};

class SupplyOptimizerAssistantTest extends TestCase
{
 use RefreshDatabase;

 private function plan():SupplyOptimizationPlan
 {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();
  $category=Category::create(['name'=>'Optimizer assistant tests']);$lines=[];
  for($i=0;$i<9;$i++){
   $product=Product::create(['name'=>$i===8?'Milano 04':'Product '.$i,'sku'=>'ASSIST-OPT-'.$i,'category_id'=>$category->id,'unit'=>'m','quantity'=>10,'price'=>2]);
   $lines[]=['id'=>'line-'.$i,'product'=>$product->only(['id','name','sku','unit']),'purchase_quantity'=>$i===8?0:20,'transfer_quantity'=>0,'projected_need'=>20,'unmet_target'=>0,'reason'=>$i===8?'covered_by_stock_and_incoming':'horizon_coverage','purchases'=>$i===8?[]:[['supplier_id'=>1,'supplier_name'=>'Factory','quantity'=>20]],'transfers'=>[]];
  }
  return SupplyOptimizationPlan::create(['version'=>(string)Str::uuid(),'request_key'=>hash('sha256',json_encode($lines)),'status'=>'OPTIMAL','scope'=>['horizon'=>30],'created_by'=>auth()->id(),'evidence_cutoff'=>now(),'input'=>['currency'=>'EUR','shipment_links'=>[]],'result'=>['plans'=>[['key'=>'balanced','status'=>'OPTIMAL','summary'=>['commitment'=>'40.00'],'lines'=>$lines]]]]);
 }

 public function test_original_unpurchased_and_marginal_questions_route_to_structured_optimizer():void
 {
  $parser=app(AssistantPlanner::class);
  $p=$parser->plan('Which products are we intentionally not purchasing?',['optimization_plan_id'=>7]);
  $this->assertSame('optimization_explain',$p['intent']);$this->assertSame('not_purchasing',$p['optimization']['decision_view']);
  $p=$parser->plan('Why are we buying only 4,000m of Milano 04 for plan #7?');
  $this->assertSame('optimization_explain',$p['intent']);$this->assertSame('Milano 04',$p['optimization']['product_term']);$this->assertSame(7,$p['optimization']['plan_id']);
  $p=$parser->plan('Why 6,000m instead of 8,000m?',['optimization_plan_id'=>7]);$this->assertSame('optimization_explain',$p['intent']);$this->assertArrayNotHasKey('product_term',$p['optimization']);
  $p=$parser->plan("Why didn't AIMS purchase Product Milano 04 for plan #7?");$this->assertSame('optimization_explain',$p['intent']);$this->assertSame('Milano 04',$p['optimization']['product_term']);
 }

 public function test_product_question_finds_frozen_target_beyond_first_eight_rows_without_business_writes():void
 {
  $plan=$this->plan();
  $reply=$this->postJson('/api/intelligence-assistant/ask',['question'=>'Why are we buying only 4,000m of Milano 04 for plan #'.$plan->id.'?'])->assertOk()->assertJsonPath('intent','optimization_explain')->json();
  $cards=collect($reply['cards'])->where('tool','explain_optimization_decision');$this->assertCount(1,$cards);$card=$cards->first();
  $this->assertSame('Milano 04 · Balanced',$card['title']);$this->assertSame('covered_by_stock_and_incoming',$card['evidence']['reason']);
  $this->assertDatabaseCount('purchase_requests',0);$this->assertDatabaseCount('purchase_orders',0);$this->assertDatabaseCount('stock_movements',0);$this->assertDatabaseCount('journal_entries',0);
 }

 public function test_unpurchased_view_filters_before_assistant_card_limit():void
 {
  $plan=$this->plan();$reply=$this->postJson('/api/intelligence-assistant/ask',['question'=>'Which products are we intentionally not purchasing for plan #'.$plan->id.'?'])->assertOk()->json();
  $this->assertCount(1,$reply['cards']);$this->assertSame('Milano 04 · Balanced',$reply['cards'][0]['title']);
  $read=app(AimsToolRegistry::class)->execute('explain_optimization_decision',['plan_id'=>$plan->id,'decision_view'=>'not_purchasing'])['data'];
  $this->assertCount(1,$read['alternatives'][0]['decisions']);$this->assertSame(0,$read['alternatives'][0]['decisions'][0]['purchase_quantity']);
 }

 public function test_unknown_product_name_is_not_guessed_from_live_search():void
 {
  $plan=$this->plan();$reply=$this->postJson('/api/intelligence-assistant/ask',['question'=>'Why are we buying only 4,000m of Unknown Product for plan #'.$plan->id.'?'])->assertOk()->json();
  $this->assertStringContainsString('does not identify one product',$reply['text']);$this->assertFalse(collect($reply['sources'])->contains('tool','explain_optimization_decision'));
 }
}
