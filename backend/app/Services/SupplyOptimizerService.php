<?php
namespace App\Services;
use App\Models\{SupplyOptimizationPlan as Plan,Company,Product,Warehouse,Supplier,Category,OperationalTask,User,PurchaseRequest,ProcurementAward,PurchaseOrder,StockTransfer};
use Illuminate\Support\Facades\{Auth,DB,Cache};
use Illuminate\Support\Str;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class SupplyOptimizerService {
 public const PERMISSIONS=['analytics.view','analytics.finance','inventory.view','procurement.view','finance.view','financial_accounts.view'];
 public function authorize():void {abort_unless(Auth::user()?->company_id,403);foreach(self::PERMISSIONS as $p)abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,$p),403);}
 public function options():array {$this->authorize();return ['products'=>Product::where('lifecycle_status','active')->orderBy('name')->get(['id','name','sku','category_id']),'warehouses'=>Warehouse::where('is_active',true)->get(['id','name']),'suppliers'=>Supplier::where('is_active',true)->get(['id','name']),'categories'=>Category::get(['id','name']),'permissions'=>['prepare'=>app(PermissionService::class)->roleHasPermission(Auth::user()->role,'procurement.manage'),'transfers'=>app(PermissionService::class)->roleHasPermission(Auth::user()->role,'transfers.manage')]];}
 public function summary(array $filters=[]):array {$this->authorize();$q=Plan::query();if($filters['stale']??false)$q->where('status','STALE');return ['rows'=>$q->latest('id')->limit(30)->get(['id','version','status','scope','evidence_cutoff','created_at','error']),'qualification'=>'Plans are advisory. New purchasing commitments are not cash spent.'];}
 public function get(int $id):array {$this->authorize();$p=Plan::findOrFail($id);$r=$p->toArray();unset($r['input']);$r['context']=$p->input?array_intersect_key($p->input,array_flip(['currency','finance','v7','v8','policy','limitations'])):null;$r['outcome']=\App\Models\DecisionLearningRecord::where('kind','optimization_outcome')->where('source_type','SupplyOptimizationPlan')->where('source_id',$id)->first()?->payload;
  $r['stress_options']=['suppliers'=>collect($p->result['plans']??[])->flatMap(fn($a)=>collect($a['lines']??[])->flatMap(fn($c)=>$c['purchases']))->unique('supplier_id')->map(fn($b)=>['id'=>$b['supplier_id'],'name'=>$b['supplier_name']])->values()->all(),'shipments'=>$p->input['shipment_links']??[]];return $r;}
 public function state(int $id):array {$this->authorize();return Plan::findOrFail($id,['id','status','updated_at','error'])->toArray();}
 public function submit(array $input):array {
  $this->authorize();$v=validator($input,['horizon'=>'required|integer|in:30,60,90','product_ids'=>'nullable|array|max:40','product_ids.*'=>'integer|distinct','warehouse_ids'=>'nullable|array|max:3','warehouse_ids.*'=>'integer|distinct','supplier_ids'=>'nullable|array|max:6','supplier_ids.*'=>'integer|distinct','category_ids'=>'nullable|array|max:20','category_ids.*'=>'integer|distinct','commitment_limit'=>'nullable|numeric|min:0|max:100000000','allow_transfers'=>'boolean','protect_critical'=>'boolean','transfer_lead_days'=>'nullable|integer|min:0|max:30','parent_plan_id'=>'nullable|integer'])->validate();
  foreach(['product_ids'=>Product::class,'warehouse_ids'=>Warehouse::class,'supplier_ids'=>Supplier::class,'category_ids'=>Category::class] as $key=>$class){$v[$key]=array_values(array_unique($v[$key]??[]));sort($v[$key]);if($v[$key])abort_unless($class::whereIn('id',$v[$key])->count()===count($v[$key]),422,'Scope contains unavailable company records.');}
  if(isset($v['parent_plan_id']))Plan::findOrFail($v['parent_plan_id']);
  if(isset($v['commitment_limit']))$v['commitment_limit']=Money::normalize($v['commitment_limit']);
  return DB::transaction(function()use($v){Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();$key=hash('sha256',json_encode($v));
   if($old=Plan::where('request_key',$key)->whereIn('status',['QUEUED','RUNNING'])->first())return $this->get($old->id);
   $p=Plan::create(['version'=>(string)Str::uuid(),'status'=>'QUEUED','scope'=>$v,'request_key'=>$key,'created_by'=>Auth::id()]);
   \App\Jobs\RunSupplyOptimization::dispatch($p->id)->onConnection('database')->onQueue('supply-optimizer')->afterCommit();
   return $this->get($p->id);
  });
 }
 public function run(int $id):void {
  $p=Plan::withoutGlobalScopes()->findOrFail($id);$actor=User::withoutGlobalScopes()->find($p->created_by);if(!$actor||!$actor->is_active||$actor->company_id!=$p->company_id){$p->update(['status'=>'FAILED','error'=>'Requester is no longer available in this company.']);return;}
  $previous=Auth::user();Auth::setUser($actor);
  try{$this->authorize();if(!Plan::whereKey($id)->where('status','QUEUED')->update(['status'=>'RUNNING','updated_at'=>now()]))return;$p->refresh();
   $data=app(SupplyOptimizationData::class)->collect($p->scope);$payload=app(SupplyOptimizationCandidates::class)->build($data);
   $p->update(['input'=>$data,'evidence_cutoff'=>$data['cutoff']]);
   if(collect($payload['groups'])->contains(fn($g)=>!$g['candidates']))$solved=['solver'=>'SciPy/HiGHS','version'=>'supply-milp-v11.1','seconds'=>0,'plans'=>[['key'=>'balanced','status'=>'INFEASIBLE','candidate_ids'=>[],'message'=>'No valid bundle satisfies the declared stock ceiling or hard critical coverage. Review supplier/transfer arrivals and demand evidence; missing forecasts cannot prove no stockout.']]];
   else $solved=app(LocalSupplyOptimizer::class)->solve($payload);
   $candidates=collect($payload['groups'])->flatMap(fn($g)=>$g['candidates'])->keyBy('id');
   foreach($solved['plans'] as &$alternative){$lines=array_map(fn($cid)=>$candidates[$cid],$alternative['candidate_ids']);
    foreach($lines as &$line){$signature=fn($c)=>[array_column($c['purchases'],'supplier_id'),$c['transfers'],array_column($c['purchases'],'order_date')];$neighbors=$candidates->filter(fn($c)=>$c['group']===$line['group']&&$c['id']!==$line['id']&&$signature($c)===$signature($line));$lower=$neighbors->filter(fn($c)=>$c['purchase_quantity']<$line['purchase_quantity'])->sortByDesc('purchase_quantity')->first();$higher=$neighbors->filter(fn($c)=>$c['purchase_quantity']>$line['purchase_quantity'])->sortBy('purchase_quantity')->first();$summary=fn($c)=>$c?array_intersect_key($c,array_flip(['purchase_quantity','cost_minor','stockout_days','unmet_target','excess_mean'])):null;$line['quantity_tradeoff']=['lower'=>$summary($lower),'higher'=>$summary($higher),'qualification'=>'Local pack-valid candidate comparison. Shared commitment and donor constraints can require displacing other products; this is not a second whole-company optimum.'];}unset($line);$alternative['lines']=$lines;
    $alternative['summary']=['commitment'=>Money::add(...array_map(fn($c)=>Money::decimal($c['cost_minor']),$lines)),'currency'=>$data['currency'],'products_analyzed'=>count($data['rows']),
     'purchase_lines'=>array_sum(array_map(fn($c)=>count($c['purchases']),$lines)),'transfer_lines'=>array_sum(array_map(fn($c)=>count($c['transfers']),$lines)),
     'unresolved_scopes'=>count(array_filter($lines,fn($c)=>($c['stockout_days']??0)>0||$c['unmet_target']>.0005)),
     'unsupported_scopes'=>count(array_filter($lines,fn($c)=>!$c['coverage_supported'])),'stockout_days'=>array_sum(array_column($lines,'stockout_days')),
     'confidence'=>collect($lines)->contains('confidence','limited')?'limited':(collect($lines)->every(fn($c)=>$c['confidence']==='high')?'high':'moderate')];
    $alternative['recommended']=$alternative['key']==='balanced';$alternative['explanation']='Balanced uses the approved V10 policy; other profiles are explicit comparisons, not production policy changes. MOQ, integer packs, supplier eligibility and shared donor stock remain hard constraints.';
    $closing=$data['v7']['forecast']['currencies'][$data['currency']]['expected_closing_cash']??null;$alternative['financial_context']=['currency'=>$data['currency'],'horizon'=>$p->scope['horizon'],'baseline_expected_closing_cash'=>$closing,'if_fully_paid_in_horizon'=>$closing===null?null:Money::subtract($closing,$alternative['summary']['commitment']),'qualification'=>'Hypothetical full payment within the selected horizon, not a recorded payment schedule or proof of affordability. No automatic cash limit.'];
    $supplierTotals=[];foreach($lines as $c)foreach($c['purchases'] as $buy){$key=$buy['supplier_id'].':'.$buy['currency'];$supplierTotals[$key]??=['supplier_id'=>$buy['supplier_id'],'supplier_name'=>$buy['supplier_name'],'currency'=>$buy['currency'],'quote_total'=>'0.00','base_total'=>'0.00','products'=>[],'arrival_from'=>$buy['expected_at'],'arrival_to'=>$buy['expected_at'],'payment_terms'=>null];$supplierTotals[$key]['quote_total']=Money::add($supplierTotals[$key]['quote_total'],$buy['quote_cost']);$supplierTotals[$key]['base_total']=Money::add($supplierTotals[$key]['base_total'],$buy['base_cost']);$supplierTotals[$key]['products'][]=$c['product']['name'];$supplierTotals[$key]['arrival_from']=min($supplierTotals[$key]['arrival_from'],$buy['expected_at']);$supplierTotals[$key]['arrival_to']=max($supplierTotals[$key]['arrival_to'],$buy['expected_at']);}$alternative['supplier_summary']=array_values($supplierTotals);
    if($lines){$tests=[];foreach(['demand_plus_20'=>['demand_multiplier'=>1.2],'supplier_plus_10_days'=>['supplier_delay'=>10],'incoming_po_plus_7_days'=>['shipment_delay'=>7]] as $name=>$stress)$tests[$name]=app(SupplyOptimizationCandidates::class)->stress($data,$lines,$stress);
     $alternative['stress_tests']=$tests;$affected=array_map(fn($t)=>$t['affected_scopes'],array_values($tests));
     $alternative['resilience']=($alternative['summary']['unsupported_scopes']>0)?'UNKNOWN':(max($affected)===0?'ROBUST':($alternative['summary']['unresolved_scopes']>0?'FRAGILE':'ACCEPTABLE'));
     $alternative['resilience_basis']='ROBUST: no additional stockout-day exposure in the three named tests; ACCEPTABLE: baseline target satisfied but at least one test worsens exposure; FRAGILE: baseline target unmet and a test worsens exposure; UNKNOWN: missing forecast evidence.';
    }
   }unset($alternative);
   $status=$solved['plans'][0]['status']??'FAILED';$p->update(['status'=>$status,'result'=>$solved]);
   app(DecisionLearningService::class)->append('supply-optimized:'.$p->version,'optimization','inventory','supply_optimization',['state'=>'OPTIMIZED','plan_id'=>$p->id,'source_version'=>$p->version,'input_hash'=>hash('sha256',json_encode($data)),'policy_version'=>$data['policy']['version'],'qualification'=>'Financial results remain in the permission-protected frozen source plan.'],'SupplyOptimizationPlan',$p->id,$p->evidence_cutoff);
   $this->event($p,$status==='INFEASIBLE'?'optimizer.plan.infeasible':'optimizer.plan.generated');$this->task($p,$status==='INFEASIBLE'?'Constraint prevents critical coverage':'Review optimized purchasing plan');
   if(($solved['plans'][0]['summary']['unresolved_scopes']??0)>0)$this->event($p,'optimizer.critical_constraint');
  }catch(\Throwable $e){$p->refresh();$p->update(['status'=>'FAILED','error'=>Str::limit($e->getMessage(),1500)]);}
  finally{if($previous)Auth::setUser($previous);else Auth::forgetUser();}
 }
 public function compare(int $id):array {return $this->get($id);}
 public function simulate(int $id,array $input):array {$this->authorize();$p=Plan::findOrFail($id);return $this->submit(array_replace($p->scope,$input,['parent_plan_id'=>$id]));}
 public function explain(int $id,?int $product=null,string $view='all'):array {$p=$this->get($id);abort_unless(in_array($view,['all','not_purchasing']),422,'Select a supported decision view.');return ['plan_id'=>$id,'status'=>$p['status'],'decision_view'=>$view,'alternatives'=>array_map(fn($a)=>['key'=>$a['key'],'summary'=>$a['summary']??null,'decisions'=>array_values(array_filter($a['lines']??[],fn($l)=>(!$product||$l['product']['id']===$product)&&($view!=='not_purchasing'||empty($l['purchases']))))],$p['result']['plans']??[]),'limitations'=>$p['context']['limitations']??[]];}
 public function stress(int $id,array $input):array {
  $this->authorize();$p=Plan::findOrFail($id);abort_unless($p->input&&$p->result,422,'Wait for the optimization to finish.');
  $v=validator($input,['alternative'=>'required|string','demand_multiplier'=>'numeric|min:0.5|max:2','supplier_delay'=>'integer|min:0|max:30','supplier_id'=>'nullable|integer','shipment_delay'=>'integer|min:0|max:30','shipment_id'=>'nullable|integer','collections_delay'=>'integer|min:0|max:60'])->validate();
  $a=collect($p->result['plans'])->firstWhere('key',$v['alternative']);abort_unless($a&&($a['lines']??[]),422,'Select a feasible alternative.');
  if(isset($v['supplier_id']))abort_unless(collect($a['lines'])->flatMap(fn($l)=>$l['purchases'])->contains('supplier_id',$v['supplier_id']),422,'Select a supplier used in this alternative.');
  if(isset($v['shipment_id'])){$ship=collect($p->input['shipment_links']??[])->firstWhere('id',$v['shipment_id']);abort_unless($ship,422,'Shipment is not part of the frozen incoming evidence.');$v['incoming_order_ids']=$ship['purchase_order_ids'];}
  $out=app(SupplyOptimizationCandidates::class)->stress($p->input,$a['lines'],$v);
  $out['collections_context']=null;
  if(isset($v['collections_delay'])){
   $e=$p->input['v7']['evidence']??null;
   if($e){$changed=0;foreach($e['receivables'] as &$r)if(!empty($r['expected_date'])){$r['expected_date']=\Carbon\CarbonImmutable::parse($r['expected_date'])->addDays($v['collections_delay'])->toDateString();$r['evidence_type']='scenario';$changed++;}unset($r);
    $out['collections_context']=['delay_days'=>$v['collections_delay'],'changed_recorded_obligations'=>$changed,'baseline'=>$p->input['v7']['forecast'],'scenario'=>app(FinancialForecastMath::class)->calculate($e,$p->scope['horizon']),'qualification'=>'Only frozen dated collections shifted. No unrecorded revenue or cash is invented; this is context, not a new hard purchasing budget.'];
   }else $out['collections_context']=['state'=>'insufficient_evidence','qualification'=>'No frozen V7 evidence; collections-delay cash consequences cannot be calculated.'];
  }
  return $out;
 }
 public function prepare(int $id,array $input):array {
  $this->authorize();abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'procurement.manage'),403);
  $v=validator($input,['alternative'=>'required|string','confirm'=>'required|accepted'])->validate();
  return DB::transaction(function()use($id,$v){Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();$p=Plan::lockForUpdate()->findOrFail($id);
   if($p->drafts){abort_unless($p->approved['alternative']===$v['alternative'],409,'This plan was already prepared using another alternative.');return $this->get($id);}
   abort_unless(in_array($p->status,['OPTIMAL','FEASIBLE']),409,'This plan is not current and feasible; re-optimize before preparing drafts.');
   $a=collect($p->result['plans'])->firstWhere('key',$v['alternative']);abort_unless($a&&in_array($a['status'],['OPTIMAL','FEASIBLE']),422,'Select a feasible alternative.');
   abort_unless(collect($a['lines'])->contains(fn($c)=>count($c['purchases'])+count($c['transfers'])>0),422,'This alternative has no purchase or transfer action to prepare.');
   if(collect($a['lines'])->contains(fn($c)=>count($c['transfers'])>0))abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'transfers.manage'),403);
   Product::whereIn('id',array_column(array_column($a['lines'],'product'),'id'))->orderBy('id')->lockForUpdate()->get();
   $live=app(SupplyOptimizationData::class)->collect($p->scope);
   $valid=$live['policy']['version']===$p->input['policy']['version']&&$live['resources']===$p->input['resources']&&count($live['rows'])===count($p->input['rows']);
   foreach($p->input['rows'] as $n=>$r)$valid=$valid&&isset($live['rows'][$n])&&$live['rows'][$n]['fingerprint']===$r['fingerprint'];
   if(!$valid)throw ValidationException::withMessages(['plan'=>['Stock, incoming, supplier, price, forecast or sourcing changed. Re-optimize; no draft was created.']]);
   $purchaseGroups=[];$transferGroups=[];
   foreach($a['lines'] as $c){foreach($c['purchases'] as $buy){$k=$buy['supplier_id'].':'.$buy['currency'].':'.$buy['order_date'];$purchaseGroups[$k]['supplier']=$buy['supplier_name'];$purchaseGroups[$k]['currency']=$buy['currency'];$purchaseGroups[$k]['arrival']=min($purchaseGroups[$k]['arrival']??$buy['expected_at'],$buy['expected_at']);$purchaseGroups[$k]['order_date']=$buy['order_date'];$purchaseGroups[$k]['items'][]=['product_id'=>$c['product']['id'],'description'=>$c['product']['name'].' — destination: '.($buy['warehouse_name']??'company stock').'; suggested supplier: '.$buy['supplier_name'],'unit'=>$buy['unit'],'quantity'=>$buy['quantity'],'estimated_unit_price'=>$buy['unit_price']];}
    foreach($c['transfers'] as $t){$k=$t['source_warehouse_id'].':'.$t['destination_warehouse_id'];$transferGroups[$k]['source_warehouse_id']=$t['source_warehouse_id'];$transferGroups[$k]['destination_warehouse_id']=$t['destination_warehouse_id'];$transferGroups[$k]['items'][]=['product_id'=>$c['product']['id'],'quantity'=>$t['quantity']];}
   }
   $drafts=['purchase_requests'=>[],'transfers'=>[]];
   foreach($purchaseGroups as $g){$pr=app(ProcurementService::class)->createRequest(['currency'=>$g['currency'],'requested_at'=>$g['order_date'],'required_by'=>$g['arrival'],'notes'=>'Supply optimizer #'.$p->id.' — '.$v['alternative'].'; suggested supplier '.$g['supplier'].'. Destination instructions are advisory; review sourcing and warehouse allocation.','items'=>$g['items']]);$drafts['purchase_requests'][]=['id'=>$pr->id,'reference'=>$pr->request_number,'supplier'=>$g['supplier'],'total'=>$pr->estimated_total,'currency'=>$pr->currency,'url'=>'/procurement?request='.$pr->id];}
   foreach($transferGroups as $g){$tr=app(WarehouseOperationsService::class)->createTransfer($g+['notes'=>'Supply optimizer #'.$p->id.' — human reviewed draft; dispatch remains separately controlled.']);$drafts['transfers'][]=['id'=>$tr->id,'reference'=>$tr->transfer_number,'url'=>'/warehouse-operations?tab=transfers&transfer='.$tr->id];}
   $approved=['alternative'=>$v['alternative'],'actor_id'=>Auth::id(),'at'=>now()->toIso8601String(),'summary'=>$a['summary'],'lines'=>$a['lines']];$p->update(['approved'=>$approved,'drafts'=>$drafts]);
   app(DecisionLearningService::class)->append('supply-approved:'.$p->version,'optimization_approved','inventory','supply_optimization',['state'=>'APPROVED','plan_id'=>$p->id,'source_version'=>$p->version,'alternative'=>$v['alternative'],'actor_id'=>Auth::id(),'purchase_request_ids'=>array_column($drafts['purchase_requests'],'id'),'transfer_ids'=>array_column($drafts['transfers'],'id')],'SupplyOptimizationPlan',$p->id,$p->evidence_cutoff);
   $this->event($p,'optimizer.plan.approved');$this->task($p,'Approved plan requires PR / transfer review');
   app(AnalyticsDataService::class)->audit('optimizer.plan.drafts_prepared',['plan_id'=>$p->id,'drafts'=>$drafts]);return $this->get($id);
  });
 }
 public function maintain():int {
  $this->authorize();$changed=0;
  $company=Auth::user()->company_id;$cursorKey='optimizer-maintain-cursor:'.$company;$cycleKey='optimizer-maintain-dirty:'.$company;$dirtyKey='optimizer-dirty:'.$company;
  $cursor=(int)Cache::get($cursorKey,0);if($cursor===0)Cache::put($cycleKey,Cache::get($dirtyKey,false),86400);$cycleDirty=Cache::get($cycleKey,false);
  $query=fn()=>Plan::whereIn('status',['OPTIMAL','FEASIBLE'])->whereNotNull('input');$batch=$query()->where('id','>',$cursor)->orderBy('id')->limit(10)->get();
  foreach($batch as $p){
   if(!$p->drafts&&(Cache::get('optimizer-dirty:'.$p->company_id)||$p->evidence_cutoff->lt(now()->subHours(24)))){
    try{$live=app(SupplyOptimizationData::class)->collect($p->scope);$stale=$p->evidence_cutoff->lt(now()->subHours(24))||app(SupplyOptimizationData::class)->material($p->input,$live);}catch(\Throwable){$stale=true;}
    if($stale){$p->update(['status'=>'STALE']);$this->event($p,'optimizer.plan.stale');$this->task($p,'Supply plan became stale — re-optimize');$changed++;}
   }
   if($p->drafts){$ids=array_column($p->drafts['purchase_requests'],'id');$rfqs=$ids?\App\Models\Rfq::whereIn('purchase_request_id',$ids)->pluck('id'):collect();$awards=ProcurementAward::whereIn('rfq_id',$rfqs)->get();
    $orders=PurchaseOrder::whereIn('id',$awards->pluck('purchase_order_id')->unique())->whereNotIn('status',['draft','cancelled'])->get();
    $transfers=StockTransfer::whereIn('id',array_column($p->drafts['transfers'],'id'))->whereIn('status',['dispatched','partially_received','received'])->get();
    $execution=['purchase_orders'=>$orders->map(fn($o)=>['id'=>$o->id,'reference'=>$o->po_number,'status'=>$o->status,'total'=>$o->total_amount,'currency'=>$o->currency,'url'=>'/purchase-orders?po='.$o->id])->all(),'transfers'=>$transfers->map(fn($t)=>['id'=>$t->id,'status'=>$t->status])->all(),'qualification'=>'Actual authoritative PO/transfer progress only; drafts and approvals are not execution.'];
    if(($orders->isNotEmpty()||$transfers->isNotEmpty())&&$execution!==$p->execution){$p->update(['execution'=>$execution]);$hash=hash('sha256',json_encode($execution));app(DecisionLearningService::class)->append('supply-execution:'.$p->version.':'.$hash,'optimization_execution','inventory','supply_optimization',['state'=>'EXECUTION_OBSERVED','plan_id'=>$p->id,'source_version'=>$p->version,'purchase_order_ids'=>$orders->pluck('id')->all(),'transfer_ids'=>$transfers->pluck('id')->all()],'SupplyOptimizationPlan',$p->id,now());$this->event($p,'optimizer.plan.executed',$hash);}
    $outcome=app(SupplyOptimizationOutcome::class)->capture($p);if($outcome?->wasRecentlyCreated)$p->touch();
   }
   $cursor=$p->id;Cache::put($cursorKey,$cursor,86400);
  }
  if($batch->isEmpty()||!$query()->where('id','>',$cursor)->exists()){
   Cache::forget($cursorKey);Cache::forget($cycleKey);
   if(Cache::get($dirtyKey,false)===$cycleDirty)Cache::forget($dirtyKey);
  }
  return $changed;
 }
 private function event(Plan $p,string $event,string $suffix=''):void {app(BusinessEventService::class)->record($event,$p,'Supply plan #'.$p->id,['status'=>$p->status,'plan_id'=>$p->id],$event.':'.$p->version.':'.$suffix);}
 private function task(Plan $p,string $title):void {OperationalTask::updateOrCreate(['dedupe_key'=>'supply-optimizer:'.$p->version],['title'=>$title,'description'=>'Review frozen evidence and unresolved constraints in Supply Optimizer. No autonomous purchasing.','source_type'=>'SupplyOptimizationPlan','source_id'=>$p->id,'status'=>'open','priority'=>in_array($p->status,['STALE','INFEASIBLE'])?'high':'normal','assigned_role'=>'admin','created_by'=>Auth::id()]);}
}
