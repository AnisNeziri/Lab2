<?php
namespace App\Services;
use App\Models\{Product,Warehouse,StockMovement,StockTransfer,AnalyticsSnapshot,AnalyticsPrediction,InventoryForecastModel,InventoryPlanningPolicy,LandedCostAllocation,DailySale,Invoice};
use App\Support\{CompanyCurrency,Money};
use Illuminate\Support\Facades\Cache;

final class InventoryPlanningData {
 public function input(Product $product,array $options=[]):array {
  $warehouse=empty($options['warehouse_id'])?null:Warehouse::where('is_active',true)->findOrFail($options['warehouse_id']);
  $policy=InventoryPlanningPolicy::where('product_id',$product->id)->where('scope_key',$warehouse?->id??0)->latest('id')->first();
  $settings=array_replace(['service_level'=>.95,'priority'=>3,'review_days'=>(int)($product->replenishment_review_days??14),'maximum_stock'=>null,'allocation_share'=>null],$policy?->settings??[]);
  $suppliers=app(InventoryIntelligencePlanner::class)->suppliers($product);$supplier=collect($suppliers)->firstWhere('supplier_id',(int)($options['supplier_id']??0))??(empty($options['supplier_id'])?($suppliers[0]??[]):[]);
  abort_if(!empty($options['supplier_id'])&&!$supplier,422,'The supplier is no longer active for this product.');
  $unit=$options['unit']??$product->unit;$factor=app(UnitConversionService::class)->resolve($product,1,$unit)['conversion_factor']??1;
  $stamp=[today()->toDateString(),$product->unit,$product->created_at,AnalyticsSnapshot::where('entity_id',$product->id)->whereIn('entity_type',['product','demand_observation','inventory'])->max('id'),Cache::get('planning-generation:'.$product->company_id.':'.$product->id,0)];
  // V2's immutable reconciled observations are the planning authority. Never
  // re-scan an entire company's sales ledger for each product projection.
  $history=Cache::remember('planning-history:v4.1:'.$product->company_id.':'.$product->id.':'.hash('sha256',json_encode($stamp)),900,fn()=>$this->closedHistory($product));
  $prediction=AnalyticsPrediction::where('entity_type','product')->where('entity_id',$product->id)->where('model_key','inventory-demand-v1')->where('value->unit',$product->unit)->where('value->horizon',90)->where('generated_at','<=',now())->where('valid_until','>',now())->latest('id')->first();
  $model=$prediction?InventoryForecastModel::where('version',$prediction->model_version)->where('domain','inventory_demand')->where('status','active')->first():null;
  $daily=$model&&$model->training_cutoff&&$model->training_cutoff->lt(today())&&hash_equals($model->artifact_hash,InventoryIntelligenceService::artifactHash($model->artifact))?($prediction->value['daily']??[]):[];
  $source=$daily?'approved_model:'.$prediction->model_version:'qualified_history_mean_or_unavailable';$scope='company';$assumptions=[];
  if($warehouse){
   $local=$this->warehouseHistory($product,$warehouse,$history);$n=count(array_filter($local,fn($d)=>$d['demand']!==null));
   if($n>=28&&$n>=.8*count($local)){$history=$local;$daily=[];$source='warehouse_observed_mean';$scope='qualified_warehouse';}
   else {$share=$settings['allocation_share'];$scope=$share===null?'warehouse_demand_unavailable':'declared_company_allocation';
    $assumptions[]=$share===null?'Warehouse history is insufficient. Set an explicit company-demand allocation to plan here.':'Company demand allocated by user-declared share; no warehouse model was trained.';
    foreach($history as &$h){$h['demand']=$h['demand']===null||$share===null?null:$h['demand']*$share;$h['returned']=($h['returned']??0)*($share??0);}unset($h);
    foreach($daily as &$d)$d['quantity']*=($share??0);unset($d);if($share===null)$daily=[];
   }
  }
  $stock=app(InventorySnapshotService::class)->forProduct($product,null,$warehouse?->id);
  $legacy=app(InventoryIntelligencePlanner::class)->calculate($product,[],$suppliers,$supplier['supplier_id']??null,$unit);
  $firm=array_values(array_filter($stock['incoming_schedule'],fn($r)=>!empty($r['expected_at'])&&$r['expected_at']>=today()->toDateString()&&!in_array($r['purchase_order_id'],$legacy['at_risk_purchase_orders'])));
  $transfers=[];$transferLead=null;
  // Dispatched units have already left available warehouse balances. Add their
  // remaining arrival once per selected scope, never as customer demand.
  foreach(StockTransfer::with(['items'=>fn($q)=>$q->where('product_id',$product->id)])->whereHas('items',fn($q)=>$q->where('product_id',$product->id))->when($warehouse,fn($q)=>$q->where('destination_warehouse_id',$warehouse->id))->whereIn('status',['in_transit','partially_received'])->limit(100)->get() as $t){
    if($t->items->contains(fn($item)=>$item->unit_snapshot!==$product->unit))continue;
    $qty=round($t->items->sum(fn($item)=>max(0,(float)$item->quantity-(float)$item->received_quantity-(float)$item->damaged_quantity)),3);if($qty<=0)continue;
    $transferLead=$this->transferLead($t->source_warehouse_id,$t->destination_warehouse_id);
    $estimate=$transferLead!==null?$t->dispatched_at?->copy()->addDays((int)ceil($transferLead))->toDateString():null;
    $transfers[]=['id'=>$t->id,'quantity'=>$qty,'estimated_at'=>$estimate,'source_warehouse_id'=>$t->source_warehouse_id];
    if($estimate&&$estimate>=today()->toDateString())$firm[]=['expected_at'=>$estimate,'quantity'=>$qty,'transfer_id'=>$t->id];
  }
  if($warehouse){
   if(\App\Models\SalesOrderItem::where('product_id',$product->id)->whereHas('order',fn($q)=>$q->whereNull('warehouse_id')->whereNotNull('confirmed_at')->whereNotIn('status',['cancelled','delivered']))->exists())$assumptions[]='Some company commitments have no warehouse assignment; warehouse exposure is incomplete.';
  }
  $lead=[];
  if($supplier&&($supplier['lead_evidence']['eligible']??false)){
   $rows=app(SupplierHistoryService::class)->orders(\App\Models\Supplier::findOrFail($supplier['supplier_id']));
   $v=collect($rows)->filter(fn($r)=>$r['complete']&&$r['lead_days']!==null&&$r['label_known_at']<=today()->toDateString()&&collect($r['lines'])->contains('product_id',$product->id))->pluck('lead_days');
   if($v->count()>=5){$mean=$v->avg();$lead=['mean'=>$mean,'variance'=>$v->sum(fn($d)=>($d-$mean)**2)/max(1,$v->count()-1),'p90'=>$supplier['lead_evidence']['p90_days'],'samples'=>$v->count(),'source'=>'completed_receipts'];}
  }
  $allocations=LandedCostAllocation::with('goodsReceiptItem')->where('product_id',$product->id)->whereHas('landedCost',fn($q)=>$q->where('status','posted'))->whereHas('goodsReceiptItem',fn($q)=>$q->where('inventory_unit',$product->unit))->whereHas('goodsReceiptItem.receipt.purchaseOrder',fn($q)=>$q->where('supplier_id',$supplier['supplier_id']??0))->latest('id')->limit(20)->get();
  $accepted=$allocations->unique('goods_receipt_item_id')->sum(fn($a)=>(float)$a->goodsReceiptItem?->accepted_base_quantity);$allocated=$allocations->reduce(fn($sum,$a)=>$sum->plus($a->allocated_amount),\Brick\Math\BigDecimal::of('0'));$allowance=$accepted>0?Money::divide((string)$allocated,$accepted,6):null;
  $fractional=fn($u)=>in_array(strtolower($u),['m','meter','metre','meters','metres','metër','metra'],true);
  return ['product'=>$product->only('id','name','sku','unit'),'warehouse'=>$warehouse?->only('id','name'),'policy'=>$settings,'policy_id'=>$policy?->id,'policy_version'=>$policy?->version??'default-v4',
   'history'=>$history,'daily'=>$daily,'forecast_source'=>$source,'forecast_error'=>$warehouse?[]:$this->forecastError($product,str_starts_with($source,'approved_model:')?$model->version:null),'scope'=>$scope,'scope_assumptions'=>$assumptions,'stock'=>$stock,'firm_incoming'=>$firm,
   'supplier'=>$supplier,'suppliers'=>$suppliers,'unit'=>$unit,'factor'=>$factor,'unit_fractional'=>$fractional($unit),'base_fractional'=>$fractional($product->unit),
   'minimum_safety'=>max((float)$product->min_quantity,(float)$product->safety_stock),'reorder_floor'=>(float)$product->reorder_point,
   'lead'=>$lead,'at_risk_purchase_orders'=>$legacy['at_risk_purchase_orders'],'incoming_transfers'=>$transfers,'transfer_p90_days'=>$transferLead,
   'currency'=>CompanyCurrency::current(),'valuation_unit_cost'=>app(InventoryCostingService::class)->currentUnitCost($product),
   'landed_unit_allowance'=>$allowance,'landed_cost_evidence'=>$allocations->pluck('landed_cost_id')->unique()->values()->all(),'landed_cost_qualification'=>'Historical posted allowance, not a quote for the proposed purchase.'];
 }
 public function transferLead(int $source,int $destination):?float {
  $done=StockTransfer::where('source_warehouse_id',$source)->where('destination_warehouse_id',$destination)->where('status','received')->whereNotNull('dispatched_at')->whereNotNull('received_at')->where('received_at','<=',now())->latest('id')->limit(50)->get();
  $days=$done->filter(fn($t)=>$t->received_at->gte($t->dispatched_at))->map(fn($t)=>$t->dispatched_at->diffInDays($t->received_at))->sort()->values();return $days->count()>=3?(float)$days[(int)ceil(($days->count()-1)*.9)]:null;
 }
 private function closedHistory(Product $p):array {
  $start=today()->subDays(90)->max($p->created_at->copy()->startOfDay());$end=today()->subDay();
  $snapshots=AnalyticsSnapshot::where('entity_type','demand_observation')->where('entity_id',$p->id)->where('warehouse_id',0)->whereBetween('snapshot_date',[$start,$end])->get()->keyBy(fn($s)=>$s->snapshot_date->toDateString());$series=[];
  for($date=$start;$date->lte($end);$date=$date->copy()->addDay()){$key=$date->toDateString();$snapshot=$snapshots->get($key);$f=$snapshot?->facts??[];$valid=($f['unit']??null)===$p->unit&&($f['complete']??false)&&$snapshot->observed_at->lte(now());
   $series[]=['date'=>$key,'demand'=>$valid?($f['demand']??null):null,'gross_recorded'=>$valid?($f['sales_quantity']??0):0,'returned'=>$valid?($f['returns_quantity']??0):0,'censored'=>$valid&&(bool)(($f['stockout']??false)||($f['potentially_censored']??false)),'available'=>$valid?($f['available']??null):null,'source_references'=>$f['provenance']['sales']??[],'quality'=>$valid?($f['quality']??'unknown'):'unknown'];
  }return $series;
 }
 private function forecastError(Product $p,?string $version):array {
  if(!$version)return [];
  $days=[];$ids=[];
  foreach(AnalyticsPrediction::where('entity_type','product')->where('entity_id',$p->id)->where('model_key','inventory-demand-v1')->where('model_version',$version)->where('value->unit',$p->unit)->where('evaluation->eligible',true)->where('evaluated_at','<=',now())->latest('id')->limit(20)->get() as $row){
   foreach($row->actual_value['days']??[] as $d)if($d['date']<today()->toDateString()&&!isset($days[$d['date']])&&isset($d['error'])){$days[$d['date']]=(float)$d['error'];$ids[]=$row->id;}
  }
  $n=count($days);if($n<28)return ['samples'=>$n,'daily_variance'=>null];
  $mean=array_sum($days)/$n;
  return ['samples'=>$n,'daily_variance'=>array_sum(array_map(fn($e)=>($e-$mean)**2,$days))/($n-1),'prediction_ids'=>array_values(array_unique($ids)),'source'=>'matured_unique_daily_errors','model_version'=>$version];
 }
 private function warehouseHistory(Product $p,Warehouse $w,array $history):array {
  if(!$history)return [];
  $moves=StockMovement::where('product_id',$p->id)->whereBetween('occurred_at',[$history[0]['date'].' 00:00:00',last($history)['date'].' 23:59:59'])->whereIn('movement_code',['daily_sale','daily_sale_reversal','invoice_sale'])->limit(10000)->get()->groupBy(fn($m)=>$m->occurred_at->toDateString());
  $snapshots=AnalyticsSnapshot::where('entity_type','inventory')->where('entity_id',$p->id)->where('warehouse_id',$w->id)->whereDate('snapshot_date','>=',$history[0]['date'])->get()->keyBy(fn($s)=>$s->snapshot_date->toDateString());
  $stockouts=StockMovement::where('product_id',$p->id)->where('warehouse_id',$w->id)->whereBetween('occurred_at',[$history[0]['date'].' 00:00:00',last($history)['date'].' 23:59:59'])->where('warehouse_quantity_after','<=',0)->pluck('occurred_at')->map(fn($d)=>substr((string)$d,0,10))->flip();
  return array_map(function($h)use($p,$w,$moves,$snapshots,$stockouts){$all=$moves->get($h['date'],collect());$same=$all->every(fn($m)=>$m->unit_snapshot===$p->unit);$net=fn($rows)=>$rows->sum(fn($m)=>(float)$m->quantity*($m->movement_code==='daily_sale_reversal'?-1:1));
   $snapshot=$snapshots->get($h['date']);$local=$all->where('warehouse_id',$w->id);$censored=$stockouts->has($h['date']);
   $sources=$all->every(fn($m)=>in_array(($m->source_type==='daily_sale'?'sale:':($m->source_type==='invoice'?'invoice:':'unknown:')).$m->source_id,$h['source_references'],true));
   $valid=$same&&$sources&&$h['demand']!==null&&abs($net($all)-(float)$h['gross_recorded'])<.0005&&$snapshot&&($snapshot->facts['unit']??null)===$p->unit&&$snapshot->observed_at->toDateString()===$h['date']&&(float)($snapshot->facts['available']??0)>0&&!$censored;
   return array_replace($h,['demand'=>$valid?max(0,$net($local)):null,'censored'=>$censored,'quality'=>$valid?'warehouse_reconciled':'warehouse_unknown']);},$history);
 }
}
