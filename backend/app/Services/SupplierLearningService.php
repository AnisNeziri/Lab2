<?php
namespace App\Services;
use App\Models\{Supplier,SupplierLeadModel,AnalyticsDataset,AnalyticsDatasetRow,InventoryModelDecision};
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Illuminate\Support\Str;

/** Uses the existing model registry and immutable decision history. */
final class SupplierLearningService {
 public function gates(SupplierLeadModel $m,?SupplierLeadModel $active):array {
  $reasons=[];$c=$m->comparison;$candidate=$c['challenger']??null;$baseline=$c['baseline']??null;$incumbent=$c['incumbent']??null;
  $factor=1-config('supplier_intelligence.improvement_percent')/100;
  if(($m->quality['samples']??0)<config('supplier_intelligence.minimum_training_orders'))$reasons[]='insufficient_history';
  if(($candidate['samples']??0)<config('supplier_intelligence.minimum_validation_orders'))$reasons[]='insufficient_validation';
  if(!$candidate||!$baseline||$baseline['mae']<=0||$candidate['mae']>$baseline['mae']*$factor)$reasons[]='baseline_not_beaten';
  if(($m->review['production_version']??null)!==$active?->version)$reasons[]='production_changed';
  if($active&&(!$incumbent||$incumbent['mae']<=0||!$candidate||$candidate['mae']>$incumbent['mae']*$factor))$reasons[]='champion_not_beaten';
  return ['eligible'=>!$reasons,'reasons'=>$reasons];
 }
 public function train(int $id):array {
  $service=app(SupplierIntelligenceService::class);$service->authorize(true);$supplier=$service->supplier($id);
  return Cache::lock('supplier-candidate:'.$supplier->company_id.':'.$id,120)->block(2,function()use($service,$supplier){
   $cutoff=today()->subDay()->toDateString();$history=app(SupplierHistoryService::class);$all=$history->orders($supplier);
   $rows=array_values(array_filter($all,fn($r)=>$r['complete']&&$r['ml_eligible']&&$r['label_known_at']<=$cutoff));
   $active=$service->active($supplier->id);if($active)app(InventoryLearningService::class)->checkArtifact($active);
   $hash=InventoryIntelligenceService::artifactHash(['rows'=>$rows,'production_version'=>$active?->version]);
   $existing=SupplierLeadModel::where('supplier_id',$supplier->id)->where('review->input_hash',$hash)->latest()->first();
   if($existing)return ['status'=>'candidate_ready','reused'=>true];
   $latest=SupplierLeadModel::where('supplier_id',$supplier->id)->latest()->first();
   if($latest&&collect($rows)->where('label_known_at','>',$latest->training_cutoff->toDateString())->count()<5)return ['status'=>'insufficient_new_outcomes','samples'=>count($rows)];
   if(count($rows)<config('supplier_intelligence.minimum_training_orders'))return ['status'=>'insufficient_data','samples'=>count($rows),'required'=>config('supplier_intelligence.minimum_training_orders')];
   $anchor=$history->capture($supplier,$all);
   $dataset=DB::transaction(function()use($rows,$cutoff,$supplier,$anchor){
    $d=AnalyticsDataset::create(['company_id'=>$supplier->company_id,'version'=>(string)Str::uuid(),'name'=>'supplier_lead_v1','date_from'=>collect($rows)->min('ordered_at'),'date_to'=>$cutoff,'feature_definitions'=>['version'=>'supplier-history-v1','features'=>['original_promised_days','original_line_count','order_month'],'target'=>'Completed physical delivery days; available labels strictly precede validation decisions. Quality, current categories and current routes are not historical predictor features.'],'row_count'=>1,'labelled_count'=>count($rows),'quality_status'=>'intelligence_frozen','created_by'=>Auth::id()]);
    AnalyticsDatasetRow::create(['company_id'=>$supplier->company_id,'analytics_dataset_id'=>$d->id,'analytics_snapshot_id'=>$anchor->id,'values'=>['supplier_id'=>$supplier->id,'cutoff'=>$cutoff,'rows'=>$rows]]);return $d;
   });
   $context=['supplier_id'=>$supplier->id,'dataset_version'=>$dataset->version,'input_hash'=>$hash,'cutoff'=>$cutoff,'engine_sha256'=>hash_file('sha256',base_path('ml/supplier_forecast.py'))];
   $audit=app(AnalyticsDataService::class);$audit->audit('supplier_intelligence.training_started',$context);
   try{$result=Cache::lock('inventory-intelligence-local-training',120)->block(2,fn()=>app(LocalSupplierLeadProvider::class)->run(['rows'=>$rows,'cutoff'=>$cutoff,'minimum'=>config('supplier_intelligence.minimum_training_orders'),'incumbent'=>$active?->artifact]));}
   catch(\Throwable $e){$audit->audit('supplier_intelligence.training_failed',$context+['reason'=>class_basename($e)]);throw $e;}
   $audit->audit('supplier_intelligence.training_finished',$context+['status'=>$result['status'],'samples'=>$result['samples']??0]);
   if($result['status']!=='ready')return $result;
   DB::transaction(function()use($result,$supplier,$dataset,$hash,$active){
    Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
    SupplierLeadModel::where('supplier_id',$supplier->id)->where('status','candidate')->update(['status'=>'archived']);
    $m=SupplierLeadModel::create(['supplier_id'=>$supplier->id,'analytics_dataset_id'=>$dataset->id,'version'=>(string)Str::uuid(),'feature_version'=>'supplier-history-v1','horizon'=>0,'algorithm'=>'supplier_ridge','status'=>'candidate','training_cutoff'=>$result['artifact']['training_cutoff'],'artifact'=>$result['artifact'],'artifact_hash'=>InventoryIntelligenceService::artifactHash($result['artifact']),'metrics'=>$result['comparison']['challenger'],'comparison'=>$result['comparison'],'quality'=>['samples'=>$result['samples']],'review'=>['production_version'=>$active?->version,'input_hash'=>$hash,'validation_windows'=>$result['windows'],'validation_training_order_ids'=>$result['validation_training_order_ids'],'created_by'=>Auth::id()]]);
    if($this->gates($m,$active)['eligible'])app(BusinessEventService::class)->record('supplier_model.review',$supplier,$supplier->name,['version'=>$m->version,'samples'=>$result['samples']],'supplier-candidate:'.$m->version);
   });return ['status'=>'candidate_ready','samples'=>$result['samples'],'reused'=>false];
  });
 }
 public function decide(int $id,string $action,array $input):array {
  $s=app(SupplierIntelligenceService::class);$s->authorize(true);
  $data=validator($input,['reason'=>'required|string|min:3|max:1000','expected_production_version'=>'present|nullable|string|max:50'])->validate();$model=SupplierLeadModel::findOrFail($id);
  return DB::transaction(function()use($s,$model,$action,$data){
   $supplier=Supplier::whereKey($model->supplier_id)->lockForUpdate()->firstOrFail();$m=$model->fresh();$active=$s->active($supplier->id);
   if($active?->id===$m->id)return ['status'=>'active','reused'=>true];
   abort_unless($active?->version===$data['expected_production_version'],409,'Production changed. Review the latest supplier evidence.');
   app(InventoryLearningService::class)->checkArtifact($m);
   if($action==='promote'){$gate=$this->gates($m,$active);abort_unless($m->status==='candidate'&&$gate['eligible'],422,'Candidate cannot be promoted: '.implode(', ',$gate['reasons']));}
   else{$last=InventoryModelDecision::where('domain','supplier_lead')->where('supplier_id',$supplier->id)->latest()->first();abort_unless($m->status==='superseded'&&$last&&(int)$last->to_model_id===$active?->id&&(int)$last->from_model_id===$m->id,422,'Only the immediate previous model can be restored.');$gate=['rollback_of'=>$last->id];}
   $active?->update(['status'=>'superseded']);$m->update(['status'=>'active']);
   InventoryModelDecision::create(['domain'=>'supplier_lead','supplier_id'=>$supplier->id,'from_model_id'=>$active?->id,'to_model_id'=>$m->id,'horizon'=>0,'action'=>$action,'reason'=>$data['reason'],'user_id'=>Auth::id(),'evidence'=>$gate]);
   return ['status'=>'active','reused'=>false];
  });
 }
}
