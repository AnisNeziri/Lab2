<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB,Cache};
use Illuminate\Support\Str;
use App\Models\{DecisionLearningRecord as Record,EnterpriseDecision,Category,Product,Supplier,ProductSupplier,AnalyticsSnapshot,AnalyticsPrediction,Company,User,BusinessEvent};
use App\Services\{DecisionLearningService as Learning,DecisionLearningMath,EnterpriseDecisionService,InventoryPlanningService,AimsToolRegistry};

class DecisionLearningTest extends TestCase {
 use RefreshDatabase;
 private function setupDecision(bool $history=false):EnterpriseDecision {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$this->travelTo(now()->setDate(2026,5,1)->setTime(12,0));
  $c=Category::create(['name'=>'Hardware']);$p=Product::create(['name'=>'Milano 04','sku'=>'ML04','unit'=>'pcs','category_id'=>$c->id,'quantity'=>5,'min_quantity'=>20,'high_stock_threshold'=>500,'price'=>5,'purchase_price'=>2]);$p->forceFill(['created_at'=>now()->subYear()])->save();
  $s=Supplier::create(['name'=>'Recorded factory','is_active'=>true]);ProductSupplier::create(['product_id'=>$p->id,'supplier_id'=>$s->id,'purchase_price'=>2,'currency'=>'EUR','exchange_rate_to_base'=>1,'usual_lead_time_days'=>3,'pack_size'=>5,'minimum_order_quantity'=>10,'is_active'=>true]);
  if($history)for($n=1;$n<=90;$n++)AnalyticsSnapshot::create(['entity_type'=>'demand_observation','entity_id'=>$p->id,'warehouse_id'=>0,'snapshot_date'=>today()->subDays($n),'observed_at'=>today()->subDays($n)->endOfDay(),'feature_version'=>'v2','facts'=>['unit'=>'pcs','demand'=>10,'sales_quantity'=>10,'returned'=>0,'available'=>100,'stockout'=>false,'complete'=>true,'stock_snapshot_id'=>1]]);
  app(EnterpriseDecisionService::class)->refresh($p->id);return EnterpriseDecision::where('decision_type','REPLENISHMENT_DECISION')->firstOrFail();
 }
 private function learning():Learning {return app(Learning::class);}
 private function observations(EnterpriseDecision $d,int $count=7,bool $stockout=false):void {
  for($n=1;$n<=$count;$n++)$this->observation($d,$n,$stockout);
 }
 private function observation(EnterpriseDecision $d,int $day,bool $stockout=false):void {
  $date=$d->generated_at->copy()->addDays($day);$available=$stockout?0:100;
  $stock=AnalyticsSnapshot::create(['entity_type'=>'product','entity_id'=>$d->product_id,'warehouse_id'=>0,'snapshot_date'=>$date,'observed_at'=>$date->copy()->endOfDay(),'feature_version'=>'v1','facts'=>['unit'=>'pcs','available'=>$available]]);
  AnalyticsSnapshot::create(['entity_type'=>'demand_observation','entity_id'=>$d->product_id,'warehouse_id'=>0,'snapshot_date'=>$date,'observed_at'=>$date->copy()->endOfDay(),'feature_version'=>'v2','facts'=>['unit'=>'pcs','demand'=>$stockout?null:10,'sales_quantity'=>10,'available'=>$available,'stockout'=>$stockout,'complete'=>!$stockout,'stock_snapshot_id'=>$stock->id]]);
 }
 private function experiment():array {return $this->postJson('/api/decision-learning/experiments',['quantity_multiplier'=>.8,'reason'=>'Repeated human quantity reductions warrant prospective shadow review.'])->assertOk()->json();}
 private function pairs(int $id,int $count=50,bool $degraded=false):void {
  for($n=0;$n<$count;$n++){$from=today()->subDays(100)->addDays((int)floor($n/5)*8);$this->learning()->append('test-pair:'.$n,'comparison','inventory','REPLENISHMENT_DECISION',[
   'experiment_id'=>$id,'product_id'=>($n%5)+1,'warehouse_id'=>null,'window'=>['from'=>$from->toDateString(),'to'=>$from->copy()->addDays(6)->toDateString()],
   'regime_key'=>'fixture-stable','champion'=>['stockout_days'=>0,'excess_mean_quantity'=>100,'purchase_cost'=>'100.00'],'challenger'=>['stockout_days'=>$degraded?1:0,'excess_mean_quantity'=>80,'purchase_cost'=>'80.00']],null,null,$from);}
 }
 public function test_recommendation_snapshot_is_frozen_and_reads_do_not_mutate_business_or_learning():void {
  $d=$this->setupDecision();$r=Record::where('kind','recommendation')->where('source_id',$d->id)->firstOrFail();$original=$r->payload;
  $this->assertSame('point_in_time',$original['origin']);$this->assertSame($d->alternatives[0],$original['recommended']);$before=Record::count();
  $this->getJson('/api/decision-learning')->assertOk();$this->getJson('/api/decision-learning/decisions/'.$d->id)->assertOk()->assertJsonPath('state','WAITING_FOR_GENUINE_OUTCOME');$this->assertSame($before,Record::count());$this->assertDatabaseCount('purchase_orders',0);$this->assertDatabaseCount('journal_entries',0);
  try{$r->update(['payload'=>[]]);$this->fail('Immutable record changed');}catch(\LogicException){}$this->assertSame($original,$r->fresh()->payload);
 }
 public function test_completed_window_and_real_stock_provenance_are_required():void {
  $d=$this->setupDecision();$this->observations($d,6);$this->travel(6)->days();$this->assertNull($this->learning()->evaluateDecision($d));$this->travel(3)->days();$this->assertNull($this->learning()->evaluateDecision($d));
  $this->observation($d,7);
  $o=$this->learning()->evaluateDecision($d);$this->assertSame('COMPLETED_OBSERVATION',$o->payload['state']);$this->assertSame(0,$o->payload['scorecard']['stockout_days']);$this->assertSame(70,$o->payload['scorecard']['demand_observed']);
 }
 public function test_stockout_censoring_does_not_hide_a_real_stockout_or_invent_lost_sales():void {
  $d=$this->setupDecision();$this->observations($d,7,true);$this->travel(9)->days();$p=$this->learning()->evaluateDecision($d)->payload;
  $this->assertSame(7,$p['scorecard']['stockout_days']);$this->assertNull($p['scorecard']['lost_revenue']);$this->assertNull($p['scorecard']['unconstrained_demand']);$this->assertFalse($p['decision_quality']['causal_claim']);
 }
 public function test_human_modification_and_original_quantity_stay_separate():void {
  $d=$this->setupDecision();$d->update(['status'=>'modified','history'=>array_merge($d->history,[['action'=>'created_pr','at'=>now()->toIso8601String(),'user_id'=>Auth::id(),'base_quantity'=>10,'supplier_id'=>1]])]);$this->learning()->response($d);
  $r=Record::where('kind','response')->latest('id')->first();$this->assertSame('MODIFIED',$r->payload['state']);$this->assertEquals(15,$r->payload['recommended_quantity']);$this->assertEquals(10,$r->payload['chosen_quantity']);$this->assertEquals(15,$d->fresh()->alternatives[0]['base_quantity']);
 }
 public function test_dismissal_is_not_failure_and_acceptance_is_not_success():void {
  $d=$this->setupDecision();$this->postJson('/api/analytics/decisions/'.$d->id.'/feedback',['action'=>'dismissed','note'=>'Existing stock is sufficient for current work'])->assertOk();$this->observations($d);$this->travel(9)->days();$p=$this->learning()->evaluateDecision($d)->payload;
  $this->assertSame('DISMISSED',$p['response']['state']);$this->assertFalse($p['decision_quality']['stockout_issue']);$this->assertNull($p['response']['chosen_quantity']);
 }
 public function test_prediction_and_decision_quality_are_not_merged():void {
  $d=$this->setupDecision();$snapshot=AnalyticsSnapshot::create(['entity_type'=>'product','entity_id'=>$d->product_id,'warehouse_id'=>0,'snapshot_date'=>today(),'observed_at'=>now(),'feature_version'=>'v1','facts'=>['unit'=>'pcs','available'=>5]]);
  AnalyticsPrediction::create(['entity_type'=>'product','entity_id'=>$d->product_id,'prediction_type'=>'demand_forecast','model_key'=>'inventory-demand-v1','model_version'=>'genuine-test-model','input_feature_version'=>'v1','analytics_snapshot_id'=>$snapshot->id,'generated_at'=>now()->subDays(40),'valid_until'=>now()->subDay(),'evaluated_at'=>now(),'value'=>['horizon'=>30],'evaluation'=>['eligible'=>true,'mae'=>12],'actual_value'=>['observed_demand'=>100]]);
  $this->learning()->maintain();$r=Record::where('kind','prediction_outcome')->firstOrFail();$this->assertNull($r->payload['decision_quality']);$this->assertEquals(12,$r->payload['prediction_quality']['mae']);$this->getJson('/api/decision-learning')->assertOk()->assertJsonPath('summary.prediction_evaluations',1)->assertJsonPath('summary.evaluated',0);
 }
 public function test_future_shadow_freezes_input_without_rebuilding_past_decisions():void {
  $past=$this->setupDecision();$oldCount=Record::where('kind','shadow')->count();$e=$this->experiment();$this->assertSame($oldCount,Record::where('kind','shadow')->count());
  $this->travel(1)->days();ProductSupplier::first()->update(['purchase_price'=>3]);InventoryPlanningService::invalidate($past->company_id,[$past->product_id]);app(EnterpriseDecisionService::class)->refresh($past->product_id);
  $shadow=Record::where('kind','shadow')->firstOrFail();$frozen=$shadow->payload;$this->assertSame($e['id'],$frozen['experiment_id']);$this->assertTrue($frozen['created_for_future_outcome']);$this->assertSame('EXPERIMENTAL',$frozen['state']);
  Product::first()->update(['quantity'=>1]);$this->assertSame($frozen,$shadow->fresh()->payload);$this->assertSame(config('enterprise_decisions.version'),$this->learning()->currentPolicy()['version']);$this->assertFalse($this->learning()->comparison($e['id'])['comparison']['eligible_for_human_review']);
 }
 public function test_real_pr_po_receipt_chain_is_attributed_to_the_modified_action_not_original_quantity():void {
  $past=$this->setupDecision();$e=$this->experiment();$this->travel(1)->days();ProductSupplier::first()->update(['purchase_price'=>3]);app(EnterpriseDecisionService::class)->refresh($past->product_id);$d=EnterpriseDecision::where('decision_type','REPLENISHMENT_DECISION')->latest('id')->first();
  $key=$d->alternatives[0]['key'];$options=['base_quantity'=>10];$review=$this->postJson('/api/analytics/decisions/'.$d->id.'/review',['alternative_key'=>$key,'options'=>$options])->assertOk()->json();$prId=$this->postJson('/api/analytics/decisions/'.$d->id.'/purchase-request',['alternative_key'=>$key,'options'=>$options,'review_token'=>$review['review_token']])->assertOk()->json('purchase_request.id');
  $item=\App\Models\PurchaseRequestItem::where('purchase_request_id',$prId)->first();$supplier=ProductSupplier::first()->supplier_id;
  $rfq=\App\Models\Rfq::create(['purchase_request_id'=>$prId,'rfq_number'=>'TEST-LEARNING-RFQ']);$quote=\App\Models\SupplierQuote::create(['rfq_id'=>$rfq->id,'supplier_id'=>$supplier,'currency'=>'EUR','exchange_rate'=>1]);$line=$quote->items()->create(['purchase_request_item_id'=>$item->id,'offered_quantity'=>10,'unit_price'=>3]);
  $this->travel(2)->days();$po=\App\Models\PurchaseOrder::create(['supplier_id'=>$supplier,'po_number'=>'TEST-LEARNING-PO','status'=>'received','ordered_at'=>$d->generated_at,'expected_at'=>today(),'received_at'=>today(),'total_amount'=>30,'currency'=>'EUR','exchange_rate'=>1]);$poLine=$po->items()->create(['product_id'=>$d->product_id,'description'=>'Milano 04','unit'=>'pcs','inventory_unit'=>'pcs','conversion_factor'=>1,'quantity'=>10,'base_quantity'=>10,'received_base_quantity'=>10,'unit_price'=>3,'line_total'=>30]);
  \App\Models\ProcurementAward::create(['rfq_id'=>$rfq->id,'purchase_request_item_id'=>$item->id,'supplier_quote_item_id'=>$line->id,'purchase_order_id'=>$po->id,'selected_at'=>now(),'status'=>'converted']);$w=\App\Models\Warehouse::create(['name'=>'Main','code'=>'ML-W','is_active'=>true]);$receipt=\App\Models\GoodsReceipt::create(['purchase_order_id'=>$po->id,'warehouse_id'=>$w->id,'receipt_number'=>'ML-RECEIPT','received_at'=>now(),'status'=>'posted','idempotency_key'=>(string)Str::uuid()]);$receipt->items()->create(['purchase_order_item_id'=>$poLine->id,'product_id'=>$d->product_id,'ordered_unit'=>'pcs','accepted_quantity'=>10,'accepted_base_quantity'=>10,'inventory_unit'=>'pcs','base_purchase_unit_cost'=>3]);
  $this->observations($d);$this->travel(7)->days();$out=$this->learning()->evaluateDecision($d)->payload;
  $this->assertSame('MODIFIED',$out['response']['state']);$this->assertEquals(15,$out['response']['recommended_quantity']);$this->assertEquals(10,$out['chain']['actual_ordered_quantity']);$this->assertSame('30.00',$out['scorecard']['purchase_cost']);$this->assertSame([$receipt->id],$out['chain']['receipt_ids']);$this->assertSame([],$out['next_replenishment_ids']);
  $comparison=Record::where('kind','comparison')->firstOrFail();$this->assertSame('estimated_scenario',$comparison->payload['evidence_kind']);$this->assertEquals(10,$comparison->payload['actual_received_quantity']);$this->assertFalse($this->learning()->comparison($e['id'])['comparison']['eligible_for_human_review']);
 }
 public function test_duplicate_experiment_reuses_existing_version_and_does_not_promote():void {
  $this->setupDecision();$a=$this->experiment();$b=$this->experiment();$this->assertSame($a['id'],$b['id']);$this->assertDatabaseCount('purchase_requests',0);$this->assertSame(0,Record::where('kind','policy_change')->count());$this->assertSame(1,Record::where('kind','experiment')->count());
 }
 public function test_sparse_evidence_cannot_promote_and_guardrail_changes_are_rejected():void {
  $this->setupDecision();$e=$this->experiment();$this->pairs($e['id'],7);$c=$this->learning()->comparison($e['id']);$this->assertEquals(7,$c['comparison']['independent_samples']);$this->assertFalse($c['comparison']['eligible_for_human_review']);
  $this->postJson('/api/decision-learning/experiments/'.$e['id'].'/promote',['confirmed'=>true,'reason'=>'Please promote this experiment','expected_champion'=>config('enterprise_decisions.version')])->assertStatus(422);
  $weights=config('enterprise_decisions.weights');$weights['stockout']=0;$this->postJson('/api/decision-learning/experiments',['quantity_multiplier'=>.9,'reason'=>'Try unsafe risk guardrail','weights'=>$weights])->assertStatus(422);$this->postJson('/api/decision-learning/experiments',['quantity_multiplier'=>.1,'reason'=>'Try unsafe quantity guardrail'])->assertStatus(422);
 }
 public function test_explicit_eligible_promotion_is_audited_and_can_roll_back():void {
  $this->setupDecision();$e=$this->experiment();$this->pairs($e['id']);$this->assertTrue($this->learning()->comparison($e['id'])['comparison']['eligible_for_human_review']);$this->assertSame(0,Record::where('kind','policy_change')->count());
  $this->postJson('/api/decision-learning/experiments/'.$e['id'].'/promote',['reason'=>'Enough stable paired evidence','expected_champion'=>config('enterprise_decisions.version')])->assertStatus(422);
  $res=$this->postJson('/api/decision-learning/experiments/'.$e['id'].'/promote',['confirmed'=>true,'reason'=>'Enough stable paired evidence','expected_champion'=>config('enterprise_decisions.version')])->assertOk();$version=$res->json('champion.version');$this->assertEquals(.8,$res->json('champion.quantity_multiplier'));$this->assertNotSame(config('enterprise_decisions.version'),$version);
  $this->postJson('/api/decision-learning/experiments/'.$e['id'].'/rollback',['confirmed'=>true,'reason'=>'Rollback after administrator review','expected_champion'=>$version])->assertOk()->assertJsonPath('champion.quantity_multiplier',1);
  $this->assertEquals(2,Record::where('kind','policy_change')->count());$this->assertDatabaseCount('purchase_orders',0);$this->assertDatabaseCount('journal_entries',0);$this->assertEquals(1,BusinessEvent::where('event_type','intelligence.learning.champion_promoted')->count());$this->assertEquals(1,BusinessEvent::where('event_type','intelligence.learning.champion_rolled_back')->count());
 }
 public function test_service_degradation_blocks_promotion_even_when_stock_and_cost_are_lower():void {
  $this->setupDecision();$e=$this->experiment();$this->pairs($e['id'],50,true);$r=$this->learning()->comparison($e['id']);$this->assertFalse($r['comparison']['stable_no_service_degradation']);$this->assertFalse($r['comparison']['eligible_for_human_review']);
 }
 public function test_overlapping_windows_and_regime_changes_are_not_independent_samples():void {
  $this->setupDecision();$e=$this->experiment();$this->pairs($e['id'],10);$p=Record::where('kind','comparison')->first()->payload;
  $this->learning()->append('duplicate-observation','comparison','inventory','REPLENISHMENT_DECISION',$p,null,null,$p['window']['from']);$this->assertEquals(10,$this->learning()->comparison($e['id'])['comparison']['independent_samples']);
  $p['regime_key']='changed-supplier-cost';$p['window']=['from'=>today()->subDay()->toDateString(),'to'=>today()->toDateString()];$this->learning()->append('changed-regime','comparison','inventory','REPLENISHMENT_DECISION',$p,null,null,$p['window']['from']);
  $this->assertEquals(9,$this->learning()->comparison($e['id'])['comparison']['independent_samples']);
 }
 public function test_manager_can_review_but_cannot_change_company_policy():void {
  $this->setupDecision();$user=Auth::user();$user->update(['role'=>'manager']);$this->getJson('/api/decision-learning')->assertOk()->assertJsonPath('can_manage',false);$this->postJson('/api/decision-learning/experiments',['quantity_multiplier'=>.8,'reason'=>'Manager tries policy promotion'])->assertForbidden();
 }
 public function test_tenant_isolation_for_history_comparison_outcome_and_tools():void {
  $d=$this->setupDecision();$e=$this->experiment();$other=Company::factory()->create();User::factory()->create(['company_id'=>$other->id,'role'=>'admin','api_token'=>hash('sha256','learning-other'),'email_verified_at'=>now()]);$this->withToken('learning-other')->getJson('/api/me')->assertOk();
  $this->getJson('/api/decision-learning')->assertOk()->assertJsonPath('summary.recommendations',0);$this->getJson('/api/decision-learning/experiments/'.$e['id'])->assertNotFound();$this->getJson('/api/decision-learning/decisions/'.$d->id)->assertNotFound();
 }
 public function test_financial_records_are_not_visible_to_inventory_only_roles():void {
  $this->setupDecision();$this->learning()->append('cash-test','prediction_outcome','finance','cash_forecast',['prediction_quality'=>['currency'=>'EUR','error'=>'100.00']]);
  $role=\App\Models\Role::where('slug','admin')->first();$role->permissions()->detach(\App\Models\Permission::whereIn('slug',['analytics.finance','finance.view','financial_accounts.view'])->pluck('id'));Cache::forget('role_permissions:admin');
  $this->getJson('/api/decision-learning')->assertOk()->assertJsonPath('summary.prediction_evaluations',0);$this->getJson('/api/decision-learning/performance?domain=finance')->assertForbidden();
 }
 public function test_assistant_learning_queries_use_read_only_evidence_and_never_promote():void {
  $this->setupDecision();$e=$this->experiment();$before=Record::count();$this->postJson('/api/intelligence-assistant/ask',['question'=>'How good are inventory recommendations?'])->assertOk()->assertJsonPath('intent','learning')->assertJsonPath('read_only',true)->assertJsonPath('cards.0.metrics.0.value',0);
  $this->postJson('/api/intelligence-assistant/ask',['question'=>'Do we usually buy less than AIMS recommends?'])->assertOk()->assertJsonPath('intent','overrides');
  $names=array_column(app(AimsToolRegistry::class)->catalog(),'name');$this->assertContains('compare_policy_versions',$names);$this->assertNotContains('promote_decision_policy',$names);$this->assertSame($before,Record::count());
 }
 public function test_outcome_automation_is_deduplicated_and_no_task_per_outcome_is_created():void {
  $d=$this->setupDecision();$this->observations($d);$this->travel(9)->days();$this->learning()->evaluateDecision($d);$this->learning()->evaluateDecision($d);
  $this->assertEquals(1,BusinessEvent::where('event_type','intelligence.learning.outcome_completed')->count());$this->assertDatabaseCount('operational_tasks',0);
  $outcome=Record::where('kind','outcome')->first();$registry=app(\App\Services\AutomationRegistry::class);$this->assertSame('/decision-learning?tab=outcomes&record='.$outcome->payload['recommendation_id'],$registry->url('DecisionLearningRecord',$outcome->id));
  $e=$this->experiment();$this->assertSame('/decision-learning?tab=challengers',$registry->url('DecisionLearningRecord',$e['id']));
 }
 public function test_money_tradeoffs_are_separate_fixed_precision_and_cost_pressure_cannot_win():void {
  $this->setupDecision();$pair=['product_id'=>1,'champion'=>['stockout_days'=>0,'excess_mean_quantity'=>10,'purchase_cost'=>'100.00'],'challenger'=>['stockout_days'=>0,'excess_mean_quantity'=>5,'purchase_cost'=>'100.01']];$r=app(DecisionLearningMath::class)->compare([$pair]);$this->assertFalse($r['stable_no_service_degradation']);$this->assertArrayNotHasKey('overall_score',$r);$this->assertSame('0.01',$r['dimensions']['purchase_cost']['change']);
 }
 public function test_a_provenance_id_without_the_matching_real_daily_stock_is_not_evidence():void {
  $d=$this->setupDecision();for($day=1;$day<=7;$day++)AnalyticsSnapshot::create(['entity_type'=>'demand_observation','entity_id'=>$d->product_id,'warehouse_id'=>0,'snapshot_date'=>$d->generated_at->copy()->addDays($day),'observed_at'=>$d->generated_at->copy()->addDays($day),'feature_version'=>'v2','facts'=>['unit'=>'pcs','available'=>100,'sales_quantity'=>10,'stock_snapshot_id'=>999999]]);
  $this->travel(9)->days();$this->assertNull($this->learning()->evaluateDecision($d));$this->assertDatabaseCount('decision_learning_records',Record::count());$this->assertEquals(0,Record::where('kind','outcome')->count());
 }
 public function test_version_filters_apply_to_performance_responses_and_overview_without_mutation():void {
  $d=$this->setupDecision();$this->observations($d);$d->update(['status'=>'modified','history'=>[['action'=>'modified','base_quantity'=>10,'at'=>now()->toIso8601String(),'user_id'=>Auth::id()]]]);$this->travel(9)->days();$this->learning()->evaluateDecision($d);
  $this->learning()->append('other-policy','recommendation','inventory','REPLENISHMENT_DECISION',['policy_version'=>'other-policy','recommended'=>['base_quantity'=>100]]);
  $version=urlencode($d->reasoning['policy_version']);$r=$this->getJson('/api/decision-learning?type=REPLENISHMENT_DECISION&policy_version='.$version)->assertOk()->assertJsonPath('summary.recommendations',1)->assertJsonPath('summary.evaluated',1)->assertJsonPath('summary.human_responses.MODIFIED',1);
  $this->assertEquals(10,$r->json('rows.0.data.response.chosen_quantity'));$this->assertNull($r->json('rows.0.data.chain.actual_ordered_quantity'));
  $this->getJson('/api/decision-learning/performance?policy_version='.$version)->assertOk()->assertJsonPath('total',1);
  $this->getJson('/api/decision-learning/performance?policy_version=other-policy')->assertOk()->assertJsonPath('total',0);
  $id=$r->json('rows.0.id');$this->getJson('/api/decision-learning/records/'.$id.'/outcome')->assertOk()->assertJsonPath('state','COMPLETED_OBSERVATION');
 }
 public function test_incremental_source_cursor_visits_older_sources_and_reuses_existing_records():void {
  $d=$this->setupDecision();config(['decision_learning.batch_limit'=>2]);$s=AnalyticsSnapshot::create(['entity_type'=>'product','entity_id'=>$d->product_id,'warehouse_id'=>0,'snapshot_date'=>today(),'observed_at'=>now(),'feature_version'=>'v1','facts'=>['unit'=>'pcs','available'=>5]]);
  for($n=0;$n<5;$n++)AnalyticsPrediction::create(['entity_type'=>'product','entity_id'=>$d->product_id,'prediction_type'=>'demand_forecast','model_key'=>'inventory-demand-v1','model_version'=>'cursor-'.$n,'input_feature_version'=>'v1','analytics_snapshot_id'=>$s->id,'generated_at'=>now(),'valid_until'=>now()->addDays(30),'value'=>['horizon'=>30]]);
  $this->learning()->maintain();$this->assertEquals(2,Record::where('kind','prediction')->count());$this->learning()->maintain();$this->assertEquals(4,Record::where('kind','prediction')->count());$this->learning()->maintain();$this->assertEquals(5,Record::where('kind','prediction')->count());$this->learning()->maintain();$this->assertEquals(5,Record::where('kind','prediction')->count());
 }
 public function test_late_import_does_not_make_ancient_evidence_recent():void {
  $this->setupDecision();$e=$this->experiment();$this->pairs($e['id'],49);$p=Record::where('kind','comparison')->first()->payload;$p['product_id']=99;$p['window']=['from'=>today()->subDays(300)->toDateString(),'to'=>today()->subDays(293)->toDateString()];
  $this->learning()->append('late-old-window','comparison','inventory','REPLENISHMENT_DECISION',$p,null,null,$p['window']['from']);$r=$this->learning()->comparison($e['id']);$this->assertEquals(49,$r['comparison']['independent_samples']);$this->assertFalse($r['comparison']['eligible_for_human_review']);
 }
}
