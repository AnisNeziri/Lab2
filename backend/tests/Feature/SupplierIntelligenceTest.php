<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB,Cache};
use Illuminate\Support\Str;
use App\Models\{Supplier,PurchaseOrder,Category,Product,Warehouse,GoodsReceipt,Shipment,ShipmentMilestone,AnalyticsPrediction,SupplierDeliveryRisk,BusinessEvent,Company,User,AnalyticsDataset,SupplierLeadModel,InventoryModelDecision};
use App\Services\{SupplierHistoryService,SupplierIntelligenceService,SupplierLearningService,InventoryIntelligenceService,InventoryIntelligencePlanner};

class SupplierIntelligenceTest extends TestCase {
 use RefreshDatabase;
 private Supplier $supplier;private Product $product;private Warehouse $warehouse;
 protected function setUp():void {parent::setUp();$this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$this->travelTo(now()->setDate(2026,10,1)->setTime(12,0));
  $this->supplier=Supplier::create(['name'=>'Genuine supplier']);$c=Category::create(['name'=>'Hardware']);$this->product=Product::create(['name'=>'Handle','sku'=>'V3-1','category_id'=>$c->id,'unit'=>'pcs','quantity'=>10,'price'=>2]);$this->warehouse=Warehouse::create(['name'=>'Warehouse','code'=>'V3']);}
 private function order(int $ago=20,int $promiseAgo=10):PurchaseOrder {
  $p=PurchaseOrder::create(['supplier_id'=>$this->supplier->id,'po_number'=>'V3-'.Str::uuid(),'status'=>'ordered','ordered_at'=>today()->subDays($ago),'expected_at'=>today()->subDays($promiseAgo),'currency'=>'EUR']);
  $i=$p->items()->create(['product_id'=>$this->product->id,'description'=>'Handle','unit'=>'box','inventory_unit'=>'pcs','conversion_factor'=>100,'quantity'=>1,'base_quantity'=>100,'unit_price'=>100,'line_total'=>100]);
  $change=$p->changes()->create(['company_id'=>$p->company_id,'user_id'=>Auth::id(),'action'=>'created','new_values'=>['status'=>'ordered','ordered_at'=>$p->ordered_at->toDateString(),'expected_at'=>$p->expected_at->toDateString(),'items'=>[['id'=>$i->id,'product_id'=>$i->product_id,'unit'=>'box','quantity'=>1]]]]);
  $change->forceFill(['created_at'=>$p->ordered_at->midDay()])->save();return $p;
 }
 private function receive(PurchaseOrder $p,float $qty,int $ago=5):void {
  $r=GoodsReceipt::create(['purchase_order_id'=>$p->id,'warehouse_id'=>$this->warehouse->id,'receipt_number'=>'GR-'.Str::uuid(),'status'=>'posted','received_at'=>today()->subDays($ago),'idempotency_key'=>(string)Str::uuid()]);
  $r->forceFill(['created_at'=>today()->subDays($ago)])->save();
  $r->items()->create(['purchase_order_item_id'=>$p->items->first()->id,'product_id'=>$this->product->id,'ordered_unit'=>'box','inventory_unit'=>'pcs','accepted_quantity'=>$qty/100,'accepted_base_quantity'=>$qty,'conversion_factor'=>100]);
 }
 public function test_partial_receipts_are_not_training_targets_and_complete_order_is_counted_once():void {
  $p=$this->order();$this->receive($p,40,8);$h=app(SupplierHistoryService::class);$r=$h->order($p->fresh());$this->assertFalse($r['complete']);$this->assertNull($r['lead_days']);$this->assertEquals(60,$r['lines'][0]['remaining']);
  $this->receive($p,60,5);$r=$h->order($p->fresh());$this->assertTrue($r['complete']);$this->assertEquals(15,$r['lead_days']);$this->assertEquals(2,$r['partial_receipt_count']);$this->assertSame(1,$h->distribution([$r])['samples']);$this->assertFalse($h->distribution([$r])['eligible']);
 }
 public function test_original_promise_survives_revision_and_missing_evidence_stays_unknown():void {
  $p=$this->order();$original=$p->expected_at->toDateString();$p->changes()->create(['action'=>'updated','old_values'=>['expected_at'=>$original],'new_values'=>['expected_at'=>today()->addDays(10)->toDateString()]]);$p->update(['expected_at'=>today()->addDays(10)]);
  $r=app(SupplierHistoryService::class)->order($p->fresh());$this->assertSame($original,$r['original_promise']);$this->assertNotSame($original,$r['current_promise']);$this->assertNull($r['stages']['customs_days']);
  $risk=app(SupplierIntelligenceService::class)->assess($r,app(SupplierHistoryService::class)->distribution([$r]));$this->assertSame('high',$risk['risk']);$this->assertSame(['unknown'],$risk['causes']);
  $p->changes()->delete();$r=app(SupplierHistoryService::class)->order($p->fresh());$this->assertNull($r['original_promise']);$this->assertFalse($r['ml_eligible']);
 }
 public function test_milestones_separate_transit_from_supplier_fault_and_events_deduplicate():void {
  $p=$this->order();$s=Shipment::create(['tracking_number'=>'V3-TRACK','purchase_order_id'=>$p->id,'supplier_id'=>$this->supplier->id,'transport_mode'=>'sea','status'=>'in_transit']);
  ShipmentMilestone::create(['shipment_id'=>$s->id,'scope_key'=>'shipment','milestone_type'=>'destination_port','status'=>'pending','planned_at'=>today()->subDay(),'source'=>'manual']);
  $service=app(SupplierIntelligenceService::class);$service->refresh($this->supplier->id);$service->refresh($this->supplier->id);
  $r=SupplierDeliveryRisk::firstOrFail();$this->assertSame(['transit'],$r->evidence['causes']);$this->assertFalse($r->evidence['supplier_fault_established']);$this->assertSame(1,BusinessEvent::where('event_type','supplier_delivery.high_risk')->count());
  $this->postJson('/api/supplier-intelligence/risks/'.$r->id.'/feedback',['decision'=>'modified','note'=>'Review carrier delay'])->assertOk();$this->assertSame('modified',$r->fresh()->feedback[0]['decision']);
 }
 public function test_history_quality_insufficient_training_and_read_only_report():void {
  $p=$this->order();$this->receive($p,100);$before=AnalyticsPrediction::count();$this->getJson('/api/suppliers/'.$this->supplier->id.'/intelligence')->assertOk()->assertJsonPath('coverage.completed',1)->assertJsonPath('distribution.eligible',false);
  $this->assertSame($before,AnalyticsPrediction::count());$this->postJson('/api/suppliers/'.$this->supplier->id.'/intelligence/train')->assertOk()->assertJsonPath('status','insufficient_data');$this->assertDatabaseCount('inventory_forecast_models',0);
 }
 public function test_only_future_completed_outcomes_are_evaluated_and_refresh_is_idempotent():void {
  for($i=0;$i<5;$i++){$p=$this->order(30+$i,20+$i);$this->receive($p,100,15+$i);}
  $open=$this->order(2,-8);$service=app(SupplierIntelligenceService::class);$service->refresh($this->supplier->id);$service->refresh($this->supplier->id);
  $p=AnalyticsPrediction::where('entity_id',$open->id)->firstOrFail();$this->assertSame(1,AnalyticsPrediction::where('entity_id',$open->id)->count());$frozen=$p->value;$this->assertNull($p->evaluated_at);
  $this->travel(3)->days();$this->receive($open,100,0);$service->refresh($this->supplier->id);$p->refresh();$this->assertTrue($p->evaluation['eligible']);$this->assertEquals(5,$p->actual_value['lead_days']);$this->assertSame($frozen,$p->value);
  $report=$service->report($this->supplier->id);$this->assertSame(1,$report['performance']['supplier-baseline-v1']['orders']);
 }
 public function test_backdated_completion_is_excluded_from_real_accuracy():void {
  for($i=0;$i<5;$i++){$p=$this->order(30+$i,20+$i);$this->receive($p,100,15+$i);}
  $p=$this->order();$s=app(SupplierIntelligenceService::class);$s->refresh($this->supplier->id);$this->receive($p,100,1);$s->refresh($this->supplier->id);$forecast=AnalyticsPrediction::where('entity_id',$p->id)->firstOrFail();$this->assertFalse($forecast->evaluation['eligible']);
 }
 public function test_tenant_boundaries_and_permission_guards():void {
  $p=$this->order();$id=$this->supplier->id;$other=Company::factory()->create();$user=User::factory()->create(['company_id'=>$other->id,'role'=>'admin','email_verified_at'=>now(),'api_token'=>hash('sha256','other-v3')]);
  $this->withToken('other-v3')->getJson('/api/suppliers/'.$id.'/intelligence')->assertNotFound();$this->postJson('/api/suppliers/'.$id.'/intelligence/refresh')->assertNotFound();
  $this->assertSame(0,SupplierDeliveryRisk::count());
 }
 private function model(string $status='candidate',?SupplierLeadModel $active=null):SupplierLeadModel {
  $d=AnalyticsDataset::create(['version'=>(string)Str::uuid(),'name'=>'supplier_lead_v1','date_from'=>today()->subYear(),'date_to'=>today()->subDay(),'feature_definitions'=>[],'row_count'=>1,'labelled_count'=>50,'quality_status'=>'intelligence_frozen']);
  $a=['version'=>'supplier-lead-v1','training_cutoff'=>today()->subDay()->toDateString()];return SupplierLeadModel::create(['supplier_id'=>$this->supplier->id,'analytics_dataset_id'=>$d->id,'version'=>(string)Str::uuid(),'feature_version'=>'supplier-history-v1','horizon'=>0,'algorithm'=>'supplier_ridge','status'=>$status,'training_cutoff'=>today()->subDay(),'artifact'=>$a,'artifact_hash'=>InventoryIntelligenceService::artifactHash($a),'metrics'=>['mae'=>1,'samples'=>10],'comparison'=>['baseline'=>['mae'=>3,'samples'=>10],'challenger'=>['mae'=>1,'samples'=>10],'incumbent'=>$active?['mae'=>2,'samples'=>10]:null],'quality'=>['samples'=>50],'review'=>['production_version'=>$active?->version]]);
 }
 public function test_manual_governance_reuses_registry_and_keeps_immutable_audit():void {
  $old=$this->model('active');$m=$this->model('candidate',$old);$this->postJson('/api/supplier-intelligence/models/'.$m->id.'/promote',['reason'=>'Validated complete history','expected_production_version'=>$old->version])->assertOk();
  $this->assertSame('superseded',$old->fresh()->status);$this->assertSame('active',$m->fresh()->status);$this->assertDatabaseCount('inventory_model_decisions',1);
  $this->postJson('/api/supplier-intelligence/models/'.$old->id.'/rollback',['reason'=>'Restore previous champion','expected_production_version'=>$m->version])->assertOk();$this->assertSame('active',$old->fresh()->status);$this->assertDatabaseCount('inventory_model_decisions',2);
  $this->getJson('/api/analytics/intelligence/models/'.$m->id)->assertNotFound();
 }
 public function test_replenishment_keeps_high_risk_incoming_separate_without_stock_effects():void {
  $p=$this->order(2,-8);$this->getJson('/api/me')->assertOk();$before=$this->product->fresh()->quantity;
  $s=Shipment::create(['tracking_number'=>'PLAN-TRACK','purchase_order_id'=>$p->id,'supplier_id'=>$this->supplier->id,'transport_mode'=>'sea','status'=>'in_transit']);
  $milestone=ShipmentMilestone::create(['shipment_id'=>$s->id,'scope_key'=>'shipment','milestone_type'=>'destination_port','status'=>'pending','planned_at'=>today()->subDay(),'source'=>'manual']);
  $daily=collect(range(0,29))->map(fn($i)=>['date'=>today()->addDays($i)->toDateString(),'quantity'=>1])->all();$r=app(InventoryIntelligencePlanner::class)->calculate($this->product,$daily,[['supplier_id'=>$this->supplier->id,'name'=>'Supplier','usual_lead_time_days'=>3,'lead_evidence'=>['eligible'=>true,'p90_days'=>7],'pack_size'=>1,'minimum_order_quantity'=>1]]);
  $this->assertEquals(7,$r['lead_time_days']);$this->assertSame($before,$this->product->fresh()->quantity);$this->assertContains($p->id,$r['at_risk_purchase_orders']);$this->assertGreaterThanOrEqual(100,$r['uncertain_incoming']);
  $milestone->update(['actual_at'=>now(),'status'=>'completed']);$r=app(InventoryIntelligencePlanner::class)->calculate($this->product,$daily,[]);$this->assertSame([],$r['at_risk_purchase_orders']);
 }
 public function test_local_training_freezes_dataset_and_reuses_identical_input():void {
  for($i=0;$i<50;$i++){$ago=2200-$i*30;$lead=3+$i%6;$p=$this->order($ago,$ago-$lead);$this->receive($p,100,$ago-$lead);}
  $r=app(SupplierLearningService::class)->train($this->supplier->id);$this->assertSame('candidate_ready',$r['status']);$m=SupplierLeadModel::firstOrFail();$this->assertEquals(50,$m->quality['samples']);$this->assertTrue(app(SupplierLearningService::class)->gates($m,null)['eligible']);
  $this->assertTrue(app(SupplierLearningService::class)->train($this->supplier->id)['reused']);$this->assertDatabaseCount('inventory_forecast_models',1);$this->assertDatabaseCount('analytics_datasets',1);
 }
 public function test_unavailable_python_never_blocks_risk_refresh_or_claims_champion_accuracy():void {
  for($i=0;$i<5;$i++){$p=$this->order(30+$i,20+$i);$this->receive($p,100,15+$i);}
  $p=$this->order(2,-8);$m=$this->model('active');config(['inventory_intelligence.python'=>'missing-python-v3']);app(SupplierIntelligenceService::class)->refresh($this->supplier->id);
  $forecast=AnalyticsPrediction::where('entity_id',$p->id)->firstOrFail();$this->assertSame('supplier-baseline-v1',$forecast->model_version);$this->assertSame('historical_median',$forecast->value['method']);$this->assertSame('local_model_unavailable_baseline_used',$forecast->value['warning']);
 }
 public function test_changed_order_scope_is_not_a_valid_unchanged_training_example():void {
  $p=$this->order();$p->items()->update(['quantity'=>2,'base_quantity'=>200]);$this->receive($p,200);$row=app(SupplierHistoryService::class)->order($p->fresh());$this->assertTrue($row['complete']);$this->assertFalse($row['ml_eligible']);
 }
 public function test_legacy_completed_order_without_receipts_is_neither_open_risk_nor_training_evidence():void {
  $p=$this->order();$p->update(['status'=>'completed']);$h=app(SupplierHistoryService::class);$r=$h->order($p->fresh());$this->assertSame(0,$h->distribution([$r])['samples']);$this->assertSame('closed',app(SupplierIntelligenceService::class)->assess($r,$h->distribution([$r]))['risk']);
 }
}
