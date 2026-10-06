<?php
namespace App\Services;

use App\Models\{EnterpriseDecision,Product,Warehouse,Company,User,PurchaseRequest,PurchaseRequestItem,ProcurementAward,PurchaseOrderItem,GoodsReceipt,AnalyticsSnapshot,StockTransfer,QualityInspection};
use Illuminate\Support\Facades\{Auth,DB,Cache,Log};
use Illuminate\Support\Str;

/** Advisory orchestration. Only an explicitly reviewed action may create a PR draft. */
final class EnterpriseDecisionService {
 const ACTIVE=['open','reviewed','accepted','modified'];
 public function authorize(bool $manage=false):void {app(InventoryPlanningService::class)->authorize($manage);}
 private function can(string $permission):bool{return app(PermissionService::class)->roleHasPermission(Auth::user()->role,$permission);}
 public function listing(array $filters=[]):array {
  $this->authorize();$f=validator($filters,['q'=>'nullable|string|max:80','product_id'=>'nullable|integer|min:1','warehouse_id'=>'nullable|integer|min:1','view'=>'nullable|in:attention,inventory,suppliers,incoming,finance,history,all','type'=>'nullable|in:REPLENISHMENT_DECISION,TRANSFER_VS_PURCHASE,SUPPLIER_SELECTION,PURCHASE_TIMING,INCOMING_STOCK_RISK,EXCESS_INVENTORY_ACTION','severity'=>'nullable|in:critical,high,warning,normal','page'=>'nullable|integer|min:1'])->validate();
  if(!empty($f['warehouse_id']))Warehouse::findOrFail($f['warehouse_id']);if(!empty($f['product_id']))Product::findOrFail($f['product_id']);
  $view=$f['view']??'attention';if($view==='finance')abort_unless($this->can('analytics.finance'),403);
  $q=EnterpriseDecision::with('purchaseRequest')->when($f['q']??null,fn($q,$s)=>$q->whereHas('product',fn($p)=>$p->where('name','like','%'.$s.'%')->orWhere('sku','like','%'.$s.'%')))->when($f['product_id']??null,fn($q,$v)=>$q->where('product_id',$v))->when($f['warehouse_id']??null,fn($q,$v)=>$q->where('warehouse_id',$v))->when($f['type']??null,fn($q,$v)=>$q->where('decision_type',$v))->when($f['severity']??null,fn($q,$v)=>$q->where('severity',$v));
  if($view==='history')$q->whereNotIn('status',['open','reviewed']);elseif($view!=='all')$q->whereIn('status',['open','reviewed']);
  if($view==='attention')$q->whereIn('severity',['critical','high','warning']);
  if($view==='inventory')$q->whereIn('decision_type',['REPLENISHMENT_DECISION','TRANSFER_VS_PURCHASE','PURCHASE_TIMING','EXCESS_INVENTORY_ACTION']);
  if($view==='suppliers')$q->where('decision_type','SUPPLIER_SELECTION');if($view==='incoming')$q->where('decision_type','INCOMING_STOCK_RISK');
  if($view==='finance'){$json="json_extract(alternatives, '$[0].base_cost')";if(DB::connection()->getDriverName()!=='sqlite')$json='json_unquote('.$json.')';$q->whereRaw('CAST('.$json.' AS DECIMAL(18,2)) >= ?',[config('enterprise_decisions.high_commitment')]);}
  $page=$q->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")->latest('id')->paginate(25);
  $summary=EnterpriseDecision::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total','status')->all();
  return ['rows'=>array_map(fn($d)=>$this->present($d,false),$page->items()),'total'=>$page->total(),'page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'summary'=>$summary,'quality'=>['generated'=>EnterpriseDecision::count(),'limited'=>EnterpriseDecision::where('confidence','limited')->count(),'completed_outcomes'=>EnterpriseDecision::where('outcome->state','completed_observation')->count(),'observed_stockouts'=>EnterpriseDecision::where('outcome->stockout_occurred',true)->count()],'products'=>Product::where('lifecycle_status','active')->orderBy('name')->limit(100)->get(['id','name']),'warehouses'=>Warehouse::where('is_active',true)->orderBy('name')->get(['id','name']),'can_manage'=>$this->can('procurement.manage'),'can_finance'=>$this->can('analytics.finance')];
 }
 public function detail(int $id):array {$this->authorize();return $this->present(EnterpriseDecision::with('purchaseRequest')->findOrFail($id),true);}
 private function present(EnterpriseDecision $d,bool $full):array {
  $r=$d->toArray();unset($r['purchase_request']);$r['purchase_request']=$d->purchaseRequest?->only('id','request_number','status','estimated_total','currency');
  $dirty=$d->last_checked_at->timestamp<(int)Cache::get('decisions-changed:'.$d->company_id.':'.$d->product_id,0);
  $expired=!empty($d->evidence['forecast']['valid_until'])&&\Carbon\Carbon::parse($d->evidence['forecast']['valid_until'])->lte(now());
  $r['stale']=$dirty||$expired||$d->last_checked_at->lt(now()->subHours(config('enterprise_decisions.stale_hours')));$r['confidence']=$r['stale']?'limited':$d->confidence;
  $r['url']='/inventory-intelligence?view=decisions&decision='.$d->id;$r['can_act']=in_array($d->status,['open','reviewed'])&&!($d->evidence['archived']??false);$r['recommended']=$d->alternatives[0]??null;
  // Embedded IDs in restored snapshots are historical, not live deep links.
  if($d->evidence['archived']??false)$r=$this->redact($r,['procurement','logistics','transfers','supplier_id']);
  $r=$this->safe($r);if(!$full){$r['evidence']=array_intersect_key($r['evidence'],array_flip(['product','warehouse','required_quantity','excess_quantity','forecast','unknowns','versions']));unset($r['alternatives'],$r['history'],$r['outcome']);}return $r;
 }
 private function safe(array $r):array {
  if(!$this->can('analytics.finance'))$r=$this->redact($r,['unit_price','base_unit_price','base_cost','estimated_cost','total_amount','estimated_total','finance','financial_intelligence','financial_account_ids','recorded_purchase_investment']);
  if(!$this->can('finance.view')||!$this->can('financial_accounts.view'))$r=$this->redact($r,['financial_intelligence']);
  if(!$this->can('supplier_performance.view'))$r=$this->redact($r,['supplier_risk','quality_acceptance_percent','quality_inspections','claims','late_delivery_percent','reliability_score']);
  if(!$this->can('shipments.view'))$r=$this->redact($r,['logistics','shipment_ids']);
  if(!$this->can('purchase_orders.view'))$r=$this->redact($r,['procurement','purchase_order_ids','purchase_request']);
  return $r;
 }
 private function redact(array $r,array $keys):array {$list=array_is_list($r);foreach($r as $key=>$v){if(in_array($key,$keys,true))unset($r[$key]);elseif(is_array($v))$r[$key]=$this->redact($v,$keys);}$r=array_filter($r,fn($v)=>!is_array($v)||!isset($v['criterion'])||$this->can('analytics.finance')||!in_array($v['criterion'],['cost','commitment']));return $list?array_values($r):$r;}
 public function refresh(int $id,?int $warehouse=null):array {
  $this->authorize();$bundle=app(EnterpriseDecisionEvidence::class)->assemble($id,$warehouse);$e=$bundle['evidence'];$types=[];
  if($bundle['required']>0){$types[]='REPLENISHMENT_DECISION';$types[]='PURCHASE_TIMING';if(count($e['supplier_options'])>1)$types[]='SUPPLIER_SELECTION';if($e['transfers'])$types[]='TRANSFER_VS_PURCHASE';}
  if(collect($e['procurement']['orders'])->contains('at_risk',true))$types[]='INCOMING_STOCK_RISK';
  if($e['excess_quantity']>0||in_array($bundle['plan']['optimization_state'],['slow_moving','overstock']))$types[]='EXCESS_INVENTORY_ACTION';
  $result=DB::transaction(function()use($id,$warehouse,$bundle,$types){
   Product::whereKey($id)->lockForUpdate()->firstOrFail();$rows=[];
   foreach($types as $type){$key=hash('sha256',Auth::user()->company_id.':'.$type.':'.$id.':'.($warehouse??0));$old=EnterpriseDecision::where('logical_key',$key)->latest('id')->lockForUpdate()->first();$candidate=$bundle;
    if($type==='INCOMING_STOCK_RISK')$candidate['severity']=$candidate['severity']==='critical'?'critical':'high';
    if($type==='EXCESS_INVENTORY_ACTION'){$candidate['severity']='warning';$candidate['alternatives']=[['key'=>'reduce_future_purchase','action'=>'reduce_future_purchase','base_quantity'=>0,'transfer_quantity'=>0,'quantity'=>0,'unit'=>$bundle['evidence']['product']['unit'],'supplier_id'=>null,'supplier_name'=>null,'base_cost'=>'0.00','feasible'=>true,'rank'=>1,'assumptions'=>['do_not_cancel_existing_commitments_automatically'],'impact'=>['excess_quantity'=>$bundle['evidence']['excess_quantity'],'projection_supported'=>$bundle['evidence']['forecast']['available']]],['key'=>'review_excess_transfer','action'=>'review_excess_transfer','base_quantity'=>0,'transfer_quantity'=>0,'quantity'=>0,'unit'=>$bundle['evidence']['product']['unit'],'supplier_id'=>null,'supplier_name'=>null,'base_cost'=>'0.00','feasible'=>true,'rank'=>2,'assumptions'=>['destination_need_requires_review'],'impact'=>['excess_quantity'=>$bundle['evidence']['excess_quantity'],'projection_supported'=>$bundle['evidence']['forecast']['available']]]];}
    if($old&&!$this->material($old,$candidate)&&$old->status!=='resolved'){$old->update(['last_checked_at'=>now()]);$rows[]=$old;continue;}
    if($old&&in_array($old->status,self::ACTIVE)){$old->update(['status'=>'superseded','history'=>$this->history($old,'superseded')]);$this->event($old,'superseded');}
    $reasoning=['situation'=>$type,'why'=>$bundle['evidence']['forecast']['available']?'forecast_and_operational_evidence':'configured_threshold_and_operational_evidence','ranking'=>'lower_weighted_risk_is_better_unknown_is_not_zero','could_change'=>['stock_or_reservations','supplier_price_or_lead','incoming_eta','forecast_health','policy_or_moq'],'policy_version'=>$bundle['evidence']['versions']['decision']];
    $d=EnterpriseDecision::create(['company_id'=>Auth::user()->company_id,'product_id'=>$id,'warehouse_id'=>$warehouse,'logical_key'=>$key,'version'=>(string)Str::uuid(),'decision_type'=>$type,'severity'=>$candidate['severity'],'confidence'=>$candidate['confidence'],'status'=>'open','source_fingerprint'=>$candidate['source_fingerprint'],'evidence'=>$candidate['evidence'],'alternatives'=>$candidate['alternatives'],'reasoning'=>$reasoning,'history'=>[['action'=>'created','at'=>now()->toIso8601String(),'user_id'=>Auth::id()]],'generated_at'=>now(),'evidence_cutoff_at'=>now(),'last_checked_at'=>now()]);
    app(DecisionLearningService::class)->capture($d,$bundle['learning_input']??[],$bundle['ranking_context']??[]);
    $this->event($d,'created');if($old)$this->event($d,'materially_changed');if($d->severity==='critical')$this->event($d,'critical');$rows[]=$d;
   }
   foreach(EnterpriseDecision::where('product_id',$id)->where('warehouse_id',$warehouse)->whereIn('status',self::ACTIVE)->whereNotIn('decision_type',$types)->get() as $d){$d->update(['status'=>'resolved','last_checked_at'=>now(),'history'=>$this->history($d,'resolved')]);$this->event($d,'resolved');}
   return $rows;
  });
  return ['decisions'=>array_map(fn($d)=>$this->present($d,false),$result),'evaluated_at'=>now()->toIso8601String()];
 }
 private function material(EnterpriseDecision $old,array $next):bool {
  if($old->severity!==$next['severity']||$old->confidence!==$next['confidence'])return true;
  $a=$old->evidence;$b=$next['evidence'];$x=$old->alternatives[0]??[];$y=$next['alternatives'][0]??[];
  foreach(['action','supplier_id','unit'] as $key)if(($x[$key]??null)!==($y[$key]??null))return true;
  foreach(['base_quantity','transfer_quantity'] as $key)if(abs(($x[$key]??0)-($y[$key]??0))>=max(.001,(float)($y['constraints']['step']??1),abs($x[$key]??0)*config('enterprise_decisions.material_quantity_fraction')))return true;
  foreach(['versions','supplier_options','unknowns','constraints'] as $key)if(json_encode($a[$key]??null)!==json_encode($b[$key]??null))return true;
  if(($a['forecast']['available']??false)!==$b['forecast']['available']||($a['forecast']['stockout_date']??null)!==$b['forecast']['stockout_date'])return true;
  $strip=function($rows){return array_map(fn($r)=>array_diff_key($r,['updated_at'=>true]),$rows);};
  if(json_encode($strip($a['procurement']['orders']))!==json_encode($strip($b['procurement']['orders']))||json_encode($a['procurement']['requests'])!==json_encode($b['procurement']['requests'])||json_encode($strip($a['logistics']))!==json_encode($strip($b['logistics'])))return true;
  foreach(['recorded_cash','po_outstanding','open_po_commitments','unlinked_supplier_documents','unknown_fx_records'] as $k)if(($a['finance'][$k]??null)!==($b['finance'][$k]??null))return true;
  $oldFinancial=$a['financial_intelligence']??null;$newFinancial=$b['financial_intelligence']??null;
  if(($oldFinancial===null)!==($newFinancial===null))return true;
  if($oldFinancial&&$newFinancial&&($oldFinancial['cash_complete']!==$newFinancial['cash_complete']||\App\Support\Money::compare(abs(\App\Support\Money::minor(\App\Support\Money::subtract($oldFinancial['cash_forecast']['net_change']??0,$newFinancial['cash_forecast']['net_change']??0)))/100,config('financial_intelligence.material_change'))>=0))return true;
  return abs(($a['excess_quantity']??0)-($b['excess_quantity']??0))>=max(1,abs($a['excess_quantity']??0)*.1);
 }
 private function history(EnterpriseDecision $d,string $action,array $extra=[]):array {$h=$d->history??[];$h[]=$extra+['action'=>$action,'user_id'=>Auth::id(),'at'=>now()->toIso8601String()];return $h;}
 private function event(EnterpriseDecision $d,string $action):void {
  if($action!=='created')app(DecisionLearningService::class)->response($d);
  app(BusinessEventService::class)->record('intelligence.decision.'.$action,$d,$d->evidence['product']['sku']??null,['severity'=>$d->severity,'decision_type'=>$d->decision_type,'confidence'=>$d->confidence,'quantity'=>$d->alternatives[0]['base_quantity']??0,'stockout_date'=>$d->evidence['forecast']['stockout_date']??null],'decision:'.$action.':'.$d->version);
 }
 public function feedback(int $id,array $input):array {
  $this->authorize(true);$v=validator($input,['action'=>'required|in:reviewed,accepted,modified,dismissed','note'=>'nullable|string|max:1000','stock_transfer_id'=>'nullable|integer|min:1'])->validate();
  return DB::transaction(function()use($id,$v){$d=EnterpriseDecision::lockForUpdate()->findOrFail($id);abort_unless(in_array($d->status,self::ACTIVE),409,'This decision has been closed or superseded.');$extra=$v;
   if(!empty($v['stock_transfer_id'])){$t=StockTransfer::with('items')->findOrFail($v['stock_transfer_id']);abort_unless($this->can('inventory.view')&&$t->items->contains('product_id',$d->product_id)&&(!$d->warehouse_id||$t->destination_warehouse_id===$d->warehouse_id)&&in_array($t->status,['in_transit','partially_received','received'])&&$t->dispatched_at?->gte($d->generated_at),422,'Select an actual transfer dispatched after this decision for the same product and destination.');$d->stock_transfer_id=$t->id;}
   $d->status=$v['action'];$d->history=$this->history($d,$v['action'],$extra);$d->save();if($v['action']!=='reviewed')$this->event($d,$v['action']==='dismissed'?'dismissed':'accepted');app(AnalyticsDataService::class)->audit('decision.feedback',['decision_id'=>$id,'feedback'=>$v]);return $this->present($d,true);
  });
 }
 public function simulate(int $id,array $input):array {
  $this->authorize();$d=EnterpriseDecision::findOrFail($id);$v=validator($input,['scenarios'=>'required|array|min:1|max:4','scenarios.*'=>'array'])->validate();$scenarios=[];foreach($v['scenarios'] as $s)$scenarios[]=array_replace($s,['warehouse_id'=>$d->warehouse_id]);
  return ['recommended'=>$this->safe(['option'=>$d->alternatives[0]??null])['option'],'comparison'=>app(InventoryPlanningService::class)->scenarios($d->product_id,['scenarios'=>$scenarios,'warehouse_id'=>$d->warehouse_id]),'read_only'=>true];
 }
 public function review(int $id,array $input,bool $recordReview=true):array {
  $this->authorize(true);abort_unless($this->can('analytics.finance'),403);$d=EnterpriseDecision::findOrFail($id);abort_unless(in_array($d->status,['open','reviewed'])&&!($d->evidence['archived']??false),409,'This decision is no longer open.');
  $v=validator($input,['alternative_key'=>'required|string|max:100','options'=>'sometimes|array'])->validate();$b=app(EnterpriseDecisionEvidence::class)->assemble($d->product_id,$d->warehouse_id);$o=collect($b['alternatives'])->firstWhere('key',$v['alternative_key']);abort_unless($o,409,'The alternative changed. Refresh and review the current alternatives.');
  $options=[];if(!empty($v['options'])){$options=app(InventoryPlanningService::class)->options($v['options']);unset($options['warehouse_id'],$options['supplier_id'],$options['type'],$options['source_warehouse_id']);$fresh=app(InventoryPlanningService::class)->decisionEvidence($d->product_id,array_replace($options,['warehouse_id'=>$d->warehouse_id,'supplier_id'=>$o['supplier_id']]));$p=$fresh['plan'];$o=array_replace($o,['base_quantity'=>$p['base_quantity'],'quantity'=>$p['quantity'],'unit'=>$p['unit'],'unit_price'=>$p['unit_price'],'base_cost'=>$p['base_cost'],'currency'=>$p['currency'],'feasible'=>$p['feasible'],'impact'=>['stockout_before'=>$p['stockout_date'],'stockout_after'=>$p['scenario_stockout_date'],'stockout_days_after'=>$p['scenario_stockout_days'],'arrival'=>$p['expected_arrival'],'days_of_supply'=>$p['post_arrival_days_of_supply'],'excess_quantity'=>$p['excess_quantity'],'projection_supported'=>(bool)$p['daily']]]);}
  abort_unless($o['base_quantity']>0&&$o['supplier_id']&&$o['unit_price']!==null&&$o['feasible'],422,'Choose a feasible, priced purchase alternative with a positive quantity.');
  $review=['decision_id'=>$id,'version'=>$d->version,'source_fingerprint'=>$b['source_fingerprint'],'alternative'=>$o,'options'=>$options,'product'=>$b['evidence']['product'],'warehouse'=>$b['evidence']['warehouse'],'confidence'=>$b['confidence'],'unknowns'=>$b['evidence']['unknowns'],'existing_requests'=>$b['evidence']['procurement']['requests'],'requires_transfer_confirmation'=>$o['transfer_quantity']>0,'human_approval_required'=>true];
  $review['review_token']=hash('sha256',json_encode($review));if($recordReview&&$d->status==='open'){$d->update(['status'=>'reviewed','history'=>$this->history($d,'reviewed')]);app(AnalyticsDataService::class)->audit('decision.reviewed',['decision_id'=>$id,'alternative_key'=>$v['alternative_key']]);}return $review;
 }
 public function draft(int $id,array $input):array {
  $this->authorize(true);abort_unless($this->can('analytics.finance'),403);$v=validator($input,['review_token'=>'required|string|size:64','alternative_key'=>'required|string|max:100','options'=>'sometimes|array','confirm_transfer_assumption'=>'sometimes|boolean'])->validate();
  return DB::transaction(function()use($id,$v){Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();$d=EnterpriseDecision::lockForUpdate()->findOrFail($id);Product::whereKey($d->product_id)->lockForUpdate()->firstOrFail();
   if($d->purchase_request_id){abort_unless(collect($d->history)->contains(fn($h)=>($h['review_token']??'')===$v['review_token']),409,'This decision already has a different reviewed draft.');return ['purchase_request'=>PurchaseRequest::findOrFail($d->purchase_request_id),'reused'=>true];}
   $review=$this->review($id,$v,false);abort_unless(hash_equals($review['review_token'],$v['review_token']),409,'Stock, pricing, forecast, MOQ, commitments or arrival evidence changed. Review again before creating a draft.');
   abort_if(PurchaseRequestItem::where('product_id',$d->product_id)->whereIn('purchase_request_id',PurchaseRequest::whereIn('status',['draft','submitted','approved','sourcing'])->select('id'))->exists(),409,'An open Purchase Request already exists for this product. Review it before creating another.');
   abort_if($review['requires_transfer_confirmation']&&!($v['confirm_transfer_assumption']??false),422,'Confirm that the separate transfer must be reviewed and authorized; a PR does not move this stock.');$o=$review['alternative'];app(UnitConversionService::class)->resolve($d->product,$o['quantity'],$o['unit']);
   $pr=app(ProcurementService::class)->createRequest(['requested_at'=>today()->toDateString(),'required_by'=>$o['impact']['arrival'],'currency'=>$o['currency'],'notes'=>'Enterprise Decision V5 '.$d->version.' — proposed supplier '.$o['supplier_name'].'. '.($review['confidence']==='limited'?'Configured threshold / limited intelligence; no guaranteed coverage. ':'Projected evidence, not guaranteed coverage. ').'Transfer remains separately authorized. Existing procurement approval required.','items'=>[['product_id'=>$d->product_id,'description'=>$d->product->name,'unit'=>$o['unit'],'quantity'=>$o['quantity'],'estimated_unit_price'=>$o['unit_price']]]]);
   $modified=$v['alternative_key']!==($d->alternatives[0]['key']??null)||!empty($v['options']);$d->update(['purchase_request_id'=>$pr->id,'status'=>$modified?'modified':'accepted','history'=>$this->history($d,'created_pr',['purchase_request_id'=>$pr->id,'supplier_id'=>$o['supplier_id'],'base_quantity'=>$o['base_quantity'],'review_token'=>$v['review_token'],'alternative_key'=>$v['alternative_key'],'options'=>$v['options']??[]])]);$this->event($d,'accepted');return ['purchase_request'=>$pr,'reused'=>false];
  });
 }
 public function collectOutcome(EnterpriseDecision $d):array {
  $poIds=$d->purchase_request_id?ProcurementAward::whereHas('requestItem',fn($q)=>$q->where('purchase_request_id',$d->purchase_request_id))->whereNotNull('purchase_order_id')->pluck('purchase_order_id'):collect();
  $receipts=GoodsReceipt::with(['items'=>fn($q)=>$q->where('product_id',$d->product_id)])->whereIn('purchase_order_id',$poIds)->where('status','posted')->where('received_at','>=',$d->generated_at)->where('received_at','<=',now())->where('created_at','<=',now())->when($d->warehouse_id,fn($q)=>$q->where('warehouse_id',$d->warehouse_id))->get();
  $lines=$receipts->flatMap->items->where('inventory_unit',$d->evidence['product']['unit']);$ordered=PurchaseOrderItem::whereIn('purchase_order_id',$poIds)->where('product_id',$d->product_id)->where('inventory_unit',$d->evidence['product']['unit'])->get();
  $end=$d->generated_at->copy()->addDays(min(30,max(7,$d->evidence['forecast']['horizon'])))->startOfDay();$obs=AnalyticsSnapshot::where('entity_id',$d->product_id)->where('entity_type',$d->warehouse_id?'inventory':'demand_observation')->where('warehouse_id',$d->warehouse_id??0)->where('facts->unit',$d->evidence['product']['unit'])->where('observed_at','<=',now())->whereDate('snapshot_date','>',$d->generated_at->toDateString())->whereDate('snapshot_date','<=',min($end->toDateString(),today()->subDay()->toDateString()))->get();
  $complete=$end->lt(today())&&$obs->count()===(int)$d->generated_at->copy()->startOfDay()->diffInDays($end)&&$obs->every(fn($o)=>isset($o->facts['available']));
  $t=$d->stock_transfer_id?StockTransfer::with('items')->find($d->stock_transfer_id):null;
  $out=['state'=>$complete&&($lines->isNotEmpty()||$t?->status==='received')?'completed_observation':'pending_or_incomplete','purchase_request_id'=>$d->purchase_request_id,'purchase_order_ids'=>$poIds->all(),'actual_ordered_quantity'=>$ordered->isEmpty()?null:round($ordered->sum('base_quantity'),3),'received_quantity'=>round($lines->sum('accepted_base_quantity'),3),'actual_receipt_dates'=>$receipts->pluck('received_at')->map(fn($v)=>$v->toDateString())->all(),'transfer_id'=>$t?->id,'transfer_status'=>$t?->status,'stockout_occurred'=>$complete?$obs->contains(fn($o)=>$o->facts['stockout']??($o->facts['available']<=0)):null,'excess_stock_occurred'=>$complete&&$d->evidence['excess_quantity']!==null?$obs->contains(fn($o)=>($o->facts['available']??0)>max($d->evidence['threshold_target'],$d->evidence['inventory']['available'])):null,'quality_issue_occurred'=>$receipts->isEmpty()?null:QualityInspection::whereIn('goods_receipt_id',$receipts->pluck('id'))->where('product_id',$d->product_id)->whereNotNull('finalized_at')->where(fn($q)=>$q->where('rejected_quantity','>',0)->orWhere('damaged_quantity','>',0))->exists(),'observation_days'=>$obs->count(),'qualification'=>'observational_only_not_causal'];
  $chosen=collect($d->history)->last(fn($h)=>isset($h['base_quantity']));$ceiling=$d->evidence['stock_ceiling']??null;
  $out['excess_stock_occurred']=$complete&&$ceiling!==null?$obs->contains(fn($o)=>($o->facts['available']??0)>$ceiling):null;
  $out+=['chosen_supplier_id'=>$chosen['supplier_id']??null,'chosen_quantity'=>$chosen['base_quantity']??null,'purchased_supplier_ids'=>\App\Models\PurchaseOrder::whereIn('id',$poIds)->pluck('supplier_id')->unique()->values()->all(),'transfer_received_quantity'=>$t?round($t->items->where('product_id',$d->product_id)->sum('received_quantity'),3):null];
  if(json_encode($d->outcome)!==json_encode($out)){$d->update(['outcome'=>$out]);app(AnalyticsDataService::class)->audit('decision.outcome',['decision_id'=>$d->id,'outcome'=>$out]);}return $out;
 }
 public static function invalidate(int $company,array $ids):void {$ids=array_values(array_unique(array_filter(array_map('intval',$ids))));if(!$ids)return;foreach($ids as $id)Cache::put('decisions-changed:'.$company.':'.$id,now()->timestamp,86400);Cache::put('decisions-dirty:'.$company,array_slice(array_values(array_unique(array_merge(Cache::get('decisions-dirty:'.$company,[]),$ids))),0,200),86400);}
 public function scheduled():int {
  $previous=Auth::user();$count=0;$deadline=microtime(true)+config('enterprise_decisions.worker_seconds');
  try{foreach(Company::orderBy('id')->cursor() as $company){if(microtime(true)>$deadline)break;$lock=Cache::lock('decision-worker:'.$company->id,60);if(!$lock->get())continue;
   try{$user=User::withoutGlobalScopes()->where('company_id',$company->id)->where('is_active',true)->whereIn('role',['admin','manager'])->first();if(!$user)continue;Auth::setUser($user);if(!$this->can('analytics.view')||!$this->can('inventory.view'))continue;
    $dirty=Cache::get('decisions-dirty:'.$company->id,[]);$cursor=(int)Cache::get('decision-cursor:'.$company->id,0);$q=Product::where('lifecycle_status','active')->orderBy('id');if($dirty)$q->whereIn('id',array_slice($dirty,0,config('enterprise_decisions.batch_limit')));else $q->where('id','>',$cursor);$products=$q->limit(config('enterprise_decisions.batch_limit'))->get();if($products->isEmpty())Cache::put('decision-cursor:'.$company->id,0,86400);
    foreach($products as $p){if(microtime(true)>$deadline)break;$key='decision-checked:'.$company->id.':'.$p->id;$complete=true;$started=now()->timestamp;
     try{if($dirty||!Cache::has($key)){
      $this->refresh($p->id);$scopeKey='decision-warehouse-cursor:'.$company->id.':'.$p->id;$scopeCursor=(int)Cache::get($scopeKey,0);
      $scopes=Warehouse::where('is_active',true)->whereHas('stock',fn($q)=>$q->where('product_id',$p->id));
      foreach((clone $scopes)->where('id','>',$scopeCursor)->orderBy('id')->limit(5)->get() as $w){if(microtime(true)>$deadline){$complete=false;break;}$this->refresh($p->id,$w->id);$scopeCursor=$w->id;Cache::put($scopeKey,$scopeCursor,86400);}
      if((clone $scopes)->where('id','>',$scopeCursor)->exists())$complete=false;
      if($complete){Cache::forget($scopeKey);foreach(EnterpriseDecision::where('product_id',$p->id)->where(fn($q)=>$q->whereNotNull('purchase_request_id')->orWhereNotNull('stock_transfer_id'))->latest()->limit(10)->get() as $d)$this->collectOutcome($d);Cache::put($key,true,21600);}else Cache::forget($key);$count++;
     }}catch(\Throwable $e){$complete=false;Log::warning('Decision refresh deferred',['company_id'=>$company->id,'product_id'=>$p->id,'error'=>$e->getMessage()]);}
     $current=Cache::get('decisions-dirty:'.$company->id,[]);
     $dirty=$complete&&(int)Cache::get('decisions-changed:'.$company->id.':'.$p->id,0)<=$started?array_values(array_diff($current,[$p->id])):array_values(array_unique(array_merge($current,[$p->id])));
     Cache::put('decisions-dirty:'.$company->id,$dirty,86400);if($complete)Cache::put('decision-cursor:'.$company->id,$p->id,86400);
    }
   }finally{$lock->release();}
  }}finally{if($previous)Auth::setUser($previous);else Auth::forgetUser();}return $count;
 }
}
