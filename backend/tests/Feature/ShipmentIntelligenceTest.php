<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Auth,Cache};
use App\Models\{Category,Product,Supplier,ProductSupplier,PurchaseOrder,Shipment,ShipmentMilestone,ShipmentContainer,ShipmentIntelligence,ShipmentHistory,AnalyticsSnapshot,Warehouse,WarehouseStock,InventoryPlanningPolicy,BusinessEvent,Company,User};
use App\Services\{ShipmentIntelligenceService,ShipmentEtaCalculator,ShipmentIntelligenceEvidence,EnterpriseDecisionEvidence,InventorySnapshotService,AimsToolRegistry,ActionCenterService};

class ShipmentIntelligenceTest extends TestCase {
 use RefreshDatabase;
 private function setupShipment(bool $demand=false):array {
  $this->actingAsApiUser();$this->getJson('/api/me')->assertOk();$this->travelTo(now()->setDate(2026,10,4)->setTime(12,0));
  $c=Category::create(['name'=>'Fabric']);$p=Product::create(['name'=>'Milano 04','sku'=>'FAB-V6','category_id'=>$c->id,'unit'=>'m','quantity'=>100,'min_quantity'=>20,'high_stock_threshold'=>20000,'purchase_price'=>2,'price'=>5]);
  $p->forceFill(['created_at'=>today()->subYear()])->save();$sup=Supplier::create(['name'=>'Factory','is_active'=>true]);ProductSupplier::create(['product_id'=>$p->id,'supplier_id'=>$sup->id,'purchase_price'=>2,'currency'=>'EUR','usual_lead_time_days'=>10,'is_active'=>true]);
  $w=Warehouse::create(['name'=>'Inland warehouse','code'=>'INLAND','is_active'=>true]);WarehouseStock::create(['warehouse_id'=>$w->id,'product_id'=>$p->id,'quantity'=>$demand?60:100,'available_quantity'=>$demand?40:80,'reserved_quantity'=>20]);
  InventoryPlanningPolicy::create(['product_id'=>$p->id,'warehouse_id'=>$w->id,'scope_key'=>$w->id,'version'=>'test-v6','settings'=>['allocation_share'=>1]]);
  if($demand)for($day=1;$day<=90;$day++)AnalyticsSnapshot::create(['entity_type'=>'demand_observation','entity_id'=>$p->id,'warehouse_id'=>0,'snapshot_date'=>today()->subDays($day),'observed_at'=>today()->subDays($day)->endOfDay(),'feature_version'=>'observed-v2','facts'=>['unit'=>'m','demand'=>20,'sales_quantity'=>20,'returns_quantity'=>0,'available'=>100,'stockout'=>false,'complete'=>true,'potentially_censored'=>false]]);
  $po=PurchaseOrder::create(['supplier_id'=>$sup->id,'warehouse_id'=>$w->id,'po_number'=>'PO-V6','status'=>'ordered','ordered_at'=>today()->subDays(25),'expected_at'=>today()->addDays(2),'total_amount'=>20000,'currency'=>'EUR','exchange_rate'=>1]);
  $item=$po->items()->create(['product_id'=>$p->id,'description'=>$p->name,'unit'=>'m','inventory_unit'=>'m','conversion_factor'=>1,'quantity'=>10000,'base_quantity'=>10000,'unit_price'=>2,'line_total'=>20000]);
  $s=Shipment::create(['tracking_number'=>'SH-V6','purchase_order_id'=>$po->id,'warehouse_id'=>$w->id,'supplier_id'=>$sup->id,'origin_port'=>'Shanghai','destination_port'=>'Durrës','transport_mode'=>'sea','status'=>'in_transit','departed_at'=>today()->subDays(20),'eta'=>today()->addDays(5),'tracking_provider'=>'aisstream','mmsi'=>'123456789','position_updated_at'=>now()->subMinutes(43),'current_lat'=>20,'current_lng'=>30]);
  $s->items()->create(['product_id'=>$p->id,'purchase_order_item_id'=>$item->id,'description'=>$p->name,'unit'=>'m','quantity'=>10000,'planned_quantity'=>10000,'base_quantity'=>10000]);
  return [$p,$s,$po,$w];
 }
 private function refresh(Shipment $s):array{return $this->postJson('/api/shipment-intelligence/'.$s->id.'/refresh')->assertOk()->json();}
 private function counts():array{return collect(['stock_movements','stock_transfers','purchase_orders','purchase_requests','journal_entries','financial_account_transactions'])->mapWithKeys(fn($t)=>[$t=>DB::table($t)->count()])->all();}
 private function milestone(Shipment $s,string $type,?string $planned=null,?string $actual=null,?string $estimate=null):ShipmentMilestone{return $s->milestones()->updateOrCreate(['scope_key'=>'shipment','milestone_type'=>$type],['status'=>$actual?'completed':'planned','planned_at'=>$planned,'estimated_at'=>$estimate,'actual_at'=>$actual,'source'=>'manual']);}
 public function test_compact_shipment_list_handles_po_reference_without_financial_columns():void {
  $this->setupShipment();$this->getJson('/api/shipments')->assertOk()->assertJsonPath('0.purchase_order.po_number','PO-V6');$this->assertArrayNotHasKey('remaining_balance',$this->getJson('/api/shipments')->json('0.purchase_order'));
 }
 public function test_sparse_fallback_separates_eta_targets_and_does_not_mutate_ledgers():void {
  [$p,$s]=$this->setupShipment();$before=$this->counts();$row=$this->refresh($s);$this->assertSame('limited',$row['confidence']);$this->assertTrue($row['eta']['limited_historical_evidence']);$this->assertSame($s->eta->toDateString(),$row['eta']['operational']);$this->assertSame('port',$row['eta']['target']);$this->assertFalse($row['impact']['products'][0]['forecast_available']);$this->assertNull($row['impact']['products'][0]['requirement_date']);$this->assertSame(80.0,(float)$row['impact']['products'][0]['stock']['available']);$this->assertSame($before,$this->counts());$this->assertSame($s->eta->toDateString(),$s->fresh()->eta->toDateString());
 }
 public function test_realistic_delay_exposes_fabric_and_transfer_without_supplier_blame():void {
  [$p,$s,$po,$w]=$this->setupShipment(true);$other=Warehouse::create(['name'=>'Reserve','code'=>'RES','is_active'=>true]);WarehouseStock::create(['warehouse_id'=>$other->id,'product_id'=>$p->id,'quantity'=>500,'available_quantity'=>500]);
  $this->milestone($s,'warehouse_arrival',today()->addDays(2)->toDateString(),null,today()->addDays(10)->toDateString());$this->milestone($s,'cargo_ready',today()->subDays(21)->toDateString(),today()->subDays(21)->toDateString());$this->milestone($s,'destination_port',today()->subDays(2)->toDateString());
  $before=$this->counts();$row=$this->refresh($s);$this->assertSame('CRITICAL',$row['risk']);$this->assertSame(1,$row['impact']['exposed_products']);$affected=$row['impact']['products'][0];$this->assertNotNull($affected['requirement_date']);$this->assertGreaterThan(0,$affected['potential_shortage_days']);$this->assertNotEmpty($affected['transfer_options']);$this->assertEquals(20,$affected['stock']['reserved']);$this->assertEquals(10000,$affected['quantity']);$this->assertSame([],$row['evidence']['supplier_feedback']);$this->assertContains('INTERNATIONAL_TRANSIT',array_column($row['evidence']['delay_evidence'],'category'));$this->assertSame($before,$this->counts());
  $dec=app(EnterpriseDecisionEvidence::class)->assemble($p->id,$w->id);$this->assertEquals($row['id'],$dec['evidence']['logistics'][0]['intelligence']['id']);$this->assertNotNull($dec['plan']['stockout_date']);$this->assertTrue($dec['evidence']['procurement']['orders'][0]['at_risk']);$this->assertSame($before,$this->counts());
  $attention=app(ActionCenterService::class)->snapshot()['attention'];$this->assertCount(1,array_filter($attention,fn($a)=>$a['id']==='logistics-'.$s->id));$this->assertTrue(BusinessEvent::where('event_type','shipment.intelligence.inventory_exposure')->exists());
 }
 public function test_incoming_quantities_are_never_duplicated_and_split_unknowns_remain_unknown():void {
  [$p,$s,$po,$w]=$this->setupShipment(true);$this->milestone($s,'warehouse_arrival',null,null,today()->addDay()->toDateString());$before=app(InventorySnapshotService::class)->forProduct($p,null,$w->id);$this->refresh($s);$decision=app(EnterpriseDecisionEvidence::class)->assemble($p->id,$w->id);$this->assertEquals(10000,$decision['plan']['stock']['incoming']);$this->assertEquals($before,app(InventorySnapshotService::class)->forProduct($p,null,$w->id));
  $s->items()->delete();Shipment::create(['tracking_number'=>'SH-SPLIT','purchase_order_id'=>$po->id,'status'=>'in_transit','transport_mode'=>'sea']);$r=$this->refresh($s);$this->assertSame([],$r['impact']['products']);$this->assertNotEmpty($r['impact']['unknowns']);
 }
 public function test_stale_ais_is_not_live_and_position_only_updates_do_not_fill_history():void {
  [$p,$s]=$this->setupShipment();$s->update(['position_updated_at'=>now()->subDays(2)]);$row=$this->refresh($s);$this->assertSame('stale',$row['tracking_freshness']['state']);$history=ShipmentHistory::count();$versions=ShipmentIntelligence::count();$s->update(['current_lat'=>21,'position_updated_at'=>now()->subDays(2)->addMinute()]);$row=$this->refresh($s);$this->assertSame($history,ShipmentHistory::count());$this->assertSame($versions,ShipmentIntelligence::count());$s->update(['mmsi'=>'123']);$this->assertSame('invalid_mmsi',$this->refresh($s)['tracking_freshness']['state']);
 }
 public function test_risk_is_not_critical_merely_because_it_is_late_and_events_deduplicate():void {
  [$p,$s]=$this->setupShipment();$s->update(['eta'=>today()->subDay()]);$row=$this->refresh($s);$this->assertSame('DELAYED',$row['risk']);$n=BusinessEvent::where('event_type','like','shipment.intelligence.%')->count();$versions=ShipmentIntelligence::count();$this->refresh($s);$this->assertEquals($n,BusinessEvent::where('event_type','like','shipment.intelligence.%')->count());$this->assertEquals($versions,ShipmentIntelligence::count());
 }
 public function test_prediction_freezing_and_prearrival_outcome_validation():void {
  [$p,$s]=$this->setupShipment();$this->milestone($s,'warehouse_arrival',null,null,today()->addDays(3)->toDateString());$row=$this->refresh($s);$frozen=ShipmentIntelligence::find($row['id']);$original=$frozen->eta;$this->travel(4)->days();$this->milestone($s,'warehouse_arrival',null,today()->toDateString());$this->refresh($s);$frozen->refresh();$this->assertSame($original,$frozen->eta);$this->assertTrue($frozen->outcome['eligible']);$this->assertEquals(-1,$frozen->outcome['error_days']);$stats=$this->getJson('/api/shipment-intelligence/routes')->assertOk()->json('performance');$this->assertSame(1,$stats['completed_genuine_predictions']);$this->assertEquals(1,$stats['mae_days']);
 }
 public function test_attribution_only_feedbacks_actual_supplier_preparation():void {
  [$p,$s]=$this->setupShipment();$this->milestone($s,'cargo_ready',today()->subDays(29)->toDateString(),today()->subDays(21)->toDateString());$this->milestone($s,'customs_cleared',today()->subDay()->toDateString());$row=$this->refresh($s);$this->assertCount(1,$row['evidence']['supplier_feedback']);$this->assertEquals(8,$row['evidence']['supplier_feedback'][0]['late_days']);$this->assertSame('SUPPLIER_PREPARATION',$row['evidence']['supplier_feedback'][0]['category']);
 }
 public function test_route_history_uses_completed_genuine_matching_routes_only():void {
  [$p,$s]=$this->setupShipment();for($i=0;$i<5;$i++){$old=Shipment::create(['tracking_number'=>'HIST-'.$i,'origin_port'=>'Shanghai','destination_port'=>'Durrës','transport_mode'=>'sea','status'=>'delivered','departed_at'=>today()->subDays(60+$i)]);$this->milestone($old,'destination_port',null,today()->subDays(30+$i)->toDateString());$this->milestone($old,'warehouse_arrival',null,today()->subDays(25+$i)->toDateString());}
  $row=$this->refresh($s);$this->assertSame(5,$row['evidence']['route']['warehouse']['samples']);$this->assertEquals(35,$row['evidence']['route']['warehouse']['median_days']);$this->assertSame('warehouse',$row['eta']['target']);$this->assertSame('moderate',$row['confidence']);$this->assertFalse($row['eta']['limited_historical_evidence']);$this->assertGreaterThanOrEqual($row['eta']['range_start'],$row['eta']['range_end']);
 }
 public function test_tenant_permissions_read_tools_and_no_recomputation_on_reads():void {
  [$p,$s]=$this->setupShipment();$this->refresh($s);$n=ShipmentIntelligence::count();$this->getJson('/api/shipment-intelligence')->assertOk();$this->getJson('/api/shipment-intelligence/'.$s->id)->assertOk();$this->assertEquals($n,ShipmentIntelligence::count());$this->assertSame($s->id,app(AimsToolRegistry::class)->execute('get_shipment_intelligence',['shipment_id'=>$s->id])['data']['shipment_id']);
  $other=Company::factory()->create();User::factory()->create(['company_id'=>$other->id,'role'=>'admin','email_verified_at'=>now(),'api_token'=>hash('sha256','other-v6')]);$this->withToken('other-v6')->getJson('/api/shipment-intelligence/'.$s->id)->assertNotFound();$this->postJson('/api/shipment-intelligence/'.$s->id.'/refresh')->assertNotFound();$this->getJson('/api/shipment-intelligence/routes')->assertOk()->assertJsonPath('routes',[]);
  $this->withToken('test-token-admin')->getJson('/api/me')->assertOk();$role=\App\Models\Role::where('slug','admin')->first();$permission=\App\Models\Permission::where('slug','inventory.view')->first();$role->permissions()->detach($permission->id);Cache::forget('role_permissions:admin');$this->getJson('/api/shipment-intelligence/'.$s->id)->assertOk()->assertJsonPath('impact.restricted',true);$this->postJson('/api/shipment-intelligence/'.$s->id.'/refresh')->assertForbidden();
 }
 public function test_all_transport_modes_without_ais_and_missing_eta():void {
  [$p,$s]=$this->setupShipment();foreach(['road','rail','air','multimodal'] as $mode){$s->update(['transport_mode'=>$mode,'tracking_provider'=>'disabled','eta'=>null]);$r=$this->refresh($s);$this->assertSame('not_applicable',$r['tracking_freshness']['state']);$this->assertSame('INSUFFICIENT_DATA',$r['risk']);$this->assertNull($r['eta']['predicted']);}
 }
 public function test_milestone_corrections_append_observations_and_leave_frozen_versions_unchanged():void {
  [$p,$s]=$this->setupShipment();$m=$this->milestone($s,'supplier_dispatch',today()->subDays(21)->toDateString(),today()->subDays(20)->toDateString());$first=$this->refresh($s);$before=ShipmentHistory::where('event_type','intelligence.observation')->count();$this->assertGreaterThan(0,$before);
  $m->update(['actual_at'=>today()->subDays(18)]);$this->refresh($s);$this->assertGreaterThan($before,ShipmentHistory::where('event_type','intelligence.observation')->count());$this->assertSame($first['evidence'],ShipmentIntelligence::find($first['id'])->evidence);
 }
 public function test_background_refresh_is_bounded_and_only_intelligence_records_are_written():void {
  [$p,$s]=$this->setupShipment();$before=$this->counts();$service=app(ShipmentIntelligenceService::class);ShipmentIntelligenceService::invalidate($s->company_id,$s->id);$this->assertEquals(1,$service->scheduled());$this->assertSame($before,$this->counts());$this->assertEquals(0,$service->scheduled());$this->assertTrue(ShipmentIntelligence::where('is_current',true)->exists());
 }
 public function test_statistical_eta_rejects_future_outcomes_and_preserves_missed_schedule():void {
  $this->setupShipment();$math=app(ShipmentEtaCalculator::class);$r=$math->distribution([['shipment_id'=>10,'actual_arrival'=>today()->addDay()->toDateString(),'known_at'=>now()->toIso8601String(),'days'=>20]],'days');$this->assertEquals(0,$r['samples']);
  $empty=$math->distribution([],'days');$eta=$math->predict(['operational_eta'=>today()->subDay()->toDateString(),'schedule_target'=>'warehouse'],['transit'=>$empty,'warehouse'=>$empty,'port_to_warehouse'=>$empty]);$this->assertNull($eta['predicted']);$this->assertSame(today()->subDay()->toDateString(),$eta['operational']);$this->assertSame(today()->subDay()->toDateString(),$eta['missed_estimate']);
 }
 public function test_received_po_lines_no_longer_create_inventory_exposure():void {
  [$p,$s,$po]=$this->setupShipment(true);$item=$po->items()->first();$item->update(['received_quantity'=>10000,'received_base_quantity'=>10000]);$row=$this->refresh($s);$this->assertSame([],$row['impact']['products']);$this->assertEquals(0,$row['impact']['exposed_products']);$this->assertNotEquals('CRITICAL',$row['risk']);
 }
 public function test_progress_and_route_history_reuse_actual_departure_and_container_arrivals():void {
  [$p,$s]=$this->setupShipment();$this->milestone($s,'cargo_ready',null,today()->subDays(22)->toDateString());$reader=app(ShipmentIntelligenceEvidence::class);$this->assertSame('sea_transit',$reader->facts($s)['current_milestone']);
  $old=Shipment::create(['tracking_number'=>'CONTAINER-HISTORY','origin_port'=>'Shanghai','destination_port'=>'Durrës','transport_mode'=>'sea','status'=>'delivered']);
  $old->containers()->create(['container_number'=>'TSTU1234567','status'=>'arrived','actual_departure'=>today()->subDays(60),'actual_arrival'=>today()->subDays(30)]);
  $history=$reader->routeHistory($s);$this->assertCount(1,$history);$this->assertEquals(30,$history[0]['transit_days']);$this->assertNull($history[0]['warehouse_days']);
 }
 public function test_warehouse_arrival_does_not_hide_a_delayed_receiving_stage():void {
  [$p,$s,$po]=$this->setupShipment();$s->update(['eta'=>today()->subDays(2),'status'=>'delivered']);$this->milestone($s,'destination_port',null,today()->subDays(2)->toDateString());$this->milestone($s,'warehouse_arrival',null,today()->subDay()->toDateString());$this->milestone($s,'goods_receipt',today()->subDay()->toDateString());$r=$this->refresh($s);$this->assertFalse($r['evidence']['completed']);$this->assertSame('DELAYED',$r['risk']);$this->assertContains('RECEIVING',array_column($r['evidence']['delay_evidence'],'category'));
  $po->items()->first()->update(['received_base_quantity'=>10000,'received_quantity'=>10000]);$po->update(['status'=>'received']);$r=$this->refresh($s);$this->assertTrue($r['evidence']['completed']);$this->assertSame('ON_TRACK',$r['risk']);$this->assertSame('recorded_completion',$r['evidence']['reason']);$this->assertSame([],$r['impact']['products']);
 }
 public function test_multiple_po_allocations_retime_only_their_own_remaining_quantities():void {
  [$p,$s,$po,$w]=$this->setupShipment(true);$other=PurchaseOrder::create(['supplier_id'=>$po->supplier_id,'warehouse_id'=>$w->id,'po_number'=>'PO-V6-SECOND','status'=>'ordered','ordered_at'=>today()->subDays(20),'expected_at'=>today()->addDays(6),'total_amount'=>4000,'currency'=>'EUR','exchange_rate'=>1]);$line=$other->items()->create(['product_id'=>$p->id,'description'=>$p->name,'unit'=>'m','inventory_unit'=>'m','quantity'=>2000,'base_quantity'=>2000,'unit_price'=>2,'line_total'=>4000]);$s->purchaseOrders()->attach($other->id,['company_id'=>$s->company_id]);$s->items()->create(['product_id'=>$p->id,'purchase_order_item_id'=>$line->id,'description'=>$p->name,'unit'=>'m','quantity'=>1000,'base_quantity'=>1000]);$this->milestone($s,'warehouse_arrival',null,null,today()->addDays(10)->toDateString());
  $before=app(InventorySnapshotService::class)->forProduct($p,null,$w->id);$row=$this->refresh($s);$allocations=$row['impact']['products'][0]['po_allocations'];$this->assertEquals(10000,$allocations[$po->id]);$this->assertEquals(1000,$allocations[$other->id]);$decision=app(EnterpriseDecisionEvidence::class)->assemble($p->id,$w->id);$this->assertEquals(12000,$decision['plan']['stock']['incoming']);$this->assertSame($before,app(InventorySnapshotService::class)->forProduct($p,null,$w->id));$timed=collect($decision['plan']['timeline'])->sum('incoming');$this->assertLessThanOrEqual(12000,$timed);
  $input=app(\App\Services\InventoryPlanningService::class)->decisionEvidence($p->id,['warehouse_id'=>$w->id])['input'];$firm=app(\App\Services\ShipmentArrivalTiming::class)->apply($input,[$po->id,$other->id],[ShipmentIntelligence::find($row['id'])],$p->id,$w->id);$this->assertEquals(10000,collect($firm)->where('purchase_order_id',$po->id)->sum('quantity'));$this->assertEquals(1000,collect($firm)->where('purchase_order_id',$other->id)->sum('quantity'));
 }
 public function test_actual_port_arrival_does_not_promise_on_time_warehouse_stock():void {
  [$p,$s]=$this->setupShipment(true);$this->milestone($s,'destination_port',null,today()->subDay()->toDateString());$row=$this->refresh($s);$this->assertSame('port',$row['eta']['target']);$impact=$row['impact']['products'][0];$this->assertTrue($impact['warehouse_arrival_unknown']);$this->assertTrue($impact['exposed']);$this->assertNull($impact['potential_shortage_days']);$this->assertSame('CRITICAL',$row['risk']);
 }
}
