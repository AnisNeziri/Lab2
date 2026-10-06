<?php
namespace App\Services;

use App\Models\{Supplier,PurchaseOrder,SupplierDeliveryRisk,SupplierLeadModel,AnalyticsPrediction,ProductSupplier};
use Carbon\CarbonImmutable as Date;
use Illuminate\Support\Facades\{Auth,DB,Cache};

/** Advisory evidence only. Never writes purchasing, stock, shipment or financial records. */
final class SupplierIntelligenceService {
 public function __construct(private SupplierHistoryService $history) {}
 public function authorize(bool $manage=false):void {
  abort_unless(Auth::user()?->company_id,403);
  foreach(['supplier_performance.view','purchase_orders.view',...($manage?['procurement.manage','analytics.ml_datasets']:[])] as $p)
   abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,$p),403);
 }
 public function supplier(int $id):Supplier {return Supplier::where('company_id',Auth::user()->company_id)->findOrFail($id);}
 public function active(int $id):?SupplierLeadModel {return SupplierLeadModel::where('supplier_id',$id)->where('status','active')->first();}

 public function assess(array $row,array $distribution):array {
  $today=today()->toDateString();$promise=$row['original_promise'];$reasons=[];$causes=[];
  $stages=['supplier_confirmation'=>'preparation','supplier_production'=>'preparation','cargo_ready'=>'preparation','supplier_dispatch'=>'preparation','container_loaded'=>'origin_logistics','vessel_departure'=>'origin_logistics','sea_transit'=>'transit','transshipment'=>'transit','destination_port'=>'transit','customs_started'=>'customs','customs_cleared'=>'customs','inland_transport'=>'local_transport','warehouse_arrival'=>'local_transport','goods_receipt'=>'receiving','inventory_available'=>'receiving'];
  foreach($row['milestones'] as $m){
   // Only recorded targets support a stage delay; stage is not a finding of supplier fault.
   if($m['known_at']>now()->toIso8601String()||!$m['planned'])continue;
   $target=substr($m['planned'],0,10);$actual=$m['actual']?substr($m['actual'],0,10):null;$estimate=$m['estimated']?substr($m['estimated'],0,10):null;
   if((!$actual&&$target<$today)||($estimate&&$estimate>$target&&!$actual)){$reasons[]=['code'=>'milestone_delay','milestone'=>$m];$causes[]=$stages[$m['type']]??'unknown';}
  }
  if($promise&&$promise<$today)$reasons[]=['code'=>'original_promise_overdue','date'=>$promise];
  if(!$promise&&$row['current_promise']&&$row['current_promise']<$today)$reasons[]=['code'=>'current_promise_overdue','date'=>$row['current_promise']];
  foreach($row['shipments'] as $s)if($s['eta']&&$promise&&substr((string)$s['eta'],0,10)>$promise)$reasons[]=['code'=>'shipment_eta_after_promise','shipment_id'=>$s['id'],'eta'=>$s['eta']];
  $window=$distribution['eligible']&&$row['ordered_at']?['from'=>Date::parse($row['ordered_at'])->addDays($distribution['p10_days'])->toDateString(),'to'=>Date::parse($row['ordered_at'])->addDays($distribution['p90_days'])->toDateString()]:null;
  $risk=$reasons?'high':($window&&(($promise&&$window['to']>$promise)||$window['to']<$today)?'warning':($distribution['eligible']?'normal':'insufficient_data'));
  if($row['complete']||in_array($row['status'],['draft','cancelled','received','completed']))$risk='closed';
  return ['risk'=>$risk,'causes'=>array_values(array_unique($causes?:['unknown'])),'reasons'=>$reasons,'original_promise'=>$promise,'current_promise'=>$row['current_promise'],'window'=>$window,
   'baseline_days'=>$distribution['eligible']?$distribution['median_days']:null,'samples'=>$distribution['samples'],'supplier_fault_established'=>false,
   'actions'=>$risk==='high'?['review_purchase_order','review_alternative_suppliers']:($risk==='warning'?['review_order_timing']:[]),
   'lines'=>$row['lines'],'shipments'=>$row['shipments'],'as_of'=>now()->toIso8601String(),'qualification'=>'Historical range, not a delivery guarantee. A delayed stage does not establish supplier responsibility.'];
 }

 public function refresh(int $id):array {
  $this->authorize();$s=$this->supplier($id);
  return Cache::lock('supplier-intelligence:'.$s->company_id.':'.$id,120)->block(2,function()use($s){
   $rows=$this->history->orders($s);$distribution=$this->history->distribution($rows);$anchor=$this->history->capture($s,$rows);$model=$this->active($s->id);
   $modelPredictions=[];$modelWarning=null;
   $baselineEvidence=collect($rows)->filter(fn($r)=>$r['complete']&&$r['lead_days']!==null&&$r['label_known_at']<=today()->toDateString())->map(fn($r)=>collect($r)->only(['order_id','lead_days','completion_date','label_known_at'])->all())->values()->all();
   $already=AnalyticsPrediction::where('prediction_type','supplier_lead')->where('entity_type','purchase_order')->where('model_version',$model?->version??'supplier-baseline-v1')->pluck('entity_id')->all();
   $eligible=array_values(array_filter($rows,fn($r)=>!$r['complete']&&!in_array($r['status'],['draft','cancelled'])&&$r['ml_eligible']&&!in_array($r['order_id'],$already)));
   if($model&&$eligible)try{app(InventoryLearningService::class)->checkArtifact($model);$modelPredictions=app(LocalSupplierLeadProvider::class)->run(['mode'=>'predict','artifact'=>$model->artifact,'orders'=>$eligible])['predictions'];}catch(\Throwable){$modelWarning='local_model_unavailable_baseline_used';}
   foreach($rows as $row){
    $e=$this->assess($row,$distribution);$po=PurchaseOrder::find($row['order_id']);if(!$po)continue;
    DB::transaction(function()use($s,$po,$row,$e,$anchor,$model,$modelPredictions,$modelWarning,$baselineEvidence){
     PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
     $risk=SupplierDeliveryRisk::firstOrNew(['company_id'=>$s->company_id,'purchase_order_id'=>$po->id]);
     $signature=fn($x)=>json_encode([$x['causes']??[],collect($x['reasons']??[])->map(fn($r)=>[$r['code'],$r['milestone']['planned']??$r['date']??$r['eta']??null,substr($r['milestone']['estimated']??'',0,10)])->all()]);
     $changed=$e['risk']==='high'&&($risk->risk!=='high'||$signature($risk->evidence??[])!==$signature($e));
     $risk->fill(['supplier_id'=>$s->id,'risk'=>$e['risk'],'evidence'=>$e,'episode'=>($risk->episode??0)+($changed?1:0)])->save();
     if($changed)app(BusinessEventService::class)->record('supplier_delivery.high_risk',$po,$po->po_number,['risk'=>'high','causes'=>$e['causes'],'original_promise'=>$e['original_promise']],'supplier-risk:'.$risk->id.':'.$risk->episode);
     if($e['risk']==='closed'||$e['baseline_days']===null||!$row['ordered_at'])return;
     // Freeze once per PO and model. Repeated refreshes cannot inflate measured accuracy.
     $version=$model?->version??'supplier-baseline-v1';
     if(AnalyticsPrediction::where('prediction_type','supplier_lead')->where('entity_type','purchase_order')->where('entity_id',$po->id)->where('model_version',$version)->exists())return;
     $days=$modelPredictions[$po->id]??$e['baseline_days'];$method=isset($modelPredictions[$po->id])?'approved_model':'historical_median';$warning=$modelWarning;
     // A fallback is recorded under the baseline, never counted as the champion's accuracy.
     if($method!=='approved_model')$version='supplier-baseline-v1';
     if(AnalyticsPrediction::where('prediction_type','supplier_lead')->where('entity_type','purchase_order')->where('entity_id',$po->id)->where('model_version',$version)->exists())return;
     AnalyticsPrediction::create(['company_id'=>$s->company_id,'prediction_type'=>'supplier_lead','entity_type'=>'purchase_order','entity_id'=>$po->id,'model_key'=>'supplier-lead-v1','model_version'=>$version,
      'input_feature_version'=>'supplier-history-v1','analytics_snapshot_id'=>$anchor->id,'value'=>['supplier_id'=>$s->id,'order_id'=>$po->id,'ordered_at'=>$row['ordered_at'],'original_promise'=>$row['original_promise'],'scope_fingerprint'=>$row['scope_fingerprint'],'days'=>$days,'baseline_days'=>$e['baseline_days'],'baseline_evidence'=>$baselineEvidence,'window'=>$e['window'],'method'=>$method,'warning'=>$warning,'training_cutoff'=>$method==='approved_model'?$model?->training_cutoff?->toDateString():null,'expected_date'=>Date::parse($row['ordered_at'])->addDays((int)ceil($days))->toDateString()],
      'generated_at'=>now(),'valid_until'=>now()->addDay(),'confidence'=>null]);
    });
   }
   $this->evaluate($s,$rows);return ['refreshed'=>count($rows),'completed_samples'=>$distribution['samples']];
  });
 }

 public function evaluate(Supplier $supplier,array $rows):void {
  $byId=collect($rows)->keyBy('order_id');
  foreach(AnalyticsPrediction::where('prediction_type','supplier_lead')->where('entity_type','purchase_order')->where('value->supplier_id',$supplier->id)->whereNull('evaluated_at')->get() as $p){
   $r=$byId->get($p->entity_id);if(!$r)continue;
   if(!$r['complete']&&$r['status']!=='cancelled')continue;
   if($r['complete']&&$r['label_known_at']>today()->toDateString())continue;
   $eligible=$r['complete']&&$r['lead_days']!==null&&$r['completion_date']>$p->generated_at->toDateString()&&(!$p->value['training_cutoff']||$p->value['training_cutoff']<$p->generated_at->toDateString())&&$r['ordered_at']===$p->value['ordered_at']&&$r['scope_fingerprint']===($p->value['scope_fingerprint']??null);
   $p->update(['actual_value'=>['lead_days'=>$r['lead_days'],'completion_date'=>$r['completion_date'],'stages'=>$r['stages'],'receipts'=>$r['receipts']],
    'evaluation'=>['eligible'=>$eligible,'status'=>$eligible?'completed':'excluded','absolute_error'=>$eligible?abs($p->value['days']-$r['lead_days']):null,'signed_error'=>$eligible?$p->value['days']-$r['lead_days']:null,'baseline_error'=>$eligible?abs($p->value['baseline_days']-$r['lead_days']):null], 'evaluated_at'=>now()]);
  }
 }

 public function report(int $id,array $filters=[]):array {
  $this->authorize();$s=$this->supplier($id);$rows=$this->history->orders($s);
  $distribution=$this->history->distribution($rows,isset($filters['product_id'])?(int)$filters['product_id']:null,isset($filters['category_id'])?(int)$filters['category_id']:null,$filters['origin']??null,$filters['mode']??null,$filters['destination']??null);
  $overall=$this->history->distribution($rows);$stored=SupplierDeliveryRisk::where('supplier_id',$id)->get()->keyBy('purchase_order_id');
  $predictions=AnalyticsPrediction::where('prediction_type','supplier_lead')->where('entity_type','purchase_order')->where('value->supplier_id',$id)->latest('id')->limit(500)->get();$latest=$predictions->unique('entity_id')->keyBy('entity_id');
  $active=$this->active($id);$learning=app(SupplierLearningService::class);
  $scores=$predictions->where('evaluation.eligible',true)->groupBy('model_version')->map(fn($p)=>['orders'=>$p->count(),'mae_days'=>round($p->avg('evaluation.absolute_error'),3),'baseline_mae_days'=>round($p->avg('evaluation.baseline_error'),3),'bias_days'=>round($p->avg('evaluation.signed_error'),3)])->all();
  $history=array_map(function($r)use($overall,$stored,$latest){$record=$stored->get($r['order_id']);$feedback=array_map(fn($f)=>$f+['outcome'=>['completion_after_decision'=>$r['complete']&&$r['completion_date']>substr($f['at'],0,10)?$r['completion_date']:null,'attribution'=>'observed_not_causal']],$record?->feedback??[]);return $r+['risk'=>$this->assess($r,$overall),'feedback'=>$feedback,'risk_id'=>$record?->id,'prediction'=>$latest->get($r['order_id'])?->only(['id','model_version','generated_at','value','actual_value','evaluation'])];},$rows);
  $can=fn($p)=>app(PermissionService::class)->roleHasPermission(Auth::user()->role,$p);
  if(!$can('analytics.finance'))foreach($history as &$r){unset($r['base_value'],$r['exchange_rate_snapshot']);}unset($r);
  foreach($history as &$r){foreach($r['feedback'] as &$f)unset($f['evidence']);unset($f);if(!$can('shipments.view')){$r['shipments']=[];$r['milestones']=[];$r['risk']['shipments']=[];}}unset($r);
  $recent=$predictions->where('model_version',$active?->version??'supplier-baseline-v1')->where('evaluation.eligible',true)->take(5);
  $health=count($recent)<5?'insufficient_evidence':($recent->every(fn($p)=>$p->evaluation['absolute_error']>$p->evaluation['baseline_error'])?'review_required':'measured');
  return ['supplier'=>$s->only(['id','name']),'distribution'=>$distribution,'orders'=>$history,'performance'=>$scores,'health'=>['state'=>$health,'completed_orders'=>$recent->count(),'minimum'=>5],
   'active_version'=>$active?->version,'models'=>SupplierLeadModel::where('supplier_id',$id)->latest()->limit(10)->get()->map(fn($m)=>$m->makeHidden('artifact')->toArray()+['gate'=>$learning->gates($m,$active)])->all(),
   'decisions'=>\App\Models\InventoryModelDecision::where('domain','supplier_lead')->where('supplier_id',$id)->latest()->limit(20)->get(),
   'comparison'=>$this->comparison($s,$filters),'can_manage'=>$can('procurement.manage')&&$can('analytics.ml_datasets'),'can_feedback'=>$can('procurement.manage'),
   'limitations'=>['incomplete_orders_not_training_targets','original_commitment_requires_audit_evidence','historical_range_not_probability','no_delay_classifier','no_automatic_purchasing'],
   'coverage'=>['orders'=>count($rows),'completed'=>$overall['samples'],'ml_eligible'=>collect($rows)->where('complete',true)->where('ml_eligible',true)->where('label_known_at','<=',today()->toDateString())->count(),'history_limit'=>config('supplier_intelligence.history_limit')]];
 }
 private function comparison(Supplier $supplier,array $filters):array {
  if(empty($filters['product_id'])||!app(PermissionService::class)->roleHasPermission(Auth::user()->role,'supplier_catalogue.view'))return [];
  return ProductSupplier::with('supplier')->where('product_id',(int)$filters['product_id'])->where('is_active',true)->whereHas('supplier',fn($q)=>$q->where('is_active',true))->limit(10)->get()->map(function($c){
   $quality=app(SupplierPerformanceService::class)->scorecard($c->supplier)['quality'];
   return $c->only(['supplier_id','purchase_price','currency','exchange_rate_to_base','minimum_order_quantity','pack_size','usual_lead_time_days'])+['name'=>$c->supplier->name,'delivery'=>$this->history->distribution($this->history->orders($c->supplier),(int)$c->product_id),'quality'=>collect($quality)->only(['inspections','acceptance_percent','defect_rate','claim_count'])->all()];
  })->all();
 }
 public function feedback(int $id,array $input):array {
  $this->authorize();abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'procurement.manage'),403);
  $data=validator($input,['decision'=>'required|in:accepted,modified,dismissed','note'=>'required|string|min:3|max:1000'])->validate();
  return DB::transaction(function()use($id,$data){$r=SupplierDeliveryRisk::lockForUpdate()->findOrFail($id);$history=$r->feedback??[];
   $history[]=$data+['user_id'=>Auth::id(),'at'=>now()->toIso8601String(),'evidence'=>$r->evidence,'episode'=>$r->episode];$r->update(['feedback'=>$history]);
   app(AnalyticsDataService::class)->audit('supplier_intelligence.feedback',['risk_id'=>$id]+end($history));return ['saved'=>true];});
 }
 public function maintain(float $deadline):void {
  $processed=0;foreach(Supplier::whereIn('id',PurchaseOrder::select('supplier_id'))->orderBy('id')->cursor() as $s){
   if(microtime(true)>$deadline)break;$key='supplier-intelligence-hour:'.$s->company_id.':'.$s->id;
   if(Cache::has($key))continue;if($processed++>=config('supplier_intelligence.maintenance_batch'))break;
   try{$this->refresh($s->id);Cache::put($key,true,config('supplier_intelligence.refresh_minutes')*60);}catch(\Throwable $e){Cache::put($key,true,300);app(MaintenanceHealthService::class)->record($s->company_id,'supplier_intelligence',class_basename($e));}
  }
 }
}
