<?php
namespace App\Services;

use App\Models\{DecisionLearningRecord as Record,EnterpriseDecision,AnalyticsSnapshot,AnalyticsPrediction,ShipmentIntelligence,FinancialIntelligenceSnapshot,CustomerSalesPrediction,Company,User,Product,PurchaseOrder,ProcurementAward,GoodsReceipt,OperationalTask};
use Illuminate\Support\Facades\{Auth,DB,Schema,Cache,Log};
use Illuminate\Support\Str;
use Carbon\CarbonImmutable as Date;
use App\Support\Money;

/** V10 is a bounded append-only read model over existing intelligence and operational sources. */
final class DecisionLearningService {
 public const DOMAINS=['inventory','supplier','shipment','finance','customer'];
 public function authorize(bool $manage=false):void {
  abort_unless(Auth::user()?->company_id&&$this->can('analytics.view'),403);
  if($manage)abort_unless(Auth::user()->role==='admin'&&$this->can('analytics.ml_datasets')&&$this->can('analytics.finance')&&$this->can('inventory.view'),403,'Policy experiments and promotion require an authorized company administrator.');
 }
 private function can(string $p):bool {return app(PermissionService::class)->roleHasPermission(Auth::user()->role,$p);}
 public function allowedDomains():array {
  return array_values(array_filter(self::DOMAINS,fn($d)=>match($d){'inventory'=>$this->can('inventory.view'),'supplier'=>$this->can('inventory.view')&&$this->can('supplier_performance.view'),'shipment'=>$this->can('shipments.view'),'finance'=>$this->can('analytics.finance')&&$this->can('finance.view')&&$this->can('financial_accounts.view'),'customer'=>collect(CustomerSalesIntelligenceService::PERMISSIONS)->every(fn($p)=>$this->can($p))}));
 }
 public function append(string $key,string $kind,string $domain,string $type,array $payload,?string $source=null,?int $id=null,$cutoff=null):Record {
  return Record::createOrFirst(['company_id'=>Auth::user()->company_id,'record_key'=>hash('sha256',$key)],
   ['kind'=>$kind,'domain'=>$domain,'decision_type'=>$type,'source_type'=>$source,'source_id'=>$id,'version'=>(string)Str::uuid(),'evidence_cutoff'=>$cutoff,'payload'=>$payload,'created_by'=>Auth::id()]);
 }
 public function currentPolicy():array {
  $baseline=['id'=>null,'version'=>config('enterprise_decisions.version'),'quantity_multiplier'=>1,'weights'=>config('enterprise_decisions.weights'),'state'=>'PRODUCTION','scope'=>'company'];
  if(!Schema::hasTable('decision_learning_records')||!Auth::user()?->company_id)return $baseline;
  $change=Record::where('kind','policy_change')->whereNull('payload->archived')->latest('id')->first();
  $policy=$change?Record::where('kind','policy')->find($change->payload['to_policy_id']):null;
  return $policy?['id'=>$policy->id,'version'=>$policy->version,'state'=>'PRODUCTION','scope'=>'company']+$policy->payload['settings']:$baseline;
 }
 public function capture(EnterpriseDecision $d,array $input=[],array $context=[]):?Record {
  if(!Schema::hasTable('decision_learning_records')||($d->evidence['archived']??false))return null;
  $predictionId=$d->evidence['sources']['forecast_prediction_id']??null;
  $modelVersion=$predictionId?AnalyticsPrediction::whereKey($predictionId)->value('model_version'):null;
  $r=$this->append('recommendation:'.$d->version,'recommendation',$d->decision_type==='SUPPLIER_SELECTION'?'supplier':'inventory',$d->decision_type,
   ['origin'=>$input?'point_in_time':'historical_snapshot','product_id'=>$d->product_id,'warehouse_id'=>$d->warehouse_id,'source_version'=>$d->version,'policy_version'=>$d->reasoning['policy_version'],
    'model_versions'=>$d->evidence['versions'],'model_version'=>$modelVersion,'recommended'=>$d->alternatives[0]??null,'alternatives'=>$d->alternatives,'confidence'=>$d->confidence,'assumptions'=>$d->evidence['unknowns'],
    'evidence'=>$d->evidence,'input'=>$input,'ranking_context'=>$context,'window_end'=>$d->generated_at->copy()->addDays(min(30,max(7,$d->evidence['forecast']['horizon'])))->toDateString(),
    'qualification'=>config('decision_learning.qualification')],'EnterpriseDecision',$d->id,$d->evidence_cutoff_at);
  if($r->wasRecentlyCreated&&$input&&$d->decision_type==='REPLENISHMENT_DECISION')$this->freezeShadows($r);
  return $r;
 }
 public function response(EnterpriseDecision $d):void {
  $r=$this->capture($d);if(!$r)return;
  $payload=app(DecisionLearningMath::class)->response($d->history,$d->status,$r->payload['recommended']??[]);
  $payload+=['recommendation_id'=>$r->id,'purchase_request_id'=>$d->purchase_request_id,'stock_transfer_id'=>$d->stock_transfer_id];
  $this->append('response:'.$d->version.':'.hash('sha256',json_encode($payload)),'response',$r->domain,$r->decision_type,$payload,'EnterpriseDecision',$d->id,$d->evidence_cutoff_at);
 }
 private function freezeShadows(Record $r):void {
  $policy=$this->currentPolicy();
  foreach(Record::where('kind','experiment')->whereNull('payload->archived')->where('created_at','<=',$r->evidence_cutoff)->latest('id')->limit(3)->get() as $experiment){
   if($experiment->payload['champion']['version']!==$policy['version']||$this->experimentClosed($experiment))continue;
   $challenger=$experiment->payload['challenger'];$options=[];$math=app(InventoryPlanningMath::class);
   foreach($r->payload['alternatives'] as $o){
    if(!in_array($o['action'],['purchase','monitor'])||!$o['feasible'])continue;
    if($o['action']==='purchase'){
     $i=$r->payload['input'];$s=collect($i['suppliers'])->firstWhere('supplier_id',$o['supplier_id']);if(!$s)continue;
     $i['supplier']=$s;$i['lead']=[]; // A changed supplier cannot inherit another supplier's lead evidence.
     $step=(float)$o['constraints']['step'];$q=$o['base_quantity']*$challenger['quantity_multiplier']/max(.001,$policy['quantity_multiplier']);
     $q=ceil(max($q,(float)$o['constraints']['moq'])/max(.001,$step))*$step;
     $result=$math->calculate($i,['base_quantity'=>$q,'order_date'=>$r->evidence_cutoff->toDateString()]);
     $o['base_quantity']=$result['base_quantity'];$o['quantity']=$result['quantity'];$o['base_cost']=$result['base_cost'];$o['feasible']=$result['feasible'];
     $o['impact']['stockout_days_after']=$result['scenario_stockout_days'];$o['impact']['arrival']=$result['expected_arrival'];
     $o['impact']['excess_quantity']=$result['timeline']?max(0,last($result['timeline'])['with_order']-$result['safety_stock']):null;
    }
    $options[]=$o;
   }
   $ranked=app(EnterpriseDecisionRanker::class)->rank($options,$r->payload['ranking_context'],$challenger['weights'],$challenger['version']);
   $this->append('shadow:'.$experiment->version.':'.$r->version,'shadow','inventory',$r->decision_type,
    ['experiment_id'=>$experiment->id,'recommendation_id'=>$r->id,'product_id'=>$r->payload['product_id'],'champion'=>$r->payload['recommended'],'challenger'=>$ranked[0]??null,
     'champion_version'=>$policy['version'],'challenger_version'=>$challenger['version'],'input_hash'=>hash('sha256',json_encode($r->payload['input'])),
     'alternatives'=>$ranked,'state'=>'EXPERIMENTAL','created_for_future_outcome'=>true,'qualification'=>'Frozen at the original decision cutoff. No outcome evidence was used.'],'EnterpriseDecision',$r->source_id,$r->evidence_cutoff);
  }
 }
 private function experimentClosed(Record $r):bool {return Record::where('kind','policy_change')->where('payload->experiment_id',$r->id)->exists();}
 public function createExperiment(array $input):array {
  $this->authorize(true);$v=validator($input,['quantity_multiplier'=>'required|numeric|min:0.8|max:1.2','reason'=>'required|string|min:5|max:1000','weights'=>'nullable|array','weights.*'=>'numeric|min:0|max:60'])->validate();
  return DB::transaction(function()use($v){Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();$current=$this->currentPolicy();
   $weights=$v['weights']??$current['weights'];abort_unless(array_keys($weights)===array_keys(config('enterprise_decisions.weights'))&&$weights['stockout']>=20&&$weights['availability']>=10&&$weights['cost']<=30,422,'Keep stockout and availability guardrails; use the existing seven bounded criteria.');
   abort_unless($v['quantity_multiplier']!=$current['quantity_multiplier']||$weights!==$current['weights'],422,'The challenger must differ from the champion.');
   $fingerprint=hash('sha256',json_encode([$current['version'],(float)$v['quantity_multiplier'],$weights]));
   if($old=Record::where('kind','experiment')->where('payload->configuration_hash',$fingerprint)->first())return $this->comparison($old->id);
   $champion=$current;if(!$current['id']){
    $p=$this->append('baseline-policy:'.$current['version'],'policy','inventory','decision_policy',['settings'=>['quantity_multiplier'=>1,'weights'=>$current['weights']],'reason'=>'Existing V5 production baseline']);$champion['id']=$p->id;
   }
   $p=$this->append('challenger-policy:'.$fingerprint,'policy','inventory','decision_policy',['settings'=>['quantity_multiplier'=>(float)$v['quantity_multiplier'],'weights'=>$weights],'reason'=>$v['reason'],'parent_version'=>$current['version']]);
   $e=$this->append('experiment:'.$fingerprint,'experiment','inventory','decision_policy',[
    'champion'=>$champion,'challenger'=>['id'=>$p->id,'version'=>$p->version,'quantity_multiplier'=>(float)$v['quantity_multiplier'],'weights'=>$weights],
    'configuration_hash'=>$fingerprint,'start_at'=>now()->toIso8601String(),'eligible_population'=>'Future replenishment decisions in this company; no historical shadow reconstruction',
    'evaluation_metrics'=>['stockout_days','excess_mean_quantity','purchase_cost'],'reason'=>$v['reason'],'state'=>'SHADOW','assumptions'=>['sampled stock availability','same observed supplier/receipt timing for paired quantity scenarios','no causal attribution','category/product specialization disabled until sufficient independent evidence']]);
   app(AnalyticsDataService::class)->audit('intelligence.learning.experiment_started',['experiment_id'=>$e->id,'champion'=>$champion['version'],'challenger'=>$p->version]);
   return $this->comparison($e->id);
  });
 }
 private function genuineStockObservations($observations,int $productId,?int $warehouseId,string $unit):bool {
  $stocks=$warehouseId?collect():AnalyticsSnapshot::where('entity_type','product')->where('entity_id',$productId)->where('warehouse_id',0)
   ->whereIn('id',$observations->map(fn($s)=>$s->facts['stock_snapshot_id']??$s->facts['provenance']['stock_snapshot_id']??null)->filter())->get()->keyBy('id');
  return $observations->every(function($s)use($stocks,$warehouseId,$unit){
   if(!isset($s->facts['available'])||($s->facts['unit']??null)!==$unit)return false;
   $stock=$warehouseId?$s:$stocks->get($s->facts['stock_snapshot_id']??$s->facts['provenance']['stock_snapshot_id']??null);
   return $stock&&($stock->facts['unit']??null)===$unit&&$stock->snapshot_date->toDateString()===$s->snapshot_date->toDateString()&&$stock->observed_at->toDateString()===$stock->snapshot_date->toDateString();
  });
 }
 public function evaluateDecision(EnterpriseDecision $d):?Record {
  $d=$d->fresh();
  $r=$this->capture($d);if(!$r)return null;$this->response($d);
  if($done=Record::where('kind','outcome')->where('source_type','EnterpriseDecision')->where('source_id',$d->id)->first())return $done;
  $end=$r->payload['window_end'];if($end>=today()->toDateString())return null;
  $from=$d->generated_at->copy()->addDay()->toDateString();
  $obs=AnalyticsSnapshot::where('entity_type',$d->warehouse_id?'inventory':'demand_observation')->where('entity_id',$d->product_id)->where('warehouse_id',$d->warehouse_id??0)
   ->whereDate('snapshot_date','>=',$from)->whereDate('snapshot_date','<=',$end)->where('observed_at','>',$d->evidence_cutoff_at)->orderBy('snapshot_date')->get();
  $expected=(int)Date::parse($from)->diffInDays(Date::parse($end))+1;
  if($obs->count()!==$expected||!$this->genuineStockObservations($obs,$d->product_id,$d->warehouse_id,$d->evidence['product']['unit']))return null;
  $days=$obs->map(fn($s)=>['date'=>$s->snapshot_date->toDateString(),'observation_id'=>$s->id]+$s->facts)->all();
  $chain=app(EnterpriseDecisionService::class)->collectOutcome($d);$response=app(DecisionLearningMath::class)->response($d->history,$d->status,$r->payload['recommended']??[]);
  $score=app(DecisionLearningMath::class)->scorecard($days,$d->evidence['stock_ceiling']??null,$d->evidence['safety_stock']??null);
  $supplierEvidence=[];foreach(PurchaseOrder::whereIn('id',$chain['purchase_order_ids'])->get() as $po)$supplierEvidence[]=app(SupplierHistoryService::class)->order($po);
  $receiptIds=collect($supplierEvidence)->flatMap(fn($s)=>array_column($s['receipts'],'id'))->unique()->values()->all();
  $chain['receipt_ids']=$receiptIds;
  $costLines=\App\Models\GoodsReceiptItem::whereIn('goods_receipt_id',$receiptIds)->where('product_id',$d->product_id)->where('inventory_unit',$d->evidence['product']['unit'])->get();
  if($costLines->isNotEmpty()&&$costLines->every(fn($l)=>$l->base_purchase_unit_cost!==null))$score['purchase_cost']=Money::add(...$costLines->map(fn($l)=>Money::multiply($l->accepted_base_quantity,$l->base_purchase_unit_cost))->all());
  $score['supplier_delay_days']=$supplierEvidence?collect($supplierEvidence)->avg('late_days'):null;
  $forecastId=$d->evidence['sources']['forecast_prediction_id']??null;$prediction=$forecastId?AnalyticsPrediction::find($forecastId):null;
  $out=$this->append('outcome:'.$d->version,'outcome',$r->domain,$r->decision_type,
   ['recommendation_id'=>$r->id,'policy_version'=>$r->payload['policy_version'],'regime_key'=>$this->regime($r),'state'=>'COMPLETED_OBSERVATION','window'=>['from'=>$from,'to'=>$end],'product_id'=>$d->product_id,'warehouse_id'=>$d->warehouse_id,
    'response'=>$response,'chain'=>$chain,'days'=>$days,'scorecard'=>$score,'supplier_evidence'=>$supplierEvidence,
    'prediction_quality'=>$prediction?->evaluation,'decision_quality'=>['stockout_issue'=>$score['stockout_days']>0,'excess_issue'=>$score['excess_quantity']===null?null:$score['excess_quantity']>0,'causal_claim'=>false],
    'financial_context_at_recommendation'=>$d->evidence['financial_intelligence']??null,'next_replenishment_ids'=>GoodsReceipt::where('status','posted')->whereHas('items',fn($q)=>$q->where('product_id',$d->product_id))->whereBetween('received_at',[$from,$end.' 23:59:59'])->whereNotIn('id',$receiptIds)->pluck('id')->all(),
    'qualification'=>config('decision_learning.qualification')],'EnterpriseDecision',$d->id,$d->evidence_cutoff_at);
  if($out->wasRecentlyCreated){$this->signal('outcome_completed',$out,['state'=>'COMPLETED_OBSERVATION'],false);$this->scoreShadows($r,$out);}
  return $out;
 }
 private function scoreShadows(Record $r,Record $out):void {
  foreach(Record::where('kind','shadow')->where('payload->recommendation_id',$r->id)->get() as $shadow){
   $p=$shadow->payload;$a=$p['champion'];$b=$p['challenger'];$actual=$out->payload['chain'];$supplier=$out->payload['response']['chosen_supplier_id'];
   // No inferred lead-time benefit for a different supplier or an unacted recommendation.
   if(!$a||!$b||$a['action']!=='purchase'||$b['action']!=='purchase'||!$supplier||$supplier!==$a['supplier_id']||$supplier!==$b['supplier_id']||count($actual['purchased_supplier_ids'])!==1||$supplier!==$actual['purchased_supplier_ids'][0]||count($actual['purchase_order_ids'])!==1||count($actual['actual_receipt_dates'])!==1||!$actual['actual_ordered_quantity']||$actual['received_quantity']<$actual['actual_ordered_quantity']-.0005)continue;
   $arrival=$actual['actual_receipt_dates'][0];if($arrival>$out->payload['window']['to'])continue;
   $scores=[];foreach(['champion'=>$a,'challenger'=>$b] as $key=>$option){
    $days=$out->payload['days'];foreach($days as &$day)if($day['date']>=$arrival){$day['available']+=$option['base_quantity']-$actual['received_quantity'];$day['stockout']=$day['available']<=0;}unset($day);
    $scores[$key]=app(DecisionLearningMath::class)->scorecard($days,$r->payload['evidence']['stock_ceiling']??null,$r->payload['evidence']['safety_stock']??null);$scores[$key]['purchase_cost']=$option['base_cost'];
   }
   $this->append('comparison:'.$shadow->version,'comparison','inventory',$r->decision_type,$scores+[
    'experiment_id'=>$p['experiment_id'],'recommendation_id'=>$r->id,'product_id'=>$r->payload['product_id'],'warehouse_id'=>$r->payload['warehouse_id'],
    'window'=>$out->payload['window'],'observed_outcome_id'=>$out->id,'actual_received_quantity'=>$actual['received_quantity'],'arrival'=>$arrival,
    'evidence_kind'=>'estimated_scenario','assumptions'=>['same observed receipt timing','other real movements unchanged','sampled stock; hidden unmet demand remains unknown'],
    'regime_key'=>$this->regime($r),'qualification'=>config('decision_learning.qualification')],'EnterpriseDecision',$r->source_id,$r->evidence_cutoff);
  }
 }
 private function regime(Record $r):string {
  $e=$r->payload['evidence'];return hash('sha256',json_encode([$e['product']['unit'],$e['stock_ceiling']??null,$e['versions']['planning'],$e['versions']['policy'],array_map(fn($s)=>[$s['supplier_id'],$s['unit_price']??$s['purchase_price']??null,$s['lead_days']??null],$e['supplier_options']),$e['warehouse']['id']??null]));
 }
 public function comparison(int $id):array {
  $this->authorize();$e=Record::where('kind','experiment')->whereNull('payload->archived')->findOrFail($id);abort_unless(in_array('inventory',$this->allowedDomains()),403);
  $pairs=Record::where('kind','comparison')->where('payload->experiment_id',$id)->where('evidence_cutoff','>=',now()->subDays(config('decision_learning.evidence_days')))->latest('id')->limit(2000)->get()->sortBy(fn($r)=>$r->payload['window']['from'])->values();
  $end=[];$regimes=[];$independent=[];
  foreach($pairs as $r){$p=$r->payload;$key=$p['product_id'].':'.($p['warehouse_id']??0);$regimes[$key]=$p['regime_key'];}
  foreach($pairs as $r){$p=$r->payload;$key=$p['product_id'].':'.($p['warehouse_id']??0);if($p['regime_key']!==$regimes[$key]||isset($end[$key])&&$p['window']['from']<=$end[$key])continue;$end[$key]=$p['window']['to'];$independent[]=$p;}
  $metrics=app(DecisionLearningMath::class)->compare($independent);$production=$this->currentPolicy();
  if($production['version']!==$e->payload['champion']['version']||$this->experimentClosed($e)){$metrics['eligible_for_human_review']=false;$metrics['state']='CLOSED_OR_CHAMPION_CHANGED';}
  if(!$this->can('analytics.finance'))unset($metrics['dimensions']['purchase_cost']);
  return ['id'=>$e->id,'version'=>$e->version,'experiment'=>$e->payload,'comparison'=>$metrics,'shadow_decisions'=>Record::where('kind','shadow')->where('payload->experiment_id',$id)->count(),'closed'=>$this->experimentClosed($e),'current_champion'=>$production];
 }
 public function changePolicy(int $id,string $action,array $input):array {
  $this->authorize(true);$v=validator($input,['confirmed'=>'required|accepted','reason'=>'required|string|min:5|max:1000','expected_champion'=>'required|string|max:60'])->validate();
  return DB::transaction(function()use($id,$action,$v){Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();$e=Record::where('kind','experiment')->findOrFail($id);$current=$this->currentPolicy();
   abort_unless($current['version']===$v['expected_champion'],409,'Champion changed. Review the current evidence again.');
   $comparison=$this->comparison($id);
   if($action==='promote'){
    abort_unless($comparison['comparison']['eligible_for_human_review'],422,'Challenger is not eligible: collect enough independent, comparable real outcomes without service degradation.');
    $target=$e->payload['challenger']['id'];$previous=$e->payload['champion']['id'];
   }else{
    $last=Record::where('kind','policy_change')->latest('id')->first();
    abort_unless($last&&$last->payload['action']==='promote'&&$last->payload['experiment_id']===$id&&$last->payload['to_policy_id']===$current['id'],422,'Rollback is limited to the previous champion of the latest promotion.');
    $target=$last->payload['from_policy_id'];$previous=$current['id'];
   }
   $record=$this->append('policy-change:'.Str::uuid(),'policy_change','inventory','decision_policy',[
    'action'=>$action,'experiment_id'=>$id,'from_policy_id'=>$previous,'to_policy_id'=>$target,'reason'=>$v['reason'],'approver_id'=>Auth::id(),'approved_at'=>now()->toIso8601String(),'comparison'=>$comparison['comparison']]);
   $this->signal($action==='promote'?'champion_promoted':'champion_rolled_back',$record,['state'=>strtoupper($action)],false);
   app(AnalyticsDataService::class)->audit('intelligence.learning.'.$action,$record->payload);
   foreach(Product::where('lifecycle_status','active')->limit(200)->pluck('id') as $p)EnterpriseDecisionService::invalidate(Auth::user()->company_id,[$p]);
   return ['champion'=>$this->currentPolicy(),'decision'=>$record->payload];
  });
 }
 private function signal(string $event,Record $source,array $meta,bool $task):void {
  app(BusinessEventService::class)->record('intelligence.learning.'.$event,$source,'Decision Learning',$meta,'learning:'.$event.':'.$source->version);
  if($task)OperationalTask::firstOrCreate(['company_id'=>$source->company_id,'dedupe_key'=>'learning:'.$event.':'.$source->id],
   ['title'=>match($event){'challenger_ready'=>'Review eligible decision-policy challenger','performance_degraded'=>'Review observed recommendation issues',default=>'Review repeated quantity overrides'},'description'=>'Open Decision Learning to review evidence. No policy or business action has been applied.','source_type'=>'DecisionLearningRecord','source_id'=>$source->id,'status'=>'open','priority'=>'normal','assigned_role'=>'admin','created_by'=>Auth::id()]);
 }
 private function filters(array $filters):array {
  $v=validator($filters,['domain'=>'nullable|in:inventory,supplier,shipment,finance,customer','type'=>'nullable|string|max:48','from'=>'nullable|date','to'=>'nullable|date','policy_version'=>'nullable|string|max:60','model_version'=>'nullable|string|max:60','page'=>'nullable|integer|min:1'])->validate();
  abort_if(!empty($v['from'])&&!empty($v['to'])&&$v['to']<$v['from'],422,'The end date must not precede the start date.');return $v;
 }
 private function filtered($q,array $v){
  $q->when($v['type']??null,fn($q,$x)=>$q->where('decision_type',$x))->when($v['from']??null,fn($q,$x)=>$q->whereDate('evidence_cutoff','>=',$x))->when($v['to']??null,fn($q,$x)=>$q->whereDate('evidence_cutoff','<=',$x));
  $versions=array_filter(['policy_version'=>$v['policy_version']??null,'model_version'=>$v['model_version']??null]);
  if($versions){$parents=Record::select('id')->whereIn('kind',['recommendation','prediction']);foreach($versions as $k=>$value)$parents->where('payload->'.$k,$value);
   $q->where(function($q)use($versions,$parents){$q->where(function($q)use($versions){foreach($versions as $k=>$value)$q->where('payload->'.$k,$value);})->orWhereIn('payload->recommendation_id',$parents);});}
  return $q;
 }
 private function presentedRows(array $rows):array {
  $ids=collect($rows)->where('kind','recommendation')->pluck('id');
  $linked=$ids->isEmpty()?collect():Record::whereIn('kind',['response','outcome'])->whereIn('payload->recommendation_id',$ids)->latest('id')->get()->groupBy(fn($r)=>$r->payload['recommendation_id']);
  return array_map(function($r)use($linked){$view=$this->present($r);if($r->kind==='recommendation'){
   $records=$linked->get($r->id,collect());$response=$records->firstWhere('kind','response');$outcome=$records->firstWhere('kind','outcome');
   if($response)$view['data']['response']=$this->present($response)['data'];
   if($outcome){$p=$this->present($outcome)['data'];foreach(['chain','scorecard','state'] as $k)$view['data'][$k]=$p[$k]??null;}
  }return $view;},$rows);
 }
 public function summary(array $filters=[]):array {
  $this->authorize();$v=$this->filters($filters);
  $allowed=$this->allowedDomains();if(!empty($v['domain']))abort_unless(in_array($v['domain'],$allowed),403);
  $q=$this->filtered(Record::whereNull('payload->archived')->whereIn('domain',empty($v['domain'])?$allowed:[$v['domain']]),$v);
  $rows=(clone $q)->whereIn('kind',['recommendation','prediction'])->latest('id')->paginate(25);
  $counts=(clone $q)->selectRaw('kind, COUNT(*) as total')->groupBy('kind')->pluck('total','kind');$responses=(clone $q)->where('kind','response')->latest('id')->limit(2000)->get()->unique(fn($r)=>$r->payload['recommendation_id']);
  $responseCounts=$responses->countBy(fn($r)=>$r->payload['state']);
  return ['state'=>($counts['outcome']??0)>=config('decision_learning.minimum_samples')?'OBSERVED_HISTORY':'COLLECTING_DECISION_OUTCOMES','as_of'=>now()->toIso8601String(),
   'summary'=>['recommendations'=>$counts['recommendation']??0,'evaluated'=>$counts['outcome']??0,'prediction_evaluations'=>$counts['prediction_outcome']??0,'waiting'=>max(0,($counts['recommendation']??0)-($counts['outcome']??0)),'human_responses'=>$responseCounts],
   'rows'=>$this->presentedRows($rows->items()),'page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total(),
   'production'=>in_array('inventory',$allowed)?$this->currentPolicy():null,'experiments'=>in_array('inventory',$allowed)?Record::where('kind','experiment')->whereNull('payload->archived')->latest('id')->limit(10)->get()->map(fn($e)=>$this->comparison($e->id))->all():[],
   'policy_history'=>in_array('inventory',$allowed)?Record::where('kind','policy_change')->whereNull('payload->archived')->latest('id')->limit(10)->get()->map(fn($r)=>$this->present($r))->all():[],
   'patterns'=>$this->patterns($v),'suggestions'=>$this->suggestions(),'domains'=>$allowed,'decision_types'=>(clone $q)->whereIn('kind',['recommendation','prediction'])->distinct()->pluck('decision_type'),
   'can_manage'=>Auth::user()->role==='admin'&&$this->can('analytics.ml_datasets')&&$this->can('analytics.finance')&&$this->can('inventory.view'),
   'data_sufficiency'=>['completed'=>$counts['outcome']??0,'required'=>config('decision_learning.minimum_samples'),'scope'=>'company; product/category specialization requires independent evidence and is not enabled on sparse samples','regime'=>'Comparisons exclude old evidence, overlapping windows and changed unit/ceiling/planning/supplier regimes.'],
   'qualification'=>config('decision_learning.qualification')];
 }
 private function present(Record $r):array {
  $p=$r->payload;
  if(!$this->can('analytics.finance'))$p=$this->redact($p,['base_cost','unit_price','purchase_cost','base_value','currency','financial_context_at_recommendation','finance','financial_intelligence','recorded_purchase_investment']);
  if(!$this->can('finance.view')||!$this->can('financial_accounts.view'))$p=$this->redact($p,['financial_context_at_recommendation','finance','financial_intelligence']);
  if(!$this->can('supplier_performance.view'))$p=$this->redact($p,['supplier_evidence','supplier_risk']);
  if(!$this->can('purchase_orders.view'))$p=$this->redact($p,['procurement','purchase_order_ids','purchase_request_id']);
  if(!$this->can('shipments.view'))$p=$this->redact($p,['logistics','shipment_ids']);
  unset($p['input'],$p['ranking_context'],$p['days']);
  return ['id'=>$r->id,'kind'=>$r->kind,'domain'=>$r->domain,'type'=>$r->decision_type,'version'=>$r->version,'source_type'=>$r->source_type,'source_id'=>$r->source_id,'evidence_cutoff'=>$r->evidence_cutoff?->toIso8601String(),'recorded_at'=>$r->created_at->toIso8601String(),'data'=>$p];
 }
 private function redact(array $p,array $keys):array {foreach($p as $k=>$v){if(in_array($k,$keys,true))unset($p[$k]);elseif(is_array($v))$p[$k]=$this->redact($v,$keys);}return $p;}
 public function outcome(int $id):array {
  $this->authorize();$d=EnterpriseDecision::findOrFail($id);$r=Record::where('kind','recommendation')->where('source_type','EnterpriseDecision')->where('source_id',$id)->first();if(!$r)return ['state'=>'NOT_CAPTURED','decision_id'=>$id];
  return $this->recordOutcome($r->id);
 }
 public function recordOutcome(int $id):array {
  $this->authorize();$r=Record::where('kind','recommendation')->whereNull('payload->archived')->findOrFail($id);
  abort_unless(in_array($r->domain,$this->allowedDomains()),403);
  return ['state'=>Record::where('kind','outcome')->where('payload->recommendation_id',$r->id)->exists()?'COMPLETED_OBSERVATION':'WAITING_FOR_GENUINE_OUTCOME','recommendation'=>$this->present($r),
   'responses'=>Record::where('kind','response')->where('payload->recommendation_id',$r->id)->orderBy('id')->get()->map(fn($r)=>$this->present($r))->all(),
   'outcome'=>($o=Record::where('kind','outcome')->where('payload->recommendation_id',$r->id)->first())?$this->present($o):null];
 }
 public function patterns(array $filters=[]):array {
  $this->authorize();$filters=$this->filters($filters);$q=$this->filtered(Record::where('kind','outcome')->whereNull('payload->archived')->whereIn('domain',$this->allowedDomains())->where('evidence_cutoff','>=',now()->subDays(config('decision_learning.evidence_days')))->orderBy('id'),$filters);
  if(!empty($filters['domain'])){abort_unless(in_array($filters['domain'],$this->allowedDomains()),403);$q->where('domain',$filters['domain']);}
  $rows=$q->whereNotNull('payload->product_id')->whereNotNull('payload->window')->reorder()->latest('id')->limit(2000)->get()->sortBy(fn($r)=>$r->payload['window']['from'])->values();$ends=[];$regimes=[];foreach($rows as $r){$p=$r->payload;$regimes[$p['product_id'].':'.($p['warehouse_id']??0)]=$p['regime_key']??'legacy';}
  $rows=$rows->filter(function($r)use(&$ends,$regimes){$p=$r->payload;$key=$p['product_id'].':'.($p['warehouse_id']??0);if(($p['regime_key']??'legacy')!==$regimes[$key]||isset($ends[$key])&&$p['window']['from']<=$ends[$key])return false;$ends[$key]=$p['window']['to'];return true;});
  $matrix=[];foreach(['ACCEPTED','MODIFIED','DISMISSED','NOT_REVIEWED','SUPERSEDED','EXPIRED'] as $s){$g=$rows->filter(fn($r)=>$r->payload['response']['state']===$s);$issues=$g->filter(fn($r)=>$r->payload['scorecard']['stockout_days']>0);$matrix[]=['state'=>$s,'samples'=>$g->count(),'with_observed_stockout'=>$issues->count(),'no_observed_stockout'=>$g->count()-$issues->count(),'with_observed_excess'=>$g->filter(fn($r)=>($r->payload['scorecard']['excess_quantity']??0)>0)->count()];}
  $changed=$rows->filter(fn($r)=>$r->payload['response']['state']==='MODIFIED'&&$r->payload['response']['recommended_quantity']>0&&($r->payload['chain']['actual_ordered_quantity']??null)!==null&&($r->payload['chain']['received_quantity']??0)>0&&$r->payload['chain']['received_quantity']>=$r->payload['chain']['actual_ordered_quantity']-.0005);
  $fractions=$changed->map(fn($r)=>$r->payload['chain']['actual_ordered_quantity']/$r->payload['response']['recommended_quantity']-1)->sort()->values();
  return ['independent_samples'=>$rows->count(),'modified_samples'=>$changed->count(),'median_quantity_change_percent'=>$fractions->count()?100*$fractions[(int)floor(($fractions->count()-1)/2)]:null,
   'modified_windows_with_observed_stockout'=>$changed->filter(fn($r)=>$r->payload['scorecard']['stockout_days']>0)->count(),'matrix'=>$matrix,'qualification'=>'Quantity calibration uses actually ordered and fully received quantities, not a draft or an intent. Observational associations are not proof that accepting or overriding caused a good or poor result.'];
 }
 public function suggestions():array {
  $this->authorize();return ['rows'=>Record::where('kind','suggestion')->whereNull('payload->archived')->whereIn('domain',$this->allowedDomains())->latest('id')->limit(10)->get()->map(fn($r)=>$this->present($r))->all(),'qualification'=>'Suggested policy review only. The production champion has not changed.'];
 }
 public function performance(array $filters=[]):array {
  $this->authorize();$filters=$this->filters($filters);$domain=$filters['domain']??null;abort_if($domain&&!in_array($domain,$this->allowedDomains()),403);
  $rows=$this->filtered(Record::whereIn('kind',['outcome','prediction_outcome'])->whereNull('payload->archived')->whereIn('domain',$domain?[$domain]:$this->allowedDomains()),$filters)->latest('id')->paginate(25);
  return ['rows'=>array_map(fn($r)=>$this->present($r),$rows->items()),'page'=>$rows->currentPage(),'last_page'=>$rows->lastPage(),'total'=>$rows->total(),'qualification'=>config('decision_learning.qualification')];
 }
 private function sourceBatch($query,string $source,int $limit,float $deadline):\Generator {
  if(microtime(true)>=$deadline)return;
  $key='decision-learning-source:'.Auth::user()->company_id.':'.$source;$cursor=(int)Cache::get($key,0);
  $rows=(clone $query)->where('id','>',$cursor)->orderBy('id')->limit($limit)->get();
  if($rows->isEmpty()){$cursor=0;Cache::forget($key);$rows=(clone $query)->orderBy('id')->limit($limit)->get();}
  foreach($rows as $row){if(microtime(true)>=$deadline)break;yield $row;Cache::put($key,$row->id,86400);}
 }
 private function importPredictions(int $limit,float $deadline):int {
  $n=0;
  $models=['inventory'=>'inventory-demand-v1','supplier'=>'supplier-lead-v1','customer'=>'customer-sales-v8'];
  foreach($models as $domain=>$key)if(in_array($domain,$this->allowedDomains()))foreach($this->sourceBatch(AnalyticsPrediction::where('model_key',$key)->whereNull('value->archived'),'prediction-'.$key,$limit,$deadline) as $p){$r=$this->append('prediction:'.$p->id,'prediction',$domain,$p->prediction_type,['model_version'=>$p->model_version,'frozen_prediction'=>$p->value,'state'=>'HISTORICAL_PREDICTION','valid_until'=>$p->valid_until?->toIso8601String(),'response'=>'NOT_REVIEWED'],'AnalyticsPrediction',$p->id,$p->generated_at);$n+=(int)$r->wasRecentlyCreated;}
  if(in_array('supplier',$this->allowedDomains()))foreach($this->sourceBatch(AnalyticsPrediction::where('model_key','supplier-lead-v1')->whereNull('value->archived')->where('evaluation->eligible',true),'supplier-outcome',$limit,$deadline) as $p){$r=$this->append('prediction-outcome:'.$p->id,'prediction_outcome','supplier','supplier_lead',['model_version'=>$p->model_version,'prediction_quality'=>$p->evaluation,'actual'=>$p->actual_value,'decision_quality'=>null,'qualification'=>'Actual end-to-end receipt lead time is not evidence that a delay was supplier-caused.'],'AnalyticsPrediction',$p->id,$p->generated_at);$n+=(int)$r->wasRecentlyCreated;}
  if(in_array('shipment',$this->allowedDomains()))foreach($this->sourceBatch(ShipmentIntelligence::whereNull('evidence->archived'),'shipment-prediction',$limit,$deadline) as $p){$r=$this->append('shipment-prediction:'.$p->version,'prediction','shipment','eta_prediction',['model_version'=>$p->version,'frozen_eta'=>$p->eta,'confidence'=>$p->confidence,'state'=>'HISTORICAL_PREDICTION','response'=>'NOT_REVIEWED'],'ShipmentIntelligence',$p->id,$p->evidence_cutoff);$n+=(int)$r->wasRecentlyCreated;}
  if(in_array('finance',$this->allowedDomains()))foreach($this->sourceBatch(FinancialIntelligenceSnapshot::whereNull('evidence->archived'),'finance-prediction',$limit,$deadline) as $p){$r=$this->append('finance-prediction:'.$p->version,'prediction','finance','cash_forecast',['model_version'=>$p->version,'forecast'=>$p->forecast,'state'=>'HISTORICAL_PREDICTION','response'=>'NOT_REVIEWED'],'FinancialIntelligenceSnapshot',$p->id,$p->evidence_cutoff);$n+=(int)$r->wasRecentlyCreated;}
  if(in_array('inventory',$this->allowedDomains()))foreach($this->sourceBatch(AnalyticsPrediction::where('model_key','inventory-demand-v1')->whereNull('value->archived')->whereNotNull('evaluated_at')->where('evaluation->eligible',true),'inventory-outcome',$limit,$deadline) as $p){$r=$this->append('prediction-outcome:'.$p->id,'prediction_outcome','inventory','demand_forecast',['model_version'=>$p->model_version,'prediction_quality'=>$p->evaluation,'actual'=>$p->actual_value,'decision_quality'=>null,'qualification'=>'Prediction accuracy is separate from recommendation/action quality.'],'AnalyticsPrediction',$p->id,$p->generated_at);$n+=(int)$r->wasRecentlyCreated;}
  if(in_array('shipment',$this->allowedDomains()))foreach($this->sourceBatch(ShipmentIntelligence::whereNull('evidence->archived')->where('outcome->eligible',true),'shipment-outcome',$limit,$deadline) as $p){$r=$this->append('shipment-outcome:'.$p->version,'prediction_outcome','shipment','eta_prediction',['model_version'=>$p->version,'prediction_quality'=>$p->outcome,'frozen_eta'=>$p->eta,'decision_quality'=>null,'qualification'=>$p->outcome['qualification']],'ShipmentIntelligence',$p->id,$p->evidence_cutoff);$n+=(int)$r->wasRecentlyCreated;}
  if(in_array('finance',$this->allowedDomains()))foreach($this->sourceBatch(FinancialIntelligenceSnapshot::whereNull('evidence->archived')->whereNotNull('evaluation'),'finance-outcome',$limit,$deadline) as $p)foreach(['cash','collections'] as $type)foreach($p->evaluation[$type]??[] as $key=>$score){$r=$this->append('finance-outcome:'.$p->version.':'.$type.':'.$key,'prediction_outcome','finance',$type.'_forecast',['model_version'=>$p->version,'prediction_quality'=>$score,'decision_quality'=>null,'qualification'=>'Existing V7 matched-account/completed-payment evaluation; no changed definitions or inferred cash outcome.'],'FinancialIntelligenceSnapshot',$p->id,$p->evidence_cutoff);$n+=(int)$r->wasRecentlyCreated;}
  if(in_array('customer',$this->allowedDomains()))foreach($this->sourceBatch(CustomerSalesPrediction::v8()->whereNull('value->archived')->whereNotNull('evaluated_at'),'customer-outcome',$limit,$deadline) as $p){$r=$this->append('customer-outcome:'.$p->id.':'.hash('sha256',json_encode($p->evaluation)),'prediction_outcome','customer',$p->prediction_type,['model_version'=>$p->model_version,'prediction_quality'=>$p->evaluation,'decision_quality'=>null,'qualification'=>'Customer inactivity/reorder observation, not proof of churn or recommendation failure. Unacted affinities are not scored as failures.'],'CustomerSalesPrediction',$p->id,$p->generated_at);$n+=(int)$r->wasRecentlyCreated;}
  return $n;
 }
 private function importExistingAdvice(int $limit,float $deadline):int {
  $n=0;if(!in_array('inventory',$this->allowedDomains()))return $n;
  foreach($this->sourceBatch(\App\Models\InventoryRecommendation::with('prediction')->whereNotIn('status',['archived']),'existing-advice',$limit,$deadline) as $rec){
   $prediction=$rec->prediction;if(!$prediction||($prediction->value['archived']??false))continue;
   $e=$rec->explanation;$end=last($e['daily']??$prediction->value['daily']??[])['date']??null;
   $recommended=$rec->feedback['recommended_base_quantity']??$e['desired_base_quantity']??$e['base_quantity']??null;
   $type=$rec->planning_key?'inventory_plan':'replenishment_advice';$version=$rec->planning_key??'v1-recommendation-'.$rec->id;
   $r=$this->append('legacy-recommendation:'.$rec->id,'recommendation','inventory',$type,[
    'origin'=>'historical_snapshot','product_id'=>$rec->product_id,'warehouse_id'=>$rec->warehouse_id,'policy_version'=>$e['policy_version']??$e['logic_version']??'inventory-v1','model_version'=>$prediction->model_version,
    'recommended'=>['base_quantity'=>$recommended,'supplier_id'=>$e['supplier_id']??null],'evidence'=>$e,'window_end'=>$end,'source_version'=>$version],'InventoryRecommendation',$rec->id,$prediction->generated_at);$n+=(int)$r->wasRecentlyCreated;
   $state=match($rec->status){'accepted'=>'ACCEPTED','adjusted'=>'MODIFIED','dismissed'=>'DISMISSED','superseded'=>'SUPERSEDED','postponed'=>'NOT_REVIEWED',default=>'NOT_REVIEWED'};
   if($rec->purchase_request_id&&isset($rec->feedback['base_quantity'],$recommended))$state=abs($rec->feedback['base_quantity']-$recommended)>.0005?'MODIFIED':'ACCEPTED';
   $response=['recommendation_id'=>$r->id,'state'=>$state,'recommended_quantity'=>$recommended,'chosen_quantity'=>$rec->feedback['base_quantity']??null,'chosen_supplier_id'=>$rec->feedback['supplier_id']??null,'purchase_request_id'=>$rec->purchase_request_id,'at'=>$rec->feedback['at']??null,'user_id'=>$rec->feedback['user_id']??null,'action_recorded'=>$rec->purchase_request_id!==null];
   $this->append('legacy-response:'.$rec->id.':'.hash('sha256',json_encode($response)),'response','inventory',$type,$response,'InventoryRecommendation',$rec->id,$prediction->generated_at);
   $o=$rec->outcome;if(!$o||!$end||$end>=today()->toDateString()||($o['state']??'')!=='completed_observation')continue;
   $from=$rec->created_at->copy()->addDay()->toDateString();
   $obs=AnalyticsSnapshot::where('entity_type',$rec->warehouse_id?'inventory':'demand_observation')->where('entity_id',$rec->product_id)->where('warehouse_id',$rec->warehouse_id??0)->whereDate('snapshot_date','>=',$from)->whereDate('snapshot_date','<=',$end)->get();
   if($obs->count()!==(int)Date::parse($from)->diffInDays(Date::parse($end))+1||!$this->genuineStockObservations($obs,$rec->product_id,$rec->warehouse_id,$prediction->value['unit']??''))continue;
   $this->append('legacy-outcome:'.$rec->id,'outcome','inventory',$type,[
    'recommendation_id'=>$r->id,'state'=>'COMPLETED_OBSERVATION','policy_version'=>$r->payload['policy_version'],'regime_key'=>'legacy-'.$r->payload['policy_version'].'-'.($prediction->value['unit']??''),'product_id'=>$rec->product_id,'warehouse_id'=>$rec->warehouse_id,'window'=>['from'=>$rec->created_at->copy()->addDay()->toDateString(),'to'=>$end],
    'response'=>$response,'chain'=>$o,'scorecard'=>['stockout_days'=>$o['stockout_days']??null,'excess_mean_quantity'=>null,'excess_quantity'=>null,'ending_available'=>$o['latest_available']??null,'purchase_cost'=>$o['recorded_purchase_investment']??null,'cash_pressure'=>null],'prediction_quality'=>$prediction->evaluation,'decision_quality'=>['causal_claim'=>false],'qualification'=>$o['qualification']],'InventoryRecommendation',$rec->id,$prediction->generated_at);
  }
  return $n;
 }
 public function maintain(?float $outerDeadline=null):int {
  $this->authorize();$deadline=min($outerDeadline??PHP_FLOAT_MAX,microtime(true)+config('decision_learning.worker_seconds'));$limit=config('decision_learning.batch_limit');$n=0;
  $cursor=(int)Cache::get('decision-learning-cursor:'.Auth::user()->company_id,0);
  $rows=EnterpriseDecision::whereNull('evidence->archived')->where('id','>',$cursor)->orderBy('id')->limit($limit)->get();
  if($rows->isEmpty())Cache::put('decision-learning-cursor:'.Auth::user()->company_id,0,86400);
  if($this->can('inventory.view'))foreach($rows as $d){if(microtime(true)>$deadline)break;$this->capture($d);$this->evaluateDecision($d);Cache::put('decision-learning-cursor:'.Auth::user()->company_id,$d->id,86400);$n++;}
  if(microtime(true)<$deadline)$n+=$this->importPredictions($limit,$deadline);
  if(microtime(true)<$deadline)$n+=$this->importExistingAdvice($limit,$deadline);
  if(microtime(true)<$deadline&&$this->can('inventory.view')){
   $patterns=$this->patterns();$minimum=config('decision_learning.minimum_samples');
   if($patterns['modified_samples']>=$minimum&&abs($patterns['median_quantity_change_percent']??0)>=10){
    $bucket=today()->startOfMonth()->toDateString();$s=$this->append('override-suggestion:'.$bucket.':'.$this->currentPolicy()['version'],'suggestion','inventory','quantity_calibration',
     ['state'=>'SUGGESTED','pattern'=>$patterns,'suggested_multiplier'=>max(.8,min(1.2,1+$patterns['median_quantity_change_percent']/100)),'champion'=>$this->currentPolicy()['version'],'reason'=>'Repeated quantity overrides with completed observed windows warrant shadow review, not automatic policy changes.','confidence'=>'observational_company_evidence']);
    if($s->wasRecentlyCreated)$this->signal('policy_review_suggested',$s,['state'=>'SUGGESTED','samples'=>$patterns['modified_samples']],true);
   }
   if($patterns['independent_samples']>=$minimum&&collect($patterns['matrix'])->sum('with_observed_stockout')/$patterns['independent_samples']>.2){
    $s=$this->append('degradation:'.today()->startOfMonth()->toDateString(),'suggestion','inventory','performance_review',['state'=>'REVIEW','pattern'=>$patterns,'reason'=>'Recorded stockout issues exceed the visible 20% review threshold; this is not a causal accuracy score.']);if($s->wasRecentlyCreated)$this->signal('performance_degraded',$s,['state'=>'REVIEW','samples'=>$patterns['independent_samples']],true);
   }
   foreach(Record::where('kind','experiment')->whereNull('payload->archived')->latest('id')->limit(3)->get() as $e){$c=$this->comparison($e->id);if($c['comparison']['eligible_for_human_review'])$this->signal('challenger_ready',$e,['state'=>'READY_FOR_HUMAN_REVIEW','samples'=>$c['comparison']['independent_samples']],true);}
  }
  return $n;
 }
 public function scheduled():int {
  $old=Auth::user();$n=0;$deadline=microtime(true)+config('decision_learning.worker_seconds');
  $cursor=(int)Cache::get('decision-learning-company-cursor',0);$companies=Company::where('id','>',$cursor)->orderBy('id')->limit(20)->get();if($companies->isEmpty()){Cache::forget('decision-learning-company-cursor');$companies=Company::orderBy('id')->limit(20)->get();}
  try{foreach($companies as $c){if(microtime(true)>$deadline)break;Cache::put('decision-learning-company-cursor',$c->id,86400);$u=User::withoutGlobalScopes()->where('company_id',$c->id)->where('is_active',true)->where('role','admin')->first();if(!$u)continue;Auth::setUser($u);
   try{Cache::lock('decision-learning:'.$c->id,120)->get(function()use(&$n,$deadline){$n+=$this->maintain($deadline);});}catch(\Throwable $e){Log::warning('Decision learning deferred',['company_id'=>$c->id,'error'=>class_basename($e)]);}
  }}finally{$old?Auth::setUser($old):Auth::forgetUser();}return $n;
 }
}
