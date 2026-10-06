<?php
namespace App\Services;
use App\Models\{Product,Warehouse,Company,InventoryPlanningPolicy,InventoryRecommendation,AnalyticsPrediction,PurchaseRequest,PurchaseOrderItem,ProcurementAward,GoodsReceipt,AnalyticsSnapshot};
use App\Support\Money;
use Illuminate\Support\Facades\{Auth,DB,Cache};
use Illuminate\Support\Str;

final class InventoryPlanningService {
 public function authorize(bool $manage=false):void {app(InventoryIntelligenceService::class)->authorize();if($manage)abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'procurement.manage'),403);}
 private function finance():bool{return app(PermissionService::class)->roleHasPermission(Auth::user()->role,'analytics.finance');}
 public function options(array $input):array {return validator($input,[
  'warehouse_id'=>'nullable|integer|min:1','supplier_id'=>'nullable|integer|min:1','unit'=>'nullable|string|max:30','order_date'=>'nullable|date_format:Y-m-d|after_or_equal:today',
  'delay_days'=>'sometimes|integer|min:0|max:180','demand_multiplier'=>'sometimes|numeric|min:0.1|max:5','service_level'=>'sometimes|in:0.9,0.95,0.98,0.99',
  'base_quantity'=>'sometimes|numeric|min:0|max:100000000','budget'=>'sometimes|numeric|min:0|max:1000000000','type'=>'sometimes|in:purchase,transfer','source_warehouse_id'=>'nullable|integer|min:1','transfer_days'=>'nullable|integer|min:0|max:180',
 ])->validate();}
 public function policy(int $id,array $input):array {
  $this->authorize(true);$p=app(InventoryIntelligenceService::class)->product($id);
  $d=validator($input,['warehouse_id'=>'nullable|integer|min:1','service_level'=>'required|in:0.9,0.95,0.98,0.99','priority'=>'required|integer|min:1|max:5','review_days'=>'required|integer|min:1|max:60','maximum_stock'=>'nullable|numeric|gt:0','allocation_share'=>'nullable|numeric|min:0|max:1'])->validate();
  $w=empty($d['warehouse_id'])?null:Warehouse::findOrFail($d['warehouse_id']);unset($d['warehouse_id']);
  $version=DB::transaction(function()use($p,$w,$d){
   Product::whereKey($p->id)->lockForUpdate()->firstOrFail();
   if($w&&isset($d['allocation_share'])){
    $others=InventoryPlanningPolicy::where('product_id',$p->id)->where('scope_key','!=',$w->id)->whereIn('warehouse_id',Warehouse::where('is_active',true)->select('id'))->latest('id')->get()->unique('scope_key');
    abort_if($others->sum(fn($p)=>(float)($p->settings['allocation_share']??0))+(float)$d['allocation_share']>1.000001,422,'Warehouse allocation shares cannot total more than 100% of company demand.');
   }
   return InventoryPlanningPolicy::create(['company_id'=>$p->company_id,'product_id'=>$p->id,'warehouse_id'=>$w?->id,'scope_key'=>$w?->id??0,'version'=>(string)Str::uuid(),'settings'=>$d,'created_by'=>Auth::id()]);
  });
  app(AnalyticsDataService::class)->audit('inventory_planning.policy_changed',['product_id'=>$id,'policy_id'=>$version->id,'version'=>$version->version,'settings'=>$d]);
  return $this->view($id,['warehouse_id'=>$w?->id]);
 }
 private function compute(Product $p,array $o=[]):array {
  $i=app(InventoryPlanningData::class)->input($p,$o);$transfer=($o['type']??'purchase')==='transfer';
  if($transfer){abort_unless($i['warehouse']&&!empty($o['source_warehouse_id'])&&$o['source_warehouse_id']!==$i['warehouse']['id'],422,'Choose different source and destination warehouses.');
   Warehouse::findOrFail($o['source_warehouse_id']);$source=app(InventorySnapshotService::class)->forProduct($p,null,$o['source_warehouse_id']);
   $i['supplier']=['usual_lead_time_days'=>$o['transfer_days']??app(InventoryPlanningData::class)->transferLead((int)$o['source_warehouse_id'],(int)$i['warehouse']['id']),'pack_size'=>$i['base_fractional']?.001:1,'minimum_order_quantity'=>0];$i['lead']=[];$i['factor']=1;$i['unit']=$p->unit;$i['unit_fractional']=$i['base_fractional'];$i['landed_unit_allowance']=null;
  }
  $fingerprint=hash('sha256',json_encode(['inventory-planning-v4.1',$p->company_id,$i,$o]));
  $r=Cache::remember('planning-result:'.$fingerprint,900,fn()=>app(InventoryPlanningMath::class)->calculate($i,$o));
  if($transfer){$r['source_available']=$source['available_to_promise'];$r['feasible']=$r['feasible']&&$r['base_quantity']<=$source['available_to_promise'];$r['assumptions'][]='Transfer arrival uses completed-route evidence or explicitly supplied transit days; no transfer is created.';}
  $r+=['fingerprint'=>$fingerprint,'product'=>$i['product'],'warehouse'=>$i['warehouse'],'policy'=>$i['policy'],'policy_id'=>$i['policy_id'],'policy_version'=>$i['policy_version'],'scope'=>$i['scope'],'stock'=>$i['stock'],'supplier_id'=>$transfer?null:($i['supplier']['supplier_id']??null),'supplier_name'=>$transfer?null:($i['supplier']['name']??null),'incoming_transfers'=>$i['incoming_transfers'],'at_risk_purchase_orders'=>$i['at_risk_purchase_orders'],'lead_evidence'=>$i['lead'],'valuation_unit_cost'=>$i['valuation_unit_cost'],'landed_cost_evidence'=>$i['landed_cost_evidence'],'landed_cost_qualification'=>$i['landed_cost_qualification'],'planning_options'=>$o,'type'=>$transfer?'transfer':'purchase'];
  $r['assumptions']=array_merge($r['assumptions'],$i['scope_assumptions']);
  $r=app(InventoryPlanningInsights::class)->enrich($r,$i);
  return [$r,$i];
 }
 private function safe(array $r):array {if(!$this->finance()){foreach(['estimated_cost','base_cost','base_unit_price','unit_price','estimated_landed_cost','valuation_unit_cost'] as $k)$r[$k]=null;foreach(['unit_price','base_unit_price'] as $k)if(isset($r['supplier_risk']))$r['supplier_risk'][$k]=null;}return $r;}
 /** Internal advisory adapter for decision orchestration; no records/actions. */
 public function decisionEvidence(int $id,array $options=[]):array {$this->authorize();$o=$this->options($options);$p=app(InventoryIntelligenceService::class)->product($id);[$plan,$input]=$this->compute($p,$o);return ['plan'=>$plan,'input'=>$input];}
 public function view(int $id,array $options=[]):array {
  $this->authorize();$o=$this->options($options);abort_if(isset($o['budget'])&&!$this->finance(),403);
  $p=app(InventoryIntelligenceService::class)->product($id);[$r,$i]=$this->compute($p,$o);
  $suppliers=array_map(fn($s)=>collect($s)->only('supplier_id','name','usual_lead_time_days','minimum_order_quantity','pack_size')->all(),$i['suppliers']);
  $previous=InventoryRecommendation::where('product_id',$id)->where('warehouse_id',$i['warehouse']['id']??null)->whereNotNull('planning_key')->latest('id')->first();
  $insights=app(InventoryPlanningInsights::class);
  return ['plan'=>$this->safe($r),'suppliers'=>$suppliers,'units'=>$p->units()->where('is_active',true)->get(['code','factor_to_base']),
   'supplier_options'=>$insights->supplierOptions($i,$this->finance()),'transfer_opportunities'=>$insights->transfers($p,$r),'changes'=>$insights->changes($r,$previous?->explanation),
   'recommendation'=>$previous?->only('id','status','feedback','purchase_request_id'),'financial_context'=>$this->finance()?$insights->finance($i['currency']):null,
   'warehouses'=>Warehouse::where('is_active',true)->get(['id','name']),'can_manage'=>app(PermissionService::class)->roleHasPermission(Auth::user()->role,'procurement.manage'),'can_finance'=>$this->finance(),
   'policies'=>InventoryPlanningPolicy::where('product_id',$id)->where('scope_key',$i['warehouse']['id']??0)->latest('id')->limit(10)->get(['id','version','settings','created_at']),
   'outcomes'=>$this->outcomes($id,false)];
 }
 public function scenarios(int $id,array $input):array {
  $this->authorize();$d=validator($input,['scenarios'=>'required|array|min:1|max:4','scenarios.*'=>'present|array'])->validate();
  $p=app(InventoryIntelligenceService::class)->product($id);return ['scenarios'=>array_map(function($s)use($p){$o=$this->options($s);abort_if(isset($o['budget'])&&!$this->finance(),403);[$r]=$this->compute($p,$o);return $this->safe($r);},$d['scenarios']),'read_only'=>true];
 }
 public function save(int $id,array $options=[]):array {
  $this->authorize(true);$o=$this->options($options);abort_if(($o['type']??'purchase')!=='purchase',422,'Transfer scenarios are read-only; review Transfers to take action.');abort_if(isset($o['budget'])&&!$this->finance(),403);
  $p=app(InventoryIntelligenceService::class)->product($id);[$plan]=$this->compute($p,$o);
  return DB::transaction(function()use($p,$plan){
   Product::whereKey($p->id)->lockForUpdate()->firstOrFail();$old=InventoryRecommendation::where('product_id',$p->id)->where('warehouse_id',$plan['warehouse']['id']??null)->whereNotNull('planning_key')->latest('id')->first();
   $key=hash('sha256',$p->company_id.':'.$plan['fingerprint']);$rec=InventoryRecommendation::where('planning_key',$key)->first();
   if(!$rec){$anchor=AnalyticsSnapshot::firstOrCreate(['company_id'=>$p->company_id,'entity_type'=>'inventory_plan','entity_id'=>$p->id,'warehouse_id'=>$plan['warehouse']['id']??0,'snapshot_date'=>today()],['observed_at'=>now(),'feature_version'=>'planning-v4','facts'=>['unit'=>$p->unit,'first_planning_stock'=>$plan['stock']]]);
    $prediction=AnalyticsPrediction::create(['company_id'=>$p->company_id,'prediction_type'=>'inventory_plan','entity_type'=>'product','entity_id'=>$p->id,'model_key'=>'inventory-planning-v4','model_version'=>$plan['policy_version'],'input_feature_version'=>'planning-v4','analytics_snapshot_id'=>$anchor->id,'value'=>['horizon'=>90,'unit'=>$p->unit,'daily'=>$plan['daily'],'quality'=>$plan['quality'],'warehouse_id'=>$plan['warehouse']['id']??null],'generated_at'=>now(),'valid_until'=>now()->addDay()]);
    InventoryRecommendation::where('product_id',$p->id)->where('warehouse_id',$plan['warehouse']['id']??null)->whereNotNull('planning_key')->whereIn('status',['open','viewed'])->update(['status'=>'superseded']);
    $rec=InventoryRecommendation::create(['company_id'=>$p->company_id,'product_id'=>$p->id,'warehouse_id'=>$plan['warehouse']['id']??null,'planning_policy_id'=>$plan['policy_id'],'analytics_prediction_id'=>$prediction->id,'planning_key'=>$key,'risk'=>$plan['risk'],'explanation'=>$plan]);
    $this->events($p,$rec,$plan,$old);
   }return ['recommendation_id'=>$rec->id,'status'=>$rec->status,'plan'=>$this->safe($plan)];
  });
 }
 public function listing(array $f=[]):array {
  $this->authorize();$f=validator($f,['q'=>'nullable|string|max:80','warehouse_id'=>'nullable|integer|min:1','view'=>'nullable|in:all,attention,replenishment,excess,warehouses,suppliers,scenarios'])->validate();
  if(!empty($f['warehouse_id']))Warehouse::findOrFail($f['warehouse_id']);
  $query=Product::where('lifecycle_status','active')->when($f['q']??null,fn($q,$s)=>$q->where('name','like','%'.$s.'%'));
  $states=match($f['view']??'all'){'attention'=>['critical','potential_stockout','reorder_now','reorder_soon'],'replenishment'=>['critical','potential_stockout','reorder_now','reorder_soon'],'excess'=>['excess_stock','overstock','slow_moving'],default=>[]};
  if($states){$latest=InventoryRecommendation::selectRaw('MAX(id)')->whereNotNull('planning_key')->where('warehouse_id',empty($f['warehouse_id'])?null:(int)$f['warehouse_id'])->groupBy('product_id');$query->whereIn('id',InventoryRecommendation::whereIn('id',$latest)->whereIn('explanation->optimization_state',$states)->whereIn('status',['open','viewed','postponed'])->whereHas('prediction',fn($q)=>$q->where('valid_until','>',now()))->select('product_id'));}
  $page=$query->orderBy('name')->paginate(25);
  $recs=InventoryRecommendation::whereIn('product_id',collect($page->items())->pluck('id'))->whereNotNull('planning_key')->where('warehouse_id',empty($f['warehouse_id'])?null:(int)$f['warehouse_id'])->latest('id')->get()->unique('product_id')->keyBy('product_id');
  return ['rows'=>array_map(function($p)use($recs){$r=$recs->get($p->id);return ['product'=>$p->only('id','name','unit'),'recommendation_id'=>$r?->id,'status'=>$r?->status,'plan'=>$r?$this->safe($r->explanation):null];},$page->items()),'total'=>$page->total(),'page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'warehouses'=>Warehouse::where('is_active',true)->get(['id','name'])];
 }
 public function consolidate(array $input):array {
  $this->authorize();abort_unless($this->finance(),403);
  $d=validator($input,['recommendations'=>'required|array|min:1|max:30','recommendations.*'=>'required|integer|distinct','budget'=>'nullable|numeric|min:0|max:1000000000'])->validate();$items=[];
  foreach($d['recommendations'] as $id){$rec=InventoryRecommendation::whereNotNull('planning_key')->findOrFail($id);$p=app(InventoryIntelligenceService::class)->product($rec->product_id);[$plan]=$this->compute($p,$rec->explanation['planning_options']);
   abort_unless(hash_equals($plan['fingerprint'],$rec->explanation['fingerprint']),409,'Planning inputs changed. Recalculate and review the product before consolidation.');
   abort_unless(in_array($rec->status,['open','viewed']),409,'The recommendation has already been actioned.');$items[]=['recommendation_id'=>$id]+$plan;}
  usort($items,fn($a,$b)=>($b['priority']<=>$a['priority'])?:strcmp($a['stockout_date']??'9999',$b['stockout_date']??'9999')?:($a['product']['id']<=>$b['product']['id']));
  $remaining=isset($d['budget'])?Money::minor($d['budget']):null;$groups=[];
  foreach($items as &$item){if($remaining!==null){$rec=InventoryRecommendation::findOrFail($item['recommendation_id']);[$adjusted]=$this->compute(Product::findOrFail($item['product']['id']),array_replace($rec->explanation['planning_options'],['budget'=>Money::decimal($remaining),'base_quantity'=>$item['base_quantity']??0]));$item=array_replace($item,$adjusted);$remaining-=Money::minor($item['base_cost']??0);}
   $key=($item['supplier_id']??0).':'.$item['currency'].':'.substr($item['expected_arrival']??'unknown',0,7);$groups[$key]??=['supplier_id'=>$item['supplier_id'],'supplier_name'=>$item['supplier_name'],'currency'=>$item['currency'],'delivery_period'=>substr($item['expected_arrival']??'unknown',0,7),'items'=>[]];$groups[$key]['items'][]=$item;
  }unset($item);
  foreach($groups as &$g){$g['estimated_commitment']=collect($g['items'])->contains(fn($r)=>$r['estimated_cost']===null)?null:Money::add(...array_column($g['items'],'estimated_cost'));}unset($g);
  $context=['recommendations'=>$d['recommendations'],'budget'=>$d['budget']??null,'items'=>array_map(fn($r)=>[$r['recommendation_id'],$r['fingerprint'],$r['base_quantity']],$items)];
  return ['groups'=>array_values($groups),'review_fingerprint'=>hash('sha256',json_encode($context)),'base_currency'=>$items[0]['base_currency'],'base_commitment'=>collect($items)->contains(fn($r)=>$r['base_cost']===null)?null:Money::add(...array_column($items,'base_cost')),'unspent_budget'=>$remaining===null?null:Money::decimal($remaining),'allocation_rule'=>'priority descending, then earliest projected stockout; whole MOQ/pack increments; unmet coverage shown explicitly'];
 }
 public function draft(array $input):array {
  $this->authorize(true);abort_unless($this->finance(),403);$fingerprint=validator($input,['review_fingerprint'=>'required|string|size:64'])->validate()['review_fingerprint'];
  return DB::transaction(function()use($input,$fingerprint){
   Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
   $ids=validator($input,['recommendations'=>'required|array|min:1|max:30','recommendations.*'=>'integer|distinct'])->validate()['recommendations'];
   $records=InventoryRecommendation::whereNotNull('planning_key')->whereIn('id',$ids)->orderBy('product_id')->lockForUpdate()->get();abort_unless($records->count()===count($ids),404);
   if($records->every(fn($r)=>$r->purchase_request_id)){
    abort_unless($records->every(fn($r)=>($r->feedback['review_fingerprint']??null)===$fingerprint),409,'This selection was already converted using a different review.');
    return ['purchase_requests'=>PurchaseRequest::whereIn('id',$records->pluck('purchase_request_id'))->get(),'reused'=>true];}
   $review=$this->consolidate($input);abort_unless(hash_equals($review['review_fingerprint'],$fingerprint),409,'The reviewed plan changed. Review again before drafting.');
   abort_if($records->pluck('product_id')->duplicates()->isNotEmpty(),422,'Select only one planning scope per product in a purchasing batch.');$requests=[];
   foreach($review['groups'] as $group){$items=[];$chosen=[];
    foreach($group['items'] as $r){$p=Product::whereKey($r['product']['id'])->lockForUpdate()->firstOrFail();
     abort_if(\App\Models\PurchaseRequestItem::where('product_id',$p->id)->whereIn('purchase_request_id',PurchaseRequest::whereIn('status',['draft','submitted','approved','sourcing'])->select('id'))->exists(),409,'An open purchase request exists for this product. Review it before drafting another.');
     abort_unless($r['supplier_id']&&$r['feasible']&&$r['coverage_supported']&&$r['base_quantity']>0&&$r['unit_price']!==null,422,'The selection contains an infeasible, unpriced, unsupported or zero-quantity item.');
     app(UnitConversionService::class)->resolve($p,$r['quantity'],$r['unit']);$items[]=['product_id'=>$p->id,'description'=>$p->name,'unit'=>$r['unit'],'quantity'=>$r['quantity'],'estimated_unit_price'=>$r['unit_price']];$chosen[]=$r;
    }
    $pr=app(ProcurementService::class)->createRequest(['requested_at'=>today()->toDateString(),'required_by'=>collect($chosen)->min('expected_arrival'),'currency'=>$group['currency'],'notes'=>'Inventory Planning V4 — supplier '.$group['supplier_name'].'; period '.$group['delivery_period'].'. Human review and existing approval workflow required. Warehouse scopes: '.implode(', ',array_map(fn($r)=>$r['warehouse']['name']??'company',$chosen)),'items'=>$items]);
    foreach($chosen as $r){$rec=$records->firstWhere('id',$r['recommendation_id']);$feedback=$rec->feedback??[];$feedback['history'][]=['action'=>'created_pr','user_id'=>Auth::id(),'at'=>now()->toIso8601String(),'purchase_request_id'=>$pr->id,'supplier_id'=>$r['supplier_id'],'base_quantity'=>$r['base_quantity']];$rec->update(['purchase_request_id'=>$pr->id,'status'=>$r['shortfall']>0?'adjusted':'accepted','feedback'=>array_replace($feedback,['user_id'=>Auth::id(),'at'=>now()->toIso8601String(),'recommended_base_quantity'=>$rec->explanation['desired_base_quantity'],'base_quantity'=>$r['base_quantity'],'supplier_id'=>$r['supplier_id'],'review_fingerprint'=>$fingerprint])]);}
    $requests[]=$pr;
   }return ['purchase_requests'=>$requests,'reused'=>false];
  });
 }
 public function outcomes(int $id,bool $persist=true):array {
  $this->authorize();$rows=[];
  foreach(InventoryRecommendation::with('prediction')->where('product_id',$id)->whereNotNull('planning_key')->whereNotNull('purchase_request_id')->latest('id')->limit(20)->get() as $r){
   $poIds=ProcurementAward::whereHas('requestItem',fn($q)=>$q->where('purchase_request_id',$r->purchase_request_id))->whereNotNull('purchase_order_id')->pluck('purchase_order_id');
   $receipts=GoodsReceipt::with(['items'=>fn($q)=>$q->where('product_id',$id)])->whereIn('purchase_order_id',$poIds)->where('status','posted')->where('received_at','>=',$r->created_at)->where('received_at','<=',now())->when($r->warehouse_id,fn($q)=>$q->where('warehouse_id',$r->warehouse_id))->get();
   $lines=$receipts->flatMap->items->where('inventory_unit',$r->prediction->value['unit']);$qty=$lines->sum('accepted_base_quantity');
   $from=$r->created_at->toDateString();$end=last($r->explanation['daily'])['date']??$from;
   $obs=AnalyticsSnapshot::where('entity_type',$r->warehouse_id?'inventory':'demand_observation')->where('entity_id',$id)->where('warehouse_id',$r->warehouse_id??0)->where('facts->unit',$r->prediction->value['unit'])->whereDate('snapshot_date','>',$from)->whereDate('snapshot_date','<=',min($end,today()->subDay()->toDateString()))->get();
   $complete=$end<today()->toDateString()&&$obs->count()===(int)\Carbon\Carbon::parse($from)->diffInDays($end)&&$obs->every(fn($o)=>isset($o->facts['available']));
   $ordered=PurchaseOrderItem::whereIn('purchase_order_id',$poIds)->where('product_id',$id)->get();
   $orderedQty=$ordered->isNotEmpty()&&$ordered->every(fn($i)=>$i->inventory_unit===$r->prediction->value['unit'])?round($ordered->sum('base_quantity'),3):null;
   $demandComplete=$complete&&!$r->warehouse_id&&$obs->every(fn($o)=>$o->facts['complete']??false);
   $predicted=collect($r->explanation['daily'])->where('date','>',$from)->sum('quantity');
   $actualDemand=$demandComplete?$obs->sum(fn($o)=>(float)$o->facts['demand']):null;
   $investment=$lines->isEmpty()||$lines->contains(fn($l)=>$l->base_purchase_unit_cost===null)?null:Money::add(...$lines->map(fn($l)=>Money::multiply($l->accepted_base_quantity,$l->base_purchase_unit_cost))->all());
   $out=['recommendation_id'=>$r->id,'policy_version'=>$r->explanation['policy_version'],'state'=>$complete&&$qty>0?'completed_observation':'pending_or_incomplete','recommended_quantity'=>$r->explanation['desired_base_quantity'],'chosen_quantity'=>$r->feedback['base_quantity']??null,'received_quantity'=>round($qty,3),'received_dates'=>$receipts->pluck('received_at')->map(fn($d)=>$d->toDateString())->all(),'expected_arrival'=>$r->explanation['expected_arrival'],'observation_days'=>$obs->count(),'stockout_days'=>$obs->filter(fn($o)=>$o->facts['stockout']??((float)($o->facts['available']??1)<=0))->count(),'latest_available'=>$obs->sortBy('snapshot_date')->last()?->facts['available']??null,'recorded_purchase_investment'=>$investment,'estimated_investment'=>$r->explanation['base_cost'],'user_modified'=>($r->feedback['base_quantity']??null)!==$r->explanation['desired_base_quantity'],'supplier_delay'=>$receipts->contains(fn($g)=>$g->received_at->toDateString()>($r->explanation['expected_arrival']??'9999')),'lost_demand'=>null,'proven_savings'=>null,'qualification'=>'Observational receipt/coverage evidence only; unforeseen demand and causal savings are not inferred.'];
   $out+=['actual_ordered_quantity'=>$orderedQty,'observed_demand'=>$actualDemand,'fulfilled_demand_error'=>$actualDemand===null?null:round($actualDemand-$predicted,3),'demand_error_qualification'=>'Only complete, uncensored company observations are compared; excess observed demand is not assigned a causal explanation.'];
   if($persist&&InventoryIntelligenceService::artifactHash($r->outcome??[])!==InventoryIntelligenceService::artifactHash($out)){$r->update(['outcome'=>$out,'outcome_updated_at'=>now()]);app(AnalyticsDataService::class)->audit('inventory_planning.outcome',['recommendation_id'=>$r->id,'outcome'=>$out]);}$rows[]=$this->finance()?$out:collect($out)->except('recorded_purchase_investment','estimated_investment')->all();
  }return $rows;
 }
 public function maintain(float $deadline):void {
  $this->authorize(true);$company=Auth::user()->company_id;$ids=Cache::pull('planning-dirty:'.$company,[]);$q=Product::where('lifecycle_status','active')->orderBy('id');if($ids)$q->whereIn('id',array_slice($ids,0,10));$handled=[];
  $count=0;foreach($q->cursor() as $p){if(microtime(true)>$deadline||$count>=10)break;$key='planning-daily:v4.1:'.$p->company_id.':'.$p->id.':'.today()->toDateString();if(!$ids&&Cache::has($key))continue;try{$this->scheduledSave($p->id);foreach(InventoryPlanningPolicy::where('product_id',$p->id)->whereIn('warehouse_id',Warehouse::where('is_active',true)->select('id'))->latest('id')->get()->unique('scope_key')->take(5) as $policy){if(microtime(true)>$deadline)break;$this->scheduledSave($p->id,$policy->warehouse_id);}$this->outcomes($p->id);Cache::put($key,true,86400);}catch(\Throwable $e){Cache::put($key,true,1800);\Illuminate\Support\Facades\Log::warning('Inventory planning refresh deferred',['product_id'=>$p->id,'error'=>class_basename($e)]);} $count++;$handled[]=$p->id;}
  $remaining=array_values(array_diff($ids,$handled));if($remaining)Cache::put('planning-dirty:'.$company,array_values(array_unique(array_merge(Cache::get('planning-dirty:'.$company,[]),$remaining))),86400);
 }
 private function scheduledSave(int $product,?int $warehouse=null):void {
  $r=InventoryRecommendation::where('product_id',$product)->where('warehouse_id',$warehouse)->whereNotNull('planning_key')->latest('id')->first();
  if($r?->status==='postponed'&&($r->feedback['until']??'')>today()->toDateString())return;
  $this->save($product,['warehouse_id'=>$warehouse]);
 }
 public static function invalidate(int $company,array $ids):void {
  $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));foreach($ids as $id)Cache::put('planning-generation:'.$company.':'.$id,(string)Str::uuid(),86400);
  Cache::put('planning-dirty:'.$company,array_slice(array_values(array_unique(array_merge(Cache::get('planning-dirty:'.$company,[]),$ids))),0,200),86400);
 }
 public function feedback(int $id,array $input):array {
  $this->authorize(true);$d=validator($input,['action'=>'required|in:accepted,quantity_changed,supplier_changed,postponed,dismissed,transferred','note'=>'nullable|string|max:1000','base_quantity'=>'nullable|numeric|gt:0|max:100000000','supplier_id'=>'nullable|integer|min:1','until'=>'nullable|date_format:Y-m-d|after_or_equal:today','transfer_id'=>'nullable|integer|min:1'])->validate();
  return DB::transaction(function()use($id,$d){$r=InventoryRecommendation::whereNotNull('planning_key')->lockForUpdate()->findOrFail($id);abort_if(in_array($r->status,['superseded','dismissed'])||$r->purchase_request_id,409,'This recommendation is no longer open for a new decision.');
   if($d['action']==='quantity_changed'){abort_unless(isset($d['base_quantity']),422,'Provide the chosen quantity.');app(UnitConversionService::class)->resolve($r->product,$d['base_quantity'],$r->product->unit);}
   if($d['action']==='supplier_changed')abort_unless(\App\Models\ProductSupplier::where('product_id',$r->product_id)->where('supplier_id',$d['supplier_id']??0)->where('is_active',true)->whereHas('supplier',fn($q)=>$q->where('is_active',true))->exists(),422,'Choose an active supplier for this product.');
   if($d['action']==='postponed')abort_unless(isset($d['until']),422,'Provide the next review date.');
   if($d['action']==='transferred'){$transfer=\App\Models\StockTransfer::with('items')->findOrFail($d['transfer_id']??0);abort_unless($transfer->items->contains('product_id',$r->product_id)&&(! $r->warehouse_id||$transfer->destination_warehouse_id===$r->warehouse_id)&&in_array($transfer->status,['in_transit','partially_received','received']),422,'Select an actual dispatched or received transfer for this product and destination.');}
   $feedback=$r->feedback??[];$feedback['history'][]=$d+['user_id'=>Auth::id(),'at'=>now()->toIso8601String()];$feedback['last_action']=$d['action'];$feedback['until']=$d['until']??null;
   $r->update(['feedback'=>$feedback,'status'=>match($d['action']){'dismissed'=>'dismissed','postponed'=>'postponed','transferred'=>'transferred',default=>'viewed'},'viewed_at'=>now()]);
   app(AnalyticsDataService::class)->audit('inventory_planning.feedback',['recommendation_id'=>$r->id,'decision'=>$d]);
   if(in_array($d['action'],['quantity_changed','supplier_changed'])){
    $options=$r->explanation['planning_options'];$options[$d['action']==='quantity_changed'?'base_quantity':'supplier_id']=$d[$d['action']==='quantity_changed'?'base_quantity':'supplier_id'];
    $saved=$this->save($r->product_id,$options);$next=InventoryRecommendation::findOrFail($saved['recommendation_id']);$feedback['source_recommendation_id']=$r->id;$next->update(['feedback'=>$feedback,'status'=>'viewed','viewed_at'=>now()]);return $next->only('id','status','feedback');
   }
   return $r->only('id','status','feedback');
  });
 }
 private function events(Product $p,InventoryRecommendation $r,array $plan,?InventoryRecommendation $old):void {
  $before=$old?->explanation??[];$quantity=(float)($plan['base_quantity']??0);$oldQuantity=(float)($before['base_quantity']??0);
  $material=!$old||($before['optimization_state']??null)!==$plan['optimization_state']||abs($quantity-$oldQuantity)>=max((float)$plan['step'],abs($oldQuantity)*.1)||($before['stockout_date']??null)!==$plan['stockout_date']||($before['supplier_id']??null)!==$plan['supplier_id'];
  if(!$material)return;
  $types=['inventory.optimization.recommendation_changed'];
  if(in_array($plan['optimization_state'],['critical','potential_stockout','reorder_now','reorder_soon']))$types[]='inventory.optimization.reorder_required';
  if(($before['risk']??null)!==$plan['risk']||($before['stockout_date']??null)!==$plan['stockout_date'])$types[]='inventory.optimization.stockout_risk_changed';
  if(in_array($plan['optimization_state'],['excess_stock','overstock','slow_moving'])&&!in_array($before['optimization_state']??'', ['excess_stock','overstock','slow_moving']))$types[]='inventory.optimization.excess_detected';
  $transfers=app(InventoryPlanningInsights::class)->transfers($p,$plan);if($transfers)$types[]='inventory.optimization.transfer_opportunity';
  foreach($types as $type)app(BusinessEventService::class)->record($type,$r,$p->sku,['risk'=>$r->risk,'quantity'=>$plan['base_quantity'],'stockout_date'=>$plan['stockout_date'],'state'=>$plan['optimization_state'],'supplier_id'=>$plan['supplier_id'],'transfer_options'=>$transfers],'optimization:'.$type.':'.$r->planning_key);
 }
}
