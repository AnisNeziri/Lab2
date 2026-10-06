<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB,Cache};
use Illuminate\Support\Str;
use App\Models\{Product,Category,AnalyticsSnapshot,AnalyticsDataset,AnalyticsDatasetRow,AnalyticsPrediction,InventoryForecastModel,InventoryModelDecision,InventoryIntelligenceAlert,DailySale,StockMovement,Company,User,BusinessEvent,InventoryRecommendation};
use App\Services\{IntelligenceObservationService,IntelligencePerformanceService,InventoryLearningService,InventoryIntelligenceService,InventoryIntelligenceDatasetAdapter};

class InventoryLearningTest extends TestCase {
 use RefreshDatabase;
 private function product():Product {
    $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$c=Category::create($this->tenantAttributes(['name'=>'Learning']));
    $p=Product::create($this->tenantAttributes(['name'=>'Fabric','sku'=>'LEARN-1','category_id'=>$c->id,'unit'=>'m','quantity'=>100,'min_quantity'=>2,'price'=>5,'selling_price'=>5]));
    $p->forceFill(['created_at'=>now()->subYear()])->save();return $p;
 }
 private function snapshot(Product $p,int $ago,float $available=20):AnalyticsSnapshot {
    return AnalyticsSnapshot::create(['entity_type'=>'product','entity_id'=>$p->id,'warehouse_id'=>0,'snapshot_date'=>today()->subDays($ago)->toDateString(),'observed_at'=>today()->subDays($ago)->endOfDay(),'feature_version'=>'observed-v1','facts'=>['unit'=>$p->unit,'available'=>$available,'reserved'=>2,'incoming'=>10]]);
 }
 private function sale(Product $p,int $ago,float $quantity=2.125):void {
    $s=DailySale::create(['sale_number'=>'LEARN-'.Str::uuid(),'sale_date'=>today()->subDays($ago),'status'=>'finalized','inventory_applied_at'=>today()->subDays($ago)->midDay(),'finalized_at'=>today()->subDays($ago)->endOfDay(),'total_amount'=>10,'total_quantity'=>$quantity,'paid_amount'=>10,'created_by'=>Auth::id()]);
    $s->items()->create(['product_id'=>$p->id,'product_name'=>$p->name,'line_number'=>1,'unit'=>$p->unit,'quantity'=>$quantity,'base_quantity'=>$quantity,'unit_price'=>1,'line_total'=>$quantity]);
 }
 private function model(Product $p,string $status='candidate',?InventoryForecastModel $current=null,array $quality=[]):InventoryForecastModel {
    $anchor=AnalyticsSnapshot::where('entity_type','product')->where('entity_id',$p->id)->first()??$this->snapshot($p,1);
    $d=AnalyticsDataset::create(['company_id'=>$p->company_id,'version'=>(string)Str::uuid(),'name'=>'demand_forecasting_v1','date_from'=>today()->subDays(140),'date_to'=>today()->subDays(40),'feature_definitions'=>[],'row_count'=>1,'labelled_count'=>1,'quality_status'=>'intelligence_frozen']);
    AnalyticsDatasetRow::create(['analytics_dataset_id'=>$d->id,'analytics_snapshot_id'=>$anchor->id,'values'=>['unit'=>$p->unit]]);
    $artifact=['version'=>'demand-v1','algorithm'=>'seasonal_mean','parameters'=>[],'training_cutoff'=>today()->subDays(40)->toDateString()];
    $metrics=['mae'=>$current?.95:1,'horizon_mae'=>$current?1.9:2,'wape'=>10,'bias'=>0,'blocks'=>4,'observations'=>28];
    return InventoryForecastModel::create(['product_id'=>$p->id,'analytics_dataset_id'=>$d->id,'version'=>(string)Str::uuid(),'feature_version'=>'demand-daily-v1','horizon'=>7,'algorithm'=>'seasonal_mean','status'=>$status,'training_cutoff'=>$artifact['training_cutoff'],'artifact'=>$artifact,'artifact_hash'=>InventoryIntelligenceService::artifactHash($artifact),'metrics'=>$metrics,'comparison'=>['seasonal_mean'=>$metrics,'incumbent'=>$current?->metrics],'quality'=>$quality+['observed_days'=>100,'recent_observed_days'=>90,'recent_calendar_days'=>90],'review'=>['unit'=>$p->unit,'production_version'=>$current?->version]]);
 }
 private function forecast(Product $p,InventoryForecastModel $model,int $firstAgo=7,float $quantity=3.125):AnalyticsPrediction {
    $daily=collect(range(0,6))->map(fn($i)=>['date'=>today()->subDays($firstAgo)->addDays($i)->toDateString(),'quantity'=>$quantity])->all();
    return AnalyticsPrediction::create(['company_id'=>$p->company_id,'prediction_type'=>'inventory_demand','entity_type'=>'product','entity_id'=>$p->id,'model_key'=>'inventory-demand-v1','model_version'=>$model->version,'input_feature_version'=>'demand-daily-v1','analytics_snapshot_id'=>AnalyticsSnapshot::where('entity_type','product')->where('entity_id',$p->id)->firstOrFail()->id,
        'value'=>['horizon'=>7,'cutoff'=>today()->subDays($firstAgo+1)->toDateString(),'unit'=>$p->unit,'daily'=>$daily,'baseline_daily'=>array_map(fn($d)=>['date'=>$d['date'],'quantity'=>2.125],$daily),'training_cutoff'=>$model->training_cutoff->toDateString(),'total'=>7*$quantity],
        'generated_at'=>today()->subDays($firstAgo),'valid_until'=>today()->subDays($firstAgo-1)]);
 }
 public function test_observations_are_idempotent_frozen_and_missing_stock_is_unknown():void {
    $p=$this->product();$this->snapshot($p,1);$this->sale($p,1);$s=app(IntelligenceObservationService::class);
    $this->assertSame(7,$s->collectProduct($p,today()->subDays(7)->toDateString(),today()->subDay()->toDateString()));
    $this->assertSame(0,$s->collectProduct($p,today()->subDays(7)->toDateString(),today()->subDay()->toDateString()));
    $rows=AnalyticsSnapshot::where('entity_type','demand_observation')->orderBy('snapshot_date')->get();
    $this->assertNull($rows->first()->facts['available']);$this->assertNull($rows->first()->facts['demand']);
    $this->assertEquals(2.125,$rows->last()->facts['demand']);$this->assertTrue($rows->last()->facts['potentially_censored']);
    $p->update(['quantity'=>999]);$this->sale($p,1,9);$s->collectProduct($p,today()->subDays(7)->toDateString(),today()->subDay()->toDateString());
    $this->assertEquals(2.125,$rows->last()->fresh()->facts['demand']);
    $this->postJson('/api/analytics/intelligence/products/'.$p->id.'/observations',['from'=>today()->toDateString(),'to'=>today()->toDateString()])->assertUnprocessable();
 }
 public function test_intraday_stockout_is_censored_even_when_snapshot_has_stock():void {
    $p=$this->product();$this->snapshot($p,1);$this->sale($p,1);
    StockMovement::create(['product_id'=>$p->id,'type'=>'out','quantity'=>2,'quantity_before'=>2,'quantity_after'=>0,'affects_company_quantity'=>true,'unit_snapshot'=>'m','occurred_at'=>today()->subDay()->midDay(),'reason'=>'Test completed sale']);
    app(IntelligenceObservationService::class)->collectProduct($p,today()->subDay()->toDateString(),today()->subDay()->toDateString());
    $f=AnalyticsSnapshot::where('entity_type','demand_observation')->firstOrFail()->facts;
    $this->assertTrue($f['stockout']);$this->assertNull($f['demand']);$this->assertEquals(2.125,$f['sales_quantity']);
    $history=app(InventoryIntelligenceDatasetAdapter::class)->history($p,today()->subDay()->toDateString());$this->assertNull(last($history['series'])['demand']);
 }
 public function test_completed_boundary_exact_metrics_baseline_and_no_duplicate_evaluation():void {
    $p=$this->product();for($i=0;$i<=6;$i++){$this->snapshot($p,$i);$this->sale($p,$i);}$m=$this->model($p,'active');$forecast=$this->forecast($p,$m,6);
    $s=app(IntelligencePerformanceService::class);$this->assertSame(0,$s->evaluate());$this->assertNull($forecast->fresh()->evaluated_at);
    $this->travel(1)->days();$this->assertSame(1,$s->evaluate());$e=$forecast->fresh()->evaluation;
    $this->assertTrue($e['eligible']);$this->assertSame('completed',$e['status']);$this->assertEquals(7,$e['absolute_error']);$this->assertEquals(7,$e['signed_error']);$this->assertEquals(1,$e['mae']);$this->assertEquals(0,$e['baseline_mae']);
    $this->assertEqualsWithDelta(100*7/(7*2.125),$e['wape'],.00001);$this->assertSame(0,$s->evaluate());
    $this->assertCount(7,$forecast->fresh()->actual_value['days']);
    $this->assertEquals($forecast->generated_at,$forecast->fresh()->generated_at);
 }
 public function test_censored_period_is_incomplete_not_real_accuracy():void {
    $p=$this->product();for($i=1;$i<=7;$i++){$this->snapshot($p,$i,$i===3?0:20);$this->sale($p,$i);}$m=$this->model($p,'active');$f=$this->forecast($p,$m);
    $s=app(IntelligencePerformanceService::class);$s->evaluate();$this->assertFalse($f->fresh()->evaluation['eligible']);$this->assertSame('incomplete',$f->fresh()->evaluation['status']);
    $this->assertSame('insufficient_real_data',$s->report($p->id,7)['summary']['status']);
 }
 public function test_forecast_trained_on_future_data_is_never_scored_as_real_accuracy():void {
    $p=$this->product();$m=$this->model($p,'active');$f=$this->forecast($p,$m);
    $f->update(['value'=>array_merge($f->value,['training_cutoff'=>today()->subDay()->toDateString()])]);
    app(IntelligencePerformanceService::class)->evaluate();
    $this->assertSame('invalid_period',$f->fresh()->evaluation['status']);$this->assertFalse($f->fresh()->evaluation['eligible']);
 }
 public function test_manual_promotion_and_rollback_preserve_old_predictions_and_audit():void {
    $p=$this->product();$old=$this->model($p,'active');$f=$this->forecast($p,$old);$frozen=$f->value;$candidate=$this->model($p,'candidate',$old);
    $body=['reason'=>'Reviewed matching historical windows','expected_production_version'=>$old->version];
    $this->postJson('/api/analytics/intelligence/models/'.$candidate->id.'/promote',$body)->assertOk()->assertJsonPath('status','active');
    $this->assertSame('superseded',$old->fresh()->status);$this->assertSame($frozen,$f->fresh()->value);
    $this->postJson('/api/analytics/intelligence/models/'.$candidate->id.'/promote',$body)->assertOk()->assertJsonPath('reused',true);$this->assertDatabaseCount('inventory_model_decisions',1);
    $this->postJson('/api/analytics/intelligence/models/'.$old->id.'/rollback',['reason'=>'Rollback after review','expected_production_version'=>$candidate->version])->assertOk();
    $this->assertSame('active',$old->fresh()->status);$this->assertSame('superseded',$candidate->fresh()->status);$this->assertSame($frozen,$f->fresh()->value);$this->assertDatabaseCount('inventory_model_decisions',2);
 }
 public function test_unsafe_candidate_and_changed_production_are_rejected():void {
    $p=$this->product();$old=$this->model($p,'active');$bad=$this->model($p,'candidate',$old,['recent_observed_days'=>10]);
    $this->postJson('/api/analytics/intelligence/models/'.$bad->id.'/promote',['reason'=>'Try invalid quality','expected_production_version'=>$old->version])->assertUnprocessable();
    $this->postJson('/api/analytics/intelligence/models/'.$bad->id.'/promote',['reason'=>'Stale review','expected_production_version'=>'wrong-version'])->assertStatus(409);
    $this->assertSame('active',$old->fresh()->status);$this->assertDatabaseCount('inventory_model_decisions',0);
 }
 public function test_tenant_and_permission_boundaries():void {
    $p=$this->product();$m=$this->model($p);$role=\App\Models\Role::where('slug','admin')->firstOrFail();$role->permissions()->detach(\App\Models\Permission::where('slug','analytics.ml_datasets')->value('id'));Cache::forget('role_permissions:admin');
    $this->postJson('/api/analytics/intelligence/models/'.$m->id.'/promote',['reason'=>'No permission','expected_production_version'=>null])->assertForbidden();
    $other=Company::factory()->create();User::factory()->create(['company_id'=>$other->id,'role'=>'admin','api_token'=>hash('sha256','learning-other')]);
    $this->withHeader('Authorization','Bearer learning-other')->getJson('/api/analytics/intelligence/products/'.$p->id.'/performance')->assertNotFound();
    $this->getJson('/api/analytics/intelligence/models/'.$m->id)->assertNotFound();
 }
 public function test_sustained_drift_requires_independent_periods_and_alerts_are_deduplicated():void {
    $p=$this->product();$m=$this->model($p,'active');$service=app(IntelligencePerformanceService::class);
    foreach([28,21,14] as $i=>$ago){$f=$this->forecast($p,$m,$ago,4.25);$from=$f->value['daily'][0]['date'];$to=last($f->value['daily'])['date'];
        $f->update(['evaluated_at'=>now(),'actual_value'=>['observed_demand'=>14.875],'evaluation'=>['version'=>2,'eligible'=>true,'status'=>'completed','from'=>$from,'to'=>$to,'observed_days'=>7,'mae'=>2.125,'baseline_mae'=>0,'absolute_error'=>14.875,'signed_error'=>14.875,'actual_total'=>14.875,'wape'=>100,'bias'=>2.125]]);
        $service->monitor($p);if($i<2)$this->assertSame(0,BusinessEvent::where('event_type','forecast_accuracy_degraded')->count());
    }
    $this->assertSame(2,BusinessEvent::where('event_type','forecast_accuracy_degraded')->count());$service->monitor($p);
    $this->assertSame(2,BusinessEvent::where('event_type','forecast_accuracy_degraded')->count());
    $this->assertDatabaseHas('inventory_intelligence_alerts',['product_id'=>$p->id,'code'=>'accuracy_degraded','status'=>'open']);
 }
 public function test_retraining_requires_new_days_elapsed_time_and_measured_reason():void {
    $p=$this->product();$m=$this->model($p,'active');$m->forceFill(['created_at'=>now()->subDays(10)])->save();
    for($i=1;$i<=14;$i++){if($i>1)$this->snapshot($p,$i);$this->sale($p,$i);}
    $s=app(InventoryLearningService::class);$this->assertFalse($s->shouldRetrain($p)['justified']);
    InventoryIntelligenceAlert::create(['product_id'=>$p->id,'horizon'=>7,'code'=>'accuracy_degraded','status'=>'open','evidence'=>['windows'=>3],'opened_at'=>now()]);
    $this->assertTrue($s->shouldRetrain($p)['justified']);
    $m->forceFill(['created_at'=>now()])->save();$this->assertFalse($s->shouldRetrain($p)['justified']);
 }
 public function test_zero_demand_has_no_percentage_and_pending_periods_are_visible():void {
    $p=$this->product();$other=$p->replicate();$other->sku='ZERO-OTHER';$other->save();
    for($i=1;$i<=7;$i++){$this->snapshot($p,$i);$this->sale($other,$i,1);}
    $m=$this->model($p,'active');$done=$this->forecast($p,$m);$pending=$this->forecast($p,$m,0);
    $service=app(IntelligencePerformanceService::class);$service->evaluate();
    $this->assertNull($done->fresh()->evaluation['wape']);$this->assertEquals(0,$done->fresh()->evaluation['actual_total']);
    $report=$service->report($p->id,7);$this->assertSame('pending',$report['pending'][0]['status']);$this->assertSame($pending->id,$report['pending'][0]['id']);
    $this->assertSame('insufficient_evidence',$report['health']['state']);
 }
 public function test_promotion_requires_configured_improvement_not_just_a_new_version():void {
    $p=$this->product();$m=$this->model($p,'active');$candidate=$this->model($p,'candidate',$m);
    config(['inventory_intelligence.promotion_min_improvement_percent'=>10]);
    $gate=app(InventoryLearningService::class)->gates($candidate,$m);
    $this->assertFalse($gate['eligible']);$this->assertContains('insufficient_improvement',$gate['reasons']);
    $this->postJson('/api/analytics/intelligence/models/'.$candidate->id.'/promote',['reason'=>'Not enough improvement','expected_production_version'=>$m->version])->assertUnprocessable();
    $this->assertSame('active',$m->fresh()->status);
 }
 public function test_failed_training_keeps_production_and_records_reproducible_failure():void {
    $p=$this->product();$m=$this->model($p,'active');$f=$this->forecast($p,$m);$frozen=$f->value;
    for($i=1;$i<=14;$i++){if($i>1)$this->snapshot($p,$i);$this->sale($p,$i);}
    $this->mock(\App\Contracts\DemandForecastProvider::class,function($mock){$mock->shouldReceive('train')->once()->andThrow(new \App\Exceptions\ForecastUnavailableException('Local trainer unavailable.'));$mock->shouldReceive('predict')->andReturn(['status'=>'insufficient_data','quality'=>[],'results'=>[]]);});
    $this->postJson('/api/analytics/intelligence/products/'.$p->id.'/train')->assertStatus(503);
    $this->assertSame('active',$m->fresh()->status);$this->assertSame($frozen,$f->fresh()->value);
    $history=app(IntelligencePerformanceService::class)->report($p->id,7)['training_history'];
    $this->assertSame('failed',$history[0]['status']);$this->assertNotEmpty($history[0]['details']['dataset_version']);$this->assertSame(64,strlen($history[0]['details']['input_hash']));
 }
 public function test_insufficient_unchanged_training_is_not_repeated():void {
    $p=$this->product();$this->snapshot($p,1);
    $this->mock(\App\Contracts\DemandForecastProvider::class,fn($m)=>$m->shouldReceive('train')->once()->andReturn(['status'=>'insufficient_data','quality'=>['observed_days'=>0],'results'=>[]]));
    $service=app(InventoryLearningService::class);$service->prepare($p->id);$this->assertTrue($service->prepare($p->id)['reused']);
    $this->assertSame(1,\App\Models\ActivityLog::where('action','inventory_intelligence.training_started')->count());
 }
 public function test_observation_contains_base_quantity_provenance_and_adjustments():void {
    $p=$this->product();$this->snapshot($p,1);$this->sale($p,1,2.125);
    StockMovement::create(['product_id'=>$p->id,'type'=>'in','quantity'=>.375,'quantity_before'=>1,'quantity_after'=>1.375,'affects_company_quantity'=>true,'unit_snapshot'=>'m','movement_code'=>'manual_adjustment_in','occurred_at'=>today()->subDay()->midDay(),'reason'=>'Count correction']);
    app(IntelligenceObservationService::class)->collectProduct($p,today()->subDay()->toDateString(),today()->subDay()->toDateString());
    $facts=AnalyticsSnapshot::where('entity_type','demand_observation')->firstOrFail()->facts;
    $this->assertEquals(2.125,$facts['net_sales_quantity']);$this->assertEquals(.375,$facts['stock_adjustment_quantity']);$this->assertCount(1,$facts['provenance']['sales']);$this->assertCount(1,$facts['provenance']['stock_movement_ids']);$this->assertNotEmpty($facts['reconciled_at']);
 }
 public function test_scheduled_jobs_resume_without_duplicate_observations_or_alerts():void {
    $p=$this->product();$this->snapshot($p,1);config(['inventory_intelligence.scheduled_training'=>false]);
    $service=app(\App\Services\IntelligenceMaintenanceService::class);$service->run();
    $count=AnalyticsSnapshot::where('entity_type','demand_observation')->count();$events=BusinessEvent::count();
    $service->run();$this->assertGreaterThan(0,$count);$this->assertSame($count,AnalyticsSnapshot::where('entity_type','demand_observation')->count());$this->assertSame($events,BusinessEvent::count());
    $this->assertDatabaseHas('maintenance_health',['company_id'=>$p->company_id,'task'=>'inventory_intelligence','status'=>'healthy']);
 }
 public function test_no_new_observations_cannot_force_a_new_challenger():void {
    $p=$this->product();$this->model($p,'active');
    $this->mock(\App\Contracts\DemandForecastProvider::class,function($m){$m->shouldNotReceive('train');$m->shouldReceive('predict')->andReturn(['status'=>'insufficient_data','quality'=>[],'results'=>[]]);});
    $this->assertSame('production_refreshed',app(InventoryLearningService::class)->prepare($p->id)['status']);$this->assertDatabaseCount('inventory_forecast_models',1);
 }
 public function test_receipt_evidence_uses_posted_base_quantity_and_preserves_knowledge_time():void {
    $p=$this->product();$this->snapshot($p,1);$this->sale($p,1);
    $supplier=\App\Models\Supplier::create(['name'=>'Evidence supplier']);$warehouse=\App\Models\Warehouse::create(['name'=>'Evidence warehouse','code'=>'EVID']);
    $po=\App\Models\PurchaseOrder::create(['supplier_id'=>$supplier->id,'po_number'=>'EVID-PO','status'=>'ordered','ordered_at'=>today()->subDays(5),'currency'=>'EUR']);
    $item=$po->items()->create(['product_id'=>$p->id,'description'=>$p->name,'unit'=>'roll','quantity'=>2,'base_quantity'=>50.75,'unit_price'=>10,'line_total'=>20]);
    $receipt=\App\Models\GoodsReceipt::create(['purchase_order_id'=>$po->id,'warehouse_id'=>$warehouse->id,'receipt_number'=>'EVID-GR','received_at'=>today()->subDay()->midDay(),'status'=>'posted','idempotency_key'=>(string)Str::uuid()]);
    $receipt->forceFill(['created_at'=>today()->subDay()->midDay()])->save();
    $receipt->items()->create(['purchase_order_item_id'=>$item->id,'product_id'=>$p->id,'ordered_unit'=>'roll','inventory_unit'=>'m','accepted_quantity'=>1,'accepted_base_quantity'=>25.375,'conversion_mode'=>'fixed','conversion_factor'=>25.375]);
    app(IntelligenceObservationService::class)->collectProduct($p,today()->subDay()->toDateString(),today()->subDay()->toDateString());
    $facts=AnalyticsSnapshot::where('entity_type','demand_observation')->firstOrFail()->facts;
    $this->assertEquals(25.375,$facts['accepted_receipt_quantity']);$this->assertSame([$receipt->id],$facts['provenance']['receipt_ids']);
    $m=$this->model($p,'active');$f=$this->forecast($p,$m);
    InventoryRecommendation::create(['product_id'=>$p->id,'analytics_prediction_id'=>$f->id,'risk'=>'normal','status'=>'superseded','explanation'=>['available'=>10,'base_quantity'=>5]]);
    $outcome=app(IntelligencePerformanceService::class)->outcomes($p)[0];$this->assertSame('ignored',$outcome['decision']);$this->assertFalse($outcome['causal_claim']);
    $audit=\App\Models\ActivityLog::where('action','inventory_intelligence.outcome_observed')->firstOrFail();$this->assertNotEmpty($audit->new_value['available_for_features_after']);
 }
}
