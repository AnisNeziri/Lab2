<?php
namespace App\Services;
use App\Models\{Supplier,PurchaseOrder,Shipment,AnalyticsSnapshot};
use Carbon\CarbonImmutable as Date;

/** Read-only adapter over PO change history and posted receipt facts; one target per PO. */
final class SupplierHistoryService {
 public function orders(Supplier $supplier):array {
  $orders=PurchaseOrder::with(['items.product','goodsReceipts.items','changes'])->where('supplier_id',$supplier->id)->orderByDesc('id')->limit(config('supplier_intelligence.history_limit'))->get();$ids=$orders->pluck('id');
  $shipments=Shipment::with(['milestones','purchaseOrders'])->where(fn($q)=>$q->whereIn('purchase_order_id',$ids)->orWhereHas('purchaseOrders',fn($q)=>$q->whereIn('purchase_orders.id',$ids)))->get();
  $inspections=\App\Models\QualityInspection::whereIn('purchase_order_id',$ids)->whereNotNull('finalized_at')->get(['id','purchase_order_id','product_id','decision','accepted_quantity','rejected_quantity','finalized_at'])->groupBy('purchase_order_id');
  $claims=\App\Models\SupplierClaim::whereIn('purchase_order_id',$ids)->get(['id','purchase_order_id','status','claim_date','resolved_at'])->groupBy('purchase_order_id');
  return $orders->map(fn($p)=>$this->order($p,['shipments'=>$shipments->filter(fn($s)=>$s->purchase_order_id===$p->id||$s->purchaseOrders->contains('id',$p->id)),'inspections'=>$inspections->get($p->id,collect())->toArray(),'claims'=>$claims->get($p->id,collect())->toArray()]))->all();
 }
 public function order(PurchaseOrder $po,?array $loaded=null):array {
  $po->loadMissing(['items.product','goodsReceipts.items','changes']);
  $changes=$po->changes->sortBy('id');$commit=$changes->first(fn($c)=>in_array($c->new_values['status']??'', ['confirmed','ordered']));
  $initial=$commit?->new_values;$promise=$initial['expected_at']??null;$origin=$initial['ordered_at']??$po->ordered_at?->toDateString();
  $known=$commit?->created_at?->toIso8601String();$revisions=$changes->filter(fn($c)=>($c->old_values['expected_at']??null)!==($c->new_values['expected_at']??null))->map(fn($c)=>['at'=>$c->created_at->toIso8601String(),'old'=>$c->old_values['expected_at']??null,'new'=>$c->new_values['expected_at']??null])->values()->all();
  $scope=fn($items)=>collect($items)->map(fn($i)=>[(int)($i['product_id']??0),$i['unit']??null,round((float)($i['quantity']??0),3)])->sortBy(fn($x)=>json_encode($x))->values()->all();
  $scopeUnchanged=isset($initial['items'])&&$scope($initial['items'])===$scope($po->items->toArray());
  $receipts=$po->goodsReceipts->where('status','posted')->filter(fn($r)=>$r->received_at&&$r->received_at->lte(now())&&$r->created_at->lte(now()))->sortBy('received_at');$lineRows=[];$complete=$po->items->isNotEmpty();$completion=null;$labelKnown=null;
  foreach($po->items as $item){$unit=$item->inventory_unit?:($item->unit===$item->product?->unit?$item->unit:null);$ordered=$item->base_quantity??($item->unit===$unit?$item->quantity:null);$received=0;$accepted=0;$lineEnd=null;
   foreach($receipts as $receipt)foreach($receipt->items->where('purchase_order_item_id',$item->id) as $line){
    if(!$unit||$line->inventory_unit!==$unit){$complete=false;continue;}
    $accepted+=(float)$line->accepted_base_quantity;$received+=(float)$line->accepted_base_quantity+(float)$line->damaged_base_quantity;
    if($ordered!==null&&$received+.0005>=(float)$ordered&&$lineEnd===null){$lineEnd=$receipt->received_at->toDateString();$labelKnown=max($labelKnown??'', $receipt->created_at->toDateString(),$lineEnd);}
   }
   if(!$lineEnd||$ordered===null||$ordered<=0)$complete=false;else $completion=max($completion??'',$lineEnd);
   $lineRows[]=['product_id'=>$item->product_id,'product_name'=>$item->description?:$item->product?->name,'category_id'=>$item->product?->category_id,'category_evidence'=>'current_not_historical_feature','unit'=>$unit,'ordered'=>$ordered===null?null:(float)$ordered,'received'=>round($received,3),'accepted'=>round($accepted,3),'remaining'=>$ordered===null?null:max(0,round($ordered-$accepted,3))];
  }
  if(in_array($po->status,['draft','cancelled']))$complete=false;
  $lead=$complete&&$origin&&$completion>=$origin?(int)Date::parse($origin)->diffInDays(Date::parse($completion)):null;
  $shipments=$loaded['shipments']??Shipment::with('milestones')->where(fn($q)=>$q->where('purchase_order_id',$po->id)->orWhereHas('purchaseOrders',fn($p)=>$p->where('purchase_orders.id',$po->id)))->get();
  $milestones=$shipments->flatMap(fn($s)=>$s->milestones->map(fn($m)=>['shipment_id'=>$s->id,'type'=>$m->milestone_type,'status'=>$m->status,'planned'=>$m->planned_at?->toIso8601String(),'estimated'=>$m->estimated_at?->toIso8601String(),'actual'=>$m->actual_at?->toIso8601String(),'known_at'=>$m->updated_at->toIso8601String(),'source'=>$m->source]))->all();
  $actual=function(array $types)use($milestones){return collect($milestones)->whereIn('type',$types)->whereNotNull('actual')->min('actual');};
  $dispatch=$actual(['vessel_departure','departed','departure','origin_departure']);$ready=$actual(['cargo_ready','supplier_dispatch']);$arrival=$actual(['destination_port','arrived','arrival','destination_arrival']);$customsStart=$actual(['customs_started','customs_arrival']);$customsEnd=$actual(['customs_cleared','customs_clearance']);
  $duration=fn($a,$b)=>$a&&$b&&$b>=$a?round(Date::parse($a)->diffInHours(Date::parse($b))/24,2):null;
  return ['order_id'=>$po->id,'reference'=>$po->po_number,'supplier_id'=>$po->supplier_id,'status'=>$po->status,'ordered_at'=>$origin,'confirmed_at'=>$known,
   'original_promise'=>$promise,'current_promise'=>$po->expected_at?->toDateString(),'commitment_evidence'=>$commit?'recorded_commitment':'original_unknown','revisions'=>$revisions,
   'complete'=>$complete,'completion_date'=>$complete?$completion:null,'lead_days'=>$lead,'label_known_at'=>$complete?$labelKnown:null,
   'late_days'=>$complete&&$promise?(int)max(0,Date::parse($promise)->diffInDays(Date::parse($completion),false)):null,
   'partial_receipt_count'=>$receipts->count(),'receipts'=>$receipts->map(fn($r)=>['id'=>$r->id,'reference'=>$r->receipt_number,'received_at'=>$r->received_at->toIso8601String(),'recorded_at'=>$r->created_at->toIso8601String()])->values()->all(),
   'lines'=>$lineRows,'line_count'=>isset($initial['items'])?count($initial['items']):null,
   'promised_days'=>$promise&&$origin&&$promise>=$origin?(int)Date::parse($origin)->diffInDays(Date::parse($promise)):null,
   'ml_eligible'=>$commit&&$origin&&$known&&substr($known,0,10)<=$origin&&$scopeUnchanged&&$promise!==null,
   'scope_fingerprint'=>hash('sha256',json_encode($scope($po->items->toArray()))),'scope_unchanged'=>$scopeUnchanged,
   'shipments'=>$shipments->map(fn($s)=>$s->only(['id','tracking_number','origin_port','destination_port','transport_mode','eta','status']))->values()->all(),'milestones'=>array_values($milestones),
   'stages'=>['supplier_preparation_days'=>$duration($origin,$ready),'transit_days'=>$duration($dispatch,$arrival),'customs_days'=>$duration($customsStart,$customsEnd),'local_receiving_days'=>$duration($customsEnd,$completion)],
   'currency'=>$po->currency,'base_value'=>$po->total_amount_eur,'exchange_rate_snapshot'=>$po->exchange_rate,
   'inspections'=>$loaded['inspections']??\App\Models\QualityInspection::where('purchase_order_id',$po->id)->whereNotNull('finalized_at')->get(['id','product_id','decision','accepted_quantity','rejected_quantity','finalized_at'])->toArray(),
   'claims'=>$loaded['claims']??\App\Models\SupplierClaim::where('purchase_order_id',$po->id)->get(['id','status','claim_date','resolved_at'])->toArray(),
   'supplier_preparation_delay_evidence'=>\App\Models\ShipmentIntelligence::whereIn('shipment_id',$shipments->filter(fn($s)=>$s->supplier_id===$po->supplier_id)->pluck('id'))->where('is_current',true)->get()->flatMap(fn($r)=>$r->evidence['supplier_feedback']??[])->values()->all(),
   'qualification'=>'One completed target per order; physical end-to-end lead time is not supplier fault. Only recorded supplier preparation milestones feed attributable delay evidence; transit/customs are separate. Missing stages and original commitments remain unknown.'];
 }
 public function distribution(array $rows,?int $product=null,?int $category=null,?string $origin=null,?string $mode=null,?string $destination=null):array {
  $completed=collect($rows)->filter(fn($r)=>$r['complete']&&$r['lead_days']!==null&&$r['label_known_at']<=today()->toDateString()&&(!$product||collect($r['lines'])->contains('product_id',$product))&&(!$category||collect($r['lines'])->contains('category_id',$category))
    &&(!$origin||collect($r['shipments'])->contains('origin_port',$origin))&&(!$mode||collect($r['shipments'])->contains('transport_mode',$mode))&&(!$destination||collect($r['shipments'])->contains('destination_port',$destination)));
  $values=$completed->pluck('lead_days')->sort()->values();$n=$values->count();$quantile=fn($p)=>$n?$values[(int)round(($n-1)*$p)]:null;$assessed=$completed->whereNotNull('late_days');
  return ['samples'=>$n,'eligible'=>$n>=config('supplier_intelligence.minimum_orders'),'median_days'=>$quantile(.5),'p10_days'=>$quantile(.1),'p90_days'=>$quantile(.9),'variability_days'=>$n?$quantile(.9)-$quantile(.1):null,
    'on_time_percent'=>$assessed->count()?round(100*$assessed->where('late_days',0)->count()/$assessed->count(),1):null,'promised_samples'=>$assessed->count(),'average_late_days'=>$assessed->count()?round($assessed->avg('late_days'),1):null,
    'partial_orders'=>$completed->where('partial_receipt_count','>',1)->count(),'excluded_orders'=>count($rows)-$n,'qualification'=>'Descriptive historical range, not a calibrated prediction interval. Categories and routes are recorded context, not inferred causes.'];
 }
 public function capture(Supplier $supplier,array $rows):AnalyticsSnapshot {
  return AnalyticsSnapshot::where('company_id',$supplier->company_id)->where('entity_type','supplier_intelligence')->where('entity_id',$supplier->id)->where('warehouse_id',0)->whereDate('snapshot_date',today())->first()
   ??AnalyticsSnapshot::createOrFirst(['company_id'=>$supplier->company_id,'entity_type'=>'supplier_intelligence','entity_id'=>$supplier->id,'warehouse_id'=>0,'snapshot_date'=>today()],['observed_at'=>now(),'feature_version'=>'supplier-history-v1','facts'=>['orders'=>$rows,'captured_at'=>now()->toIso8601String()]]);
 }
}
