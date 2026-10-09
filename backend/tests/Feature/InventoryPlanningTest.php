<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Product,Category,Supplier,ProductSupplier,AnalyticsSnapshot,InventoryRecommendation,PurchaseRequest,Company,User,Warehouse,WarehouseStock,PurchaseOrder,StockTransfer,StockMovement};
use App\Services\{InventoryPlanningMath,InventoryPlanningService,InventorySnapshotService,InventoryPlanningInsights};

class InventoryPlanningTest extends TestCase {
 use RefreshDatabase;
 private function product():Product {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$c=Category::create($this->tenantAttributes(['name'=>'Parts']));
  $p=Product::create($this->tenantAttributes(['name'=>'Handle','sku'=>'V4-1','category_id'=>$c->id,'unit'=>'pcs','quantity'=>5,'min_quantity'=>2,'purchase_price'=>2,'price'=>5,'created_at'=>now()->subYear()]));$p->forceFill(['created_at'=>now()->subYear()])->save();
  $s=Supplier::create($this->tenantAttributes(['name'=>'Factory','is_active'=>true]));ProductSupplier::create($this->tenantAttributes(['product_id'=>$p->id,'supplier_id'=>$s->id,'purchase_price'=>2,'currency'=>'EUR','exchange_rate_to_base'=>1,'usual_lead_time_days'=>3,'pack_size'=>5,'minimum_order_quantity'=>10,'is_active'=>true]));
  for($d=1;$d<=90;$d++)AnalyticsSnapshot::create($this->tenantAttributes(['entity_type'=>'demand_observation','entity_id'=>$p->id,'warehouse_id'=>0,'snapshot_date'=>today()->subDays($d),'observed_at'=>today()->subDays($d)->endOfDay(),'feature_version'=>'observed-v2','facts'=>['unit'=>'pcs','demand'=>10,'sales_quantity'=>10,'returns_quantity'=>0,'available'=>100,'stockout'=>false,'quality'=>'observed','complete'=>true,'potentially_censored'=>false]]));
  return $p;
 }
 private function input():array {return ['policy'=>['service_level'=>.95,'review_days'=>7],'history'=>array_map(fn($d)=>['date'=>today()->subDays(40-$d)->toDateString(),'demand'=>$d%2?8:12,'censored'=>false,'returned'=>0],range(0,39)),'lead'=>['mean'=>3,'variance'=>4,'p90'=>5],'supplier'=>['usual_lead_time_days'=>3,'pack_size'=>5,'minimum_order_quantity'=>10,'purchase_price'=>'2.00','currency'=>'EUR'],'minimum_safety'=>2,'reorder_floor'=>0,'daily'=>[],'forecast_source'=>'history','stock'=>['available'=>5,'reserved'=>3,'committed_outgoing'=>2],'firm_incoming'=>[],'factor'=>1,'unit'=>'pcs','unit_fractional'=>false,'base_fractional'=>false,'currency'=>'EUR'];}
 public function test_variability_formula_and_deterministic_reorder_point():void {
  $i=$this->input();$r=app(InventoryPlanningMath::class)->calculate($i);$variance=40*4/39;
  $this->assertEqualsWithDelta(1.644853627*sqrt(3*$variance+100*4),$r['safety_stock'],.001);
  $this->assertEqualsWithDelta(50+$r['safety_stock'],$r['reorder_point'],.001);$this->assertSame('independent_demand_lead_variance',$r['method']);$this->assertSame($r,app(InventoryPlanningMath::class)->calculate($i));
 }
 public function test_unknown_censored_outlier_and_intermittent_fallbacks():void {
  $i=$this->input();$i['history']=array_slice($i['history'],0,4);$r=app(InventoryPlanningMath::class)->calculate($i);$this->assertNull($r['base_quantity']);$this->assertSame('configured_floor',$r['method']);
  $i=$this->input();foreach($i['history'] as &$d)$d['demand']=null;unset($d);$this->assertSame('insufficient_data',app(InventoryPlanningMath::class)->calculate($i)['risk']);
  $i=$this->input();$i['history'][0]['demand']=10000;$this->assertTrue(app(InventoryPlanningMath::class)->calculate($i)['quality']['outliers']);
  $i=$this->input();foreach($i['history'] as $k=>&$d)$d['demand']=$k%5?0:10;unset($d);$this->assertSame('empirical_lead_window',app(InventoryPlanningMath::class)->calculate($i)['method']);
 }
 public function test_fractional_conversion_moq_budget_and_zero_lead():void {
  $i=$this->input();$i['factor']=2.5;$i['unit']='roll';$i['base_fractional']=true;$i['supplier']['pack_size']=.75;
  $r=app(InventoryPlanningMath::class)->calculate($i,['budget'=>'50.00']);$this->assertSame(7.5,$r['step']);$this->assertLessThanOrEqual(50,(float)$r['base_cost']);$this->assertGreaterThan(0,$r['shortfall']);$this->assertEquals(round($r['quantity']),$r['quantity']);
  $i=$this->input();$i['lead']=['mean'=>0,'variance'=>0,'p90'=>0];$r=app(InventoryPlanningMath::class)->calculate($i);$this->assertSame(0,$r['lead_days']);$this->assertEquals(0,$r['expected_lead_demand']);
  $i['lead']=[];unset($i['supplier']['usual_lead_time_days']);$this->assertNull(app(InventoryPlanningMath::class)->calculate($i)['base_quantity']);
 }
 public function test_scenarios_read_only_and_ml_offline_fallback():void {
  $p=$this->product();$r=$this->postJson('/api/analytics/planning/products/'.$p->id.'/scenarios',['scenarios'=>[[],['delay_days'=>8,'demand_multiplier'=>1.5],['budget'=>'30.00']]])->assertOk()->assertJsonPath('read_only',true)->json();
  $this->assertCount(3,$r['scenarios']);$this->assertSame('qualified_history_mean_or_unavailable',$r['scenarios'][0]['quality']['forecast_source']);$this->assertGreaterThan($r['scenarios'][0]['base_quantity'],$r['scenarios'][1]['base_quantity']);
  $this->assertDatabaseCount('purchase_requests',0);$this->assertDatabaseCount('inventory_recommendations',0);$this->assertDatabaseCount('analytics_predictions',0);$this->assertEquals(5,$p->fresh()->quantity);
 }
 public function test_policy_version_save_stale_review_duplicate_draft_and_tenant_isolation():void {
  $p=$this->product();$this->postJson('/api/analytics/planning/products/'.$p->id.'/policy',['service_level'=>.98,'priority'=>5,'review_days'=>7])->assertOk();
  $one=$this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk()->json();$this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk()->assertJsonPath('recommendation_id',$one['recommendation_id']);
  $body=['recommendations'=>[$one['recommendation_id']],'budget'=>'500.00'];$review=$this->postJson('/api/analytics/planning/consolidate',$body)->assertOk()->json();$body['review_fingerprint']=$review['review_fingerprint'];
  $p->update(['quantity'=>6]);$this->postJson('/api/analytics/planning/purchase-requests',$body)->assertStatus(409);
  $fresh=$this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk()->json();$body['recommendations']=[$fresh['recommendation_id']];unset($body['review_fingerprint']);$review=$this->postJson('/api/analytics/planning/consolidate',$body)->assertOk()->json();$body['review_fingerprint']=$review['review_fingerprint'];
  $draft=$this->postJson('/api/analytics/planning/purchase-requests',$body)->assertOk()->assertJsonPath('purchase_requests.0.status','draft')->json();$this->postJson('/api/analytics/planning/purchase-requests',$body)->assertOk()->assertJsonPath('reused',true);$this->assertDatabaseCount('purchase_requests',1);$this->assertDatabaseCount('purchase_orders',0);
  $this->assertNull(app(InventoryPlanningService::class)->outcomes($p->id)[0]['proven_savings']);
  $other=Company::factory()->create();User::factory()->create(['company_id'=>$other->id,'role'=>'admin','api_token'=>hash('sha256','planning-other')]);$this->withHeader('Authorization','Bearer planning-other')->getJson('/api/analytics/planning/products/'.$p->id)->assertNotFound();$this->postJson('/api/analytics/planning/consolidate',$body)->assertNotFound();
 }
 public function test_canonical_warehouse_stock_reservations_partial_po_and_transfers_not_demand():void {
  $p=$this->product();$a=Warehouse::create($this->tenantAttributes(['name'=>'A','code'=>'A','is_active'=>true]));$b=Warehouse::create($this->tenantAttributes(['name'=>'B','code'=>'B','is_active'=>true]));
  WarehouseStock::create($this->tenantAttributes(['warehouse_id'=>$a->id,'product_id'=>$p->id,'quantity'=>100,'available_quantity'=>80,'reserved_quantity'=>20]));WarehouseStock::create($this->tenantAttributes(['warehouse_id'=>$b->id,'product_id'=>$p->id,'quantity'=>40,'available_quantity'=>40]));
  $s=ProductSupplier::first()->supplier_id;$po=PurchaseOrder::create($this->tenantAttributes(['warehouse_id'=>$a->id,'supplier_id'=>$s,'po_number'=>'V4-PO','status'=>'partially_received','ordered_at'=>today(),'expected_at'=>today()->addDays(2),'currency'=>'EUR']));$po->items()->create(['product_id'=>$p->id,'description'=>'Handle','unit'=>'pcs','quantity'=>50,'base_quantity'=>50,'received_base_quantity'=>15,'unit_price'=>2,'line_total'=>100]);
  $snapshot=app(InventorySnapshotService::class);$this->assertEquals(80,$snapshot->forProduct($p,null,$a->id)['available']);$this->assertEquals(35,$snapshot->forProduct($p,null,$a->id)['incoming']);$this->assertEquals(0,$snapshot->forProduct($p,null,$b->id)['incoming']);$this->assertEquals(120,$snapshot->forProduct($p)['available']);
  $this->getJson('/api/analytics/planning/products/'.$p->id.'?warehouse_id='.$b->id)->assertOk()->assertJsonPath('plan.scope','warehouse_demand_unavailable')->assertJsonPath('plan.base_quantity',null);
  $this->postJson('/api/analytics/planning/products/'.$p->id.'/policy',['warehouse_id'=>$b->id,'allocation_share'=>.25,'service_level'=>.95,'priority'=>3,'review_days'=>7])->assertOk()->assertJsonPath('plan.scope','declared_company_allocation');
  $t=StockTransfer::create($this->tenantAttributes(['transfer_number'=>'V4-T','source_warehouse_id'=>$a->id,'destination_warehouse_id'=>$b->id,'status'=>'in_transit','dispatched_at'=>now()]));$t->items()->create(['product_id'=>$p->id,'quantity'=>20,'received_quantity'=>5,'damaged_quantity'=>2,'unit_snapshot'=>'pcs']);
  $r=$this->getJson('/api/analytics/planning/products/'.$p->id.'?warehouse_id='.$b->id)->assertOk()->json('plan');$this->assertEquals(13,$r['incoming_transfers'][0]['quantity']);$this->assertNull($r['incoming_transfers'][0]['estimated_at']);$this->assertEquals(2.5,$r['daily'][0]['quantity']);
  $sim=$this->postJson('/api/analytics/planning/products/'.$p->id.'/scenarios',['scenarios'=>[['warehouse_id'=>$b->id,'type'=>'transfer','source_warehouse_id'=>$a->id,'base_quantity'=>10,'transfer_days'=>1]]])->assertOk()->json('scenarios.0');$this->assertTrue($sim['feasible']);$this->assertEquals(80,$sim['source_available']);$this->assertDatabaseCount('stock_transfers',1);
 }
 public function test_budget_multi_product_allocation_order_and_duplicate_product_scopes():void {
  $p=$this->product();$other=$p->replicate();$other->sku='V4-2';$other->name='Second';$other->save();ProductSupplier::create($this->tenantAttributes(array_replace(ProductSupplier::first()->only(['supplier_id','purchase_price','currency','exchange_rate_to_base','usual_lead_time_days','pack_size','minimum_order_quantity','is_active']),['product_id'=>$other->id])));
  $other->forceFill(['created_at'=>$p->created_at])->save();foreach(AnalyticsSnapshot::where('entity_id',$p->id)->get() as $snapshot){$clone=$snapshot->replicate();$clone->entity_id=$other->id;$clone->save();}
  $this->postJson('/api/analytics/planning/products/'.$other->id.'/policy',['service_level'=>.95,'priority'=>5,'review_days'=>7])->assertOk();$a=$this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk()->json('recommendation_id');$b=$this->postJson('/api/analytics/planning/products/'.$other->id.'/save')->assertOk()->json('recommendation_id');
  $r=$this->postJson('/api/analytics/planning/consolidate',['recommendations'=>[$a,$b],'budget'=>'100.00'])->assertOk()->json();$this->assertEquals($other->id,$r['groups'][0]['items'][0]['product']['id']);$this->assertLessThanOrEqual(100,(float)$r['base_commitment']);$this->assertGreaterThan(0,$r['groups'][0]['items'][0]['shortfall']);
 }
 public function test_warehouse_demand_reconciles_sales_and_excludes_internal_movements():void {
  $p=$this->product();$w=Warehouse::create($this->tenantAttributes(['name'=>'Measured','code'=>'M','is_active'=>true]));
  foreach(AnalyticsSnapshot::where('entity_type','demand_observation')->get() as $s){
   // Synthetic fixtures only: immutable production observations are not rewritten.
   $facts=$s->facts;$facts['provenance']=['sales'=>['sale:'.$s->id]];\Illuminate\Support\Facades\DB::table('analytics_snapshots')->where('id',$s->id)->update(['facts'=>json_encode($facts)]);
   AnalyticsSnapshot::create($this->tenantAttributes(['entity_type'=>'inventory','entity_id'=>$p->id,'warehouse_id'=>$w->id,'snapshot_date'=>$s->snapshot_date,'observed_at'=>$s->snapshot_date->copy()->endOfDay(),'feature_version'=>'observed-v1','facts'=>['unit'=>'pcs','available'=>100]]));
   foreach([['daily_sale',10,true],['transfer_out',300,false]] as [$code,$qty,$affects])StockMovement::create($this->tenantAttributes(['product_id'=>$p->id,'warehouse_id'=>$w->id,'type'=>'out','quantity'=>$qty,'quantity_before'=>1000,'quantity_after'=>1000-$qty,'warehouse_quantity_after'=>100,'unit_snapshot'=>'pcs','movement_code'=>$code,'source_type'=>$affects?'daily_sale':'stock_transfer','source_id'=>$s->id,'affects_company_quantity'=>$affects,'occurred_at'=>$s->snapshot_date->copy()->midDay()]));
   StockMovement::create($this->tenantAttributes(['product_id'=>$p->id,'warehouse_id'=>$w->id,'type'=>'out','quantity'=>10,'quantity_before'=>1000,'quantity_after'=>1000,'warehouse_quantity_after'=>0,'stock_state'=>'reserved','unit_snapshot'=>'pcs','movement_code'=>'transfer_out','source_type'=>'stock_transfer','source_id'=>$s->id,'affects_company_quantity'=>false,'occurred_at'=>$s->snapshot_date->copy()->midDay()]));
  }
  $r=$this->getJson('/api/analytics/planning/products/'.$p->id.'?warehouse_id='.$w->id)->assertOk()->assertJsonPath('plan.scope','qualified_warehouse')->json('plan');$this->assertEquals(10,$r['daily'][0]['quantity']);$this->assertEquals(90,$r['quality']['observed_days']);
 }
 public function test_allocation_limits_and_policy_immutability():void {
  $p=$this->product();$a=Warehouse::create($this->tenantAttributes(['name'=>'First','code'=>'F','is_active'=>true]));$b=Warehouse::create($this->tenantAttributes(['name'=>'Second','code'=>'S','is_active'=>true]));$d=['service_level'=>.95,'priority'=>3,'review_days'=>7,'allocation_share'=>.7];
  $this->postJson('/api/analytics/planning/products/'.$p->id.'/policy',$d+['warehouse_id'=>$a->id])->assertOk();$this->postJson('/api/analytics/planning/products/'.$p->id.'/policy',$d+['warehouse_id'=>$b->id])->assertStatus(422);
  $this->expectException(\LogicException::class);\App\Models\InventoryPlanningPolicy::first()->update(['settings'=>['priority'=>1]]);
 }
 public function test_sampled_availability_warning_does_not_discard_reconciled_fulfilled_demand():void {
  $p=$this->product();
  foreach(AnalyticsSnapshot::where('entity_type','demand_observation')->get() as $snapshot){$facts=$snapshot->facts;$facts['potentially_censored']=true;\Illuminate\Support\Facades\DB::table('analytics_snapshots')->where('id',$snapshot->id)->update(['facts'=>json_encode($facts)]);}
  $this->getJson('/api/analytics/planning/products/'.$p->id)->assertOk()->assertJsonPath('plan.quality.observed_days',90)->assertJsonPath('plan.quality.stockout_days',0)->assertJsonPath('plan.coverage_supported',true);
 }
 public function test_new_planning_data_survives_portable_restore_as_history():void {
  $p=$this->product();$this->postJson('/api/analytics/planning/products/'.$p->id.'/policy',['service_level'=>.95,'priority'=>4,'review_days'=>7])->assertOk();$this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk();
  $contents=app(\App\Services\PortableBackupService::class)->export(['analytics','categories','products','suppliers','warehouses'])->getContent();
  $other=Company::factory()->create();User::factory()->create(['company_id'=>$other->id,'role'=>'admin','api_token'=>hash('sha256','planning-restore')]);$this->withHeader('Authorization','Bearer planning-restore')->getJson('/api/me')->assertOk();
  $file=\Illuminate\Http\UploadedFile::fake()->createWithContent('planning.json',$contents);$this->post('/api/backup/import',['file'=>$file,'mode'=>'merge'])->assertOk();
  $policy=\App\Models\InventoryPlanningPolicy::where('company_id',$other->id)->firstOrFail();$rec=InventoryRecommendation::where('company_id',$other->id)->firstOrFail();$this->assertSame($policy->id,$rec->planning_policy_id);$this->assertSame('superseded',$rec->status);$this->assertTrue($rec->prediction->valid_until->lte(now()));
 }
 public function test_optimization_evidence_supplier_tradeoffs_and_views():void {
  $p=$this->product();$s=Supplier::create($this->tenantAttributes(['name'=>'Cheaper slower','is_active'=>true]));ProductSupplier::create($this->tenantAttributes(['product_id'=>$p->id,'supplier_id'=>$s->id,'purchase_price'=>1,'currency'=>'EUR','exchange_rate_to_base'=>1,'usual_lead_time_days'=>30,'pack_size'=>10,'is_active'=>true]));
  $r=$this->getJson('/api/analytics/planning/products/'.$p->id)->assertOk()->assertJsonPath('plan.optimization_state','potential_stockout')->assertJsonPath('plan.logic_version','inventory-planning-v4.1')->json();
  $this->assertEquals(5,$r['plan']['inventory_position']['position']);$this->assertEquals(180,$r['plan']['desired_base_quantity']);$this->assertCount(2,$r['supplier_options']);$this->assertNull($r['supplier_options'][0]['late_delivery_percent']);
  $this->assertSame('lead_days',$r['supplier_options'][0]['tradeoffs'][0]['advantage']);$this->assertSame('base_unit_price',$r['supplier_options'][1]['tradeoffs'][0]['advantage']);
  $this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk();$this->getJson('/api/analytics/planning?view=replenishment')->assertOk()->assertJsonPath('total',1);$this->getJson('/api/analytics/planning?view=excess')->assertOk()->assertJsonPath('total',0);
 }
 public function test_pack_rounding_excess_and_observed_forecast_uncertainty():void {
  $i=$this->input();$plain=app(InventoryPlanningMath::class)->calculate($i);$i['forecast_error']=['daily_variance'=>100];$uncertain=app(InventoryPlanningMath::class)->calculate($i);$this->assertGreaterThan($plain['safety_stock'],$uncertain['safety_stock']);
  $i=$this->input();$i['history']=[];$i['daily']=array_map(fn($n)=>['date'=>today()->addDays($n)->toDateString(),'quantity'=>10],range(0,89));$i['policy']['review_days']=14;$i['lead']=['mean'=>3,'variance'=>0,'p90'=>3];$i['minimum_safety']=5;$i['stock']=['available'=>2,'committed_outgoing'=>0];$i['factor']=50;$i['unit']='roll';$i['base_fractional']=true;$i['supplier']['pack_size']=50;
  $r=app(InventoryPlanningMath::class)->calculate($i);$this->assertEquals(183,$r['required_base_quantity']);$this->assertEquals(200,$r['base_quantity']);$this->assertEquals(4,$r['quantity']);
  $r+=['policy'=>$i['policy']+['maximum_stock'=>null],'stock'=>$i['stock'],'supplier_id'=>null,'warehouse'=>null];$r=app(InventoryPlanningInsights::class)->enrich($r,$i+['currency'=>'EUR','history'=>[],'suppliers'=>[]]);$this->assertSame('potential_stockout',$r['optimization_state']);
 }
 public function test_feedback_changes_are_reviewed_versions_not_operational_actions():void {
  $p=$this->product();$id=$this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk()->json('recommendation_id');$old=InventoryRecommendation::findOrFail($id)->explanation;
  $changed=$this->postJson('/api/analytics/planning/recommendations/'.$id.'/feedback',['action'=>'quantity_changed','base_quantity'=>200,'note'=>'Fixed package choice'])->assertOk()->json();$this->assertNotEquals($id,$changed['id']);$this->assertSame($old,InventoryRecommendation::findOrFail($id)->explanation);$this->assertEquals(200,InventoryRecommendation::findOrFail($changed['id'])->explanation['base_quantity']);
  $this->postJson('/api/analytics/planning/recommendations/'.$changed['id'].'/feedback',['action'=>'postponed','until'=>today()->addDays(5)->toDateString()])->assertOk()->assertJsonPath('status','postponed');
  $count=InventoryRecommendation::count();app(InventoryPlanningService::class)->maintain(microtime(true)+3);$this->assertSame($count,InventoryRecommendation::count());$this->assertSame('postponed',InventoryRecommendation::findOrFail($changed['id'])->status);
  $this->assertCount(2,InventoryRecommendation::findOrFail($changed['id'])->feedback['history']);$this->assertDatabaseCount('purchase_requests',0);$this->assertDatabaseCount('purchase_orders',0);$this->assertEquals(5,$p->fresh()->quantity);
  $other=Company::factory()->create();User::factory()->create(['company_id'=>$other->id,'role'=>'admin','api_token'=>hash('sha256','feedback-other')]);$this->withHeader('Authorization','Bearer feedback-other')->postJson('/api/analytics/planning/recommendations/'.$changed['id'].'/feedback',['action'=>'accepted'])->assertNotFound();
 }
 public function test_material_change_events_are_deduplicated_and_link_to_planning():void {
  $p=$this->product();$id=$this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk()->json('recommendation_id');$count=\App\Models\BusinessEvent::where('event_type','like','inventory.optimization.%')->count();$this->assertGreaterThan(0,$count);
  $this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk();$this->assertSame($count,\App\Models\BusinessEvent::where('event_type','like','inventory.optimization.%')->count());
  $p->update(['quantity'=>6]);$this->postJson('/api/analytics/planning/products/'.$p->id.'/save')->assertOk();$this->assertSame($count,\App\Models\BusinessEvent::where('event_type','like','inventory.optimization.%')->count());
  $this->assertStringContainsString('view=planning&product='.$p->id,app(\App\Services\AutomationRegistry::class)->url('InventoryRecommendation',$id));
  $names=array_column(app(\App\Services\AimsToolRegistry::class)->catalog(),'name');$this->assertContains('get_inventory_position',$names);$this->assertContains('simulate_inventory_scenario',$names);
  $this->assertEquals(6,app(\App\Services\AimsToolRegistry::class)->execute('get_inventory_position',['product_id'=>$p->id])['data']['inventory_position']['position']);
 }
 public function test_future_and_incomplete_observations_never_become_demand():void {
  $p=$this->product();$rows=AnalyticsSnapshot::where('entity_type','demand_observation')->latest('snapshot_date')->take(2)->get();
  foreach($rows as $k=>$s){$f=$s->facts;$f['demand']=9999;if($k===0)$f['complete']=false;\Illuminate\Support\Facades\DB::table('analytics_snapshots')->where('id',$s->id)->update(['facts'=>json_encode($f),'observed_at'=>$k===1?now()->addDay():$s->observed_at]);}
  InventoryPlanningService::invalidate($p->company_id,[$p->id]);$r=$this->getJson('/api/analytics/planning/products/'.$p->id)->assertOk()->json('plan');$this->assertEquals(88,$r['quality']['observed_days']);$this->assertEquals(10,$r['daily'][0]['quantity']);
 }
 public function test_finance_context_is_separate_and_company_scoped():void {
  $p=$this->product();\App\Models\FinancialAccount::create($this->tenantAttributes(['type'=>'cash','name'=>'Till','currency'=>'EUR','opening_balance'=>1000,'opening_date'=>today(),'is_active'=>true]));\App\Models\FinancialAccount::withoutEvents(fn()=>\App\Models\FinancialAccount::create(['company_id'=>Company::factory()->create()->id,'type'=>'cash','name'=>'Other','currency'=>'EUR','opening_balance'=>999999,'opening_date'=>today(),'is_active'=>true]));
  $s=ProductSupplier::first()->supplier_id;PurchaseOrder::create($this->tenantAttributes(['supplier_id'=>$s,'po_number'=>'Finance PO','status'=>'ordered','ordered_at'=>today(),'due_at'=>today()->addDays(3),'total_amount'=>200,'total_paid'=>50,'currency'=>'EUR','exchange_rate'=>1]));
  $r=$this->getJson('/api/analytics/planning/products/'.$p->id)->assertOk()->json('financial_context');$this->assertEquals(1000,$r['recorded_cash']);$this->assertEquals(150,$r['po_outstanding']);$this->assertEquals(200,$r['open_po_commitments']);$this->assertCount(1,$r['upcoming_po_payments']);
 }
 public function test_transfer_opportunities_require_real_local_evidence_and_protect_donor():void {
  $p=$this->product();$a=Warehouse::create($this->tenantAttributes(['name'=>'Donor','code'=>'DON','is_active'=>true]));$b=Warehouse::create($this->tenantAttributes(['name'=>'Destination','code'=>'DEST','is_active'=>true]));
  foreach([[$a,1000],[$b,5]] as [$w,$q])WarehouseStock::create($this->tenantAttributes(['warehouse_id'=>$w->id,'product_id'=>$p->id,'quantity'=>$q,'available_quantity'=>$q]));
  foreach(AnalyticsSnapshot::where('entity_type','demand_observation')->get() as $s){$f=$s->facts;$f['provenance']=['sales'=>['sale:'.$s->id]];\Illuminate\Support\Facades\DB::table('analytics_snapshots')->where('id',$s->id)->update(['facts'=>json_encode($f)]);
   foreach([$a,$b] as $w)AnalyticsSnapshot::create($this->tenantAttributes(['entity_type'=>'inventory','entity_id'=>$p->id,'warehouse_id'=>$w->id,'snapshot_date'=>$s->snapshot_date,'observed_at'=>$s->snapshot_date->copy()->endOfDay(),'feature_version'=>'observed-v1','facts'=>['unit'=>'pcs','available'=>100]]));
   StockMovement::create($this->tenantAttributes(['product_id'=>$p->id,'warehouse_id'=>$b->id,'type'=>'out','quantity'=>10,'quantity_before'=>100,'quantity_after'=>90,'warehouse_quantity_after'=>90,'unit_snapshot'=>'pcs','movement_code'=>'daily_sale','source_type'=>'daily_sale','source_id'=>$s->id,'affects_company_quantity'=>true,'occurred_at'=>$s->snapshot_date->copy()->midDay()]));
  }
  $r=$this->getJson('/api/analytics/planning/products/'.$p->id.'?warehouse_id='.$b->id)->assertOk()->json();$this->assertSame('qualified_warehouse',$r['plan']['scope']);$this->assertCount(1,$r['transfer_opportunities']);$this->assertSame($a->id,$r['transfer_opportunities'][0]['source_warehouse_id']);$this->assertLessThanOrEqual(998,$r['transfer_opportunities'][0]['quantity']);$this->assertNull($r['transfer_opportunities'][0]['expected_arrival']);$this->assertDatabaseCount('stock_transfers',0);
 }
}
