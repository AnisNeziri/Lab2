<?php
namespace App\Services;
use App\Contracts\DemandForecastProvider;
use App\Models\{Product,AnalyticsSnapshot,AnalyticsDatasetRow,InventoryForecastModel,InventoryModelDecision,InventoryIntelligenceAlert};
use Illuminate\Support\Facades\{Auth,DB,Cache};
use Illuminate\Support\Str;

final class InventoryLearningService {
    public function __construct(private DemandForecastProvider $provider,private InventoryIntelligenceDatasetAdapter $adapter){}
    public function active(Product $p,int $h):?InventoryForecastModel {
        return InventoryForecastModel::where('product_id',$p->id)->where('horizon',$h)->where('status','active')->first();
    }
    public function unit(InventoryForecastModel $m):?string {
        return $m->review['unit']??AnalyticsDatasetRow::where('analytics_dataset_id',$m->analytics_dataset_id)->value('values')['unit']??null;
    }
    public function checkArtifact(InventoryForecastModel $m):void {
        abort_unless(hash_equals($m->artifact_hash,InventoryIntelligenceService::artifactHash($m->artifact)),422,'Model artifact integrity failed.');
    }
    public function gates(InventoryForecastModel $m,?InventoryForecastModel $production):array {
        $q=$m->quality;$e=$m->metrics;$b=$m->comparison['seasonal_mean']??null;$previous=$m->comparison['incumbent']??null;$reasons=[];
        if($this->unit($m)!==Product::whereKey($m->product_id)->value('unit'))$reasons[]='unit_changed';
        $coverage=($q['recent_observed_days']??0)/max(1,$q['recent_calendar_days']??90);
        if(($q['observed_days']??0)<config('inventory_intelligence.promotion_min_days'))$reasons[]='not_enough_observations';
        if($coverage<config('inventory_intelligence.promotion_min_coverage'))$reasons[]='low_history_coverage';
        if(!$e||!$b||($e['blocks']??0)<config('inventory_intelligence.promotion_min_windows')||($e['observations']??0)<14)$reasons[]='not_enough_validation_windows';
        if($e&&$b&&($e['mae']>$b['mae']+0.000001||$e['horizon_mae']>$b['horizon_mae']+0.000001))$reasons[]='worse_than_baseline';
        if(($m->review['production_version']??null)!==$production?->version)$reasons[]='production_changed';
        if($production&&$this->unit($production)===$this->unit($m)&&(!$previous||!$e||$e['mae']>$previous['mae']+0.000001||$e['horizon_mae']>$previous['horizon_mae']+0.000001))$reasons[]='production_not_beaten';
        $minimum=(float)config('inventory_intelligence.promotion_min_improvement_percent');
        if($production&&$previous&&$e&&$minimum>0&&($previous['mae']<=0||$e['mae']>$previous['mae']*(1-$minimum/100)+0.000001))$reasons[]='insufficient_improvement';
        return ['eligible'=>!$reasons,'reasons'=>$reasons,'recent_coverage'=>round(100*$coverage,2),'requirements'=>['observed_days'=>config('inventory_intelligence.promotion_min_days'),'windows'=>config('inventory_intelligence.promotion_min_windows'),'coverage'=>100*config('inventory_intelligence.promotion_min_coverage')]];
    }
    public function prepare(int $id):array {
        $v1=app(InventoryIntelligenceService::class);$v1->authorize(true);$product=$v1->product($id);
        return Cache::lock('intelligence-candidate:'.$product->company_id.':'.$id,120)->block(2,function()use($product,$v1){
            $cutoff=today()->subDay()->toDateString();
            app(IntelligenceObservationService::class)->collectProduct($product,today()->subDays(90)->toDateString(),$cutoff);
            $active=InventoryForecastModel::where('product_id',$product->id)->where('status','active')->get()->keyBy('horizon');
            $models=[];foreach($active as $h=>$m)if($this->unit($m)===$product->unit){$this->checkArtifact($m);$models[(string)$h]=$m->artifact;}
            $existing=InventoryForecastModel::where('product_id',$product->id)->where('status','candidate')->whereDate('training_cutoff',$cutoff)->where('review->unit',$product->unit)->get();
            if($existing->isNotEmpty()&&$existing->every(fn($m)=>($m->review['production_version']??null)===$active->get($m->horizon)?->version)){
                $v1->refreshProduction($product->id);return ['status'=>'candidate_ready','reused'=>true];
            }
            $history=$this->adapter->history($product,$cutoff);$history['mode']='candidate';
            $latest=InventoryForecastModel::where('product_id',$product->id)->latest('id')->first();
            if($latest&&$this->unit($latest)===$product->unit&&collect($history['series'])->where('date','>',$latest->training_cutoff->toDateString())->whereNotNull('demand')->count()<config('inventory_intelligence.minimum_new_days')){
                $v1->refreshProduction($product->id);return ['status'=>'production_refreshed','reused'=>true,'quality'=>$latest->quality];
            }
            $hash=InventoryIntelligenceService::artifactHash(['history'=>$history,'champions'=>$models]);
            $previous=\App\Models\ActivityLog::where('action','inventory_intelligence.training_finished')->where('new_value->product_id',$product->id)->where('new_value->input_hash',$hash)->latest('id')->first();
            if(($previous?->new_value['status']??null)==='insufficient_data')return ['status'=>'insufficient_data','quality'=>$previous->new_value['quality'],'reused'=>true];
            $anchor=AnalyticsSnapshot::where('entity_type','product')->where('entity_id',$product->id)->where('warehouse_id',0)->latest('snapshot_date')->first();
            $dataset=$anchor?$this->adapter->freeze($product,$history,$anchor):null;
            $result=$this->trainCandidate($product,$history,$models,$dataset);
            if($result['status']==='insufficient_data')return $result;
            abort_unless($dataset,422,'No authoritative stock observation exists for this training dataset.');
            DB::transaction(function()use($result,$product,$active,$dataset){
                Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                foreach($result['results'] as $r){
                    InventoryForecastModel::where('product_id',$product->id)->where('horizon',$r['horizon'])->where('status','candidate')->update(['status'=>'archived']);
                    $model=InventoryForecastModel::create(['company_id'=>$product->company_id,'product_id'=>$product->id,'analytics_dataset_id'=>$dataset->id,
                        'version'=>(string)Str::uuid(),'feature_version'=>InventoryIntelligenceDatasetAdapter::VERSION,'horizon'=>$r['horizon'],'algorithm'=>$r['algorithm'],'status'=>'candidate',
                        'training_cutoff'=>$r['artifact']['training_cutoff'],'artifact'=>$r['artifact'],'artifact_hash'=>InventoryIntelligenceService::artifactHash($r['artifact']),
                        'metrics'=>$r['evaluation'],'comparison'=>$r['comparison'],'quality'=>$result['quality'],
                        'review'=>['unit'=>$product->unit,'production_version'=>$active->get($r['horizon'])?->version,'origin'=>'business_history_holdout','created_by'=>Auth::id()]]);
                    $gate=$this->gates($model,$active->get($r['horizon']));
                    if($gate['eligible'])app(BusinessEventService::class)->record('model_candidate_ready',$model,$product->name,['horizon'=>$model->horizon,'algorithm'=>$model->algorithm,'coverage'=>$gate['recent_coverage']],'candidate:'.$model->version);
                }
            });
            app(AnalyticsDataService::class)->audit('inventory_intelligence.candidate_prepared',['product_id'=>$product->id,'dataset_version'=>$dataset->version]);
            $v1->refreshProduction($product->id);
            return ['status'=>'candidate_ready','quality'=>$result['quality'],'reused'=>false];
        });
    }
    private function trainCandidate(Product $product,array $history,array $models,?\App\Models\AnalyticsDataset $dataset):array {
        $audit=app(AnalyticsDataService::class);$attempt=(string)Str::uuid();
        $context=['product_id'=>$product->id,'attempt'=>$attempt,'cutoff'=>$history['cutoff'],'dataset_id'=>$dataset?->id,'dataset_version'=>$dataset?->version,
            'input_hash'=>InventoryIntelligenceService::artifactHash(['history'=>$history,'champions'=>$models]),
            'configuration'=>['feature_version'=>InventoryIntelligenceDatasetAdapter::VERSION,'engine_sha256'=>hash_file('sha256',base_path('ml/forecast.py')),'history_limit'=>730,'timeout_seconds'=>config('inventory_intelligence.timeout_seconds'),'validation'=>'time_ordered_recursive_holdout','promotion_improvement_percent'=>config('inventory_intelligence.promotion_min_improvement_percent')]];
        $audit->audit('inventory_intelligence.training_started',$context);
        try{
            // One local training process at a time across companies; inference has a separate path.
            $result=Cache::lock('inventory-intelligence-local-training',120)->block(2,fn()=>$this->provider->train($history,$models));
            $audit->audit('inventory_intelligence.training_finished',$context+['status'=>$result['status'],'quality'=>$result['quality']??[]]);
            return $result;
        }catch(\Throwable $error){
            $audit->audit('inventory_intelligence.training_failed',$context+['status'=>'failed','reason'=>class_basename($error),'message'=>$error instanceof \App\Exceptions\ForecastUnavailableException?$error->getMessage():'Candidate training did not finish. Existing production forecasts are preserved.']);
            try{app(InventoryIntelligenceService::class)->refreshProduction($product->id);}catch(\Throwable){}
            throw $error;
        }
    }
    public function decide(int $id,string $action,array $input):array {
        app(InventoryIntelligenceService::class)->authorize(true);
        $data=validator($input,['reason'=>'required|string|min:3|max:1000','expected_production_version'=>'present|nullable|string|max:50'])->validate();
        $model=InventoryForecastModel::where('domain','inventory_demand')->findOrFail($id);
        $result=DB::transaction(function()use($model,$action,$data){
            $p=Product::whereKey($model->product_id)->lockForUpdate()->firstOrFail();$m=$model->fresh();$current=$this->active($p,$m->horizon);
            if($current?->id===$m->id)return ['status'=>'active','reused'=>true];
            abort_unless($current?->version===$data['expected_production_version'],409,'Production model changed. Review again.');
            $this->checkArtifact($m);abort_unless($this->unit($m)===$p->unit,422,'The product unit changed. Prepare a new candidate.');
            if($action==='promote'){
                abort_unless($m->status==='candidate',422,'Only a pending candidate can be promoted.');
                $gate=$this->gates($m,$current);abort_unless($gate['eligible'],422,'Candidate is not eligible: '.implode(', ',$gate['reasons']));
            }else{
                $last=InventoryModelDecision::where('product_id',$p->id)->where('horizon',$m->horizon)->latest('id')->first();
                abort_unless($m->status==='superseded'&&$last&&(int)$last->to_model_id===$current?->id&&(int)$last->from_model_id===$m->id,422,'Rollback is limited to the immediately previous production version.');
                $gate=['rollback_of'=>$last->id];
            }
            if($current)$current->update(['status'=>'superseded']);$m->update(['status'=>'active']);
            InventoryModelDecision::create(['company_id'=>$p->company_id,'product_id'=>$p->id,'from_model_id'=>$current?->id,'to_model_id'=>$m->id,'horizon'=>$m->horizon,'action'=>$action,'reason'=>$data['reason'],'user_id'=>Auth::id(),'evidence'=>$gate]);
            // Historical values remain frozen. Superseded advice cannot be acted upon.
            \App\Models\InventoryRecommendation::where('product_id',$p->id)->whereIn('status',['open','viewed'])->whereHas('prediction',fn($q)=>$q->where('value->horizon',$m->horizon))->update(['status'=>'superseded']);
            app(AnalyticsDataService::class)->audit('inventory_intelligence.model_'.$action,['product_id'=>$p->id,'from'=>$current?->version,'to'=>$m->version,'reason'=>$data['reason']]);
            return ['status'=>'active','reused'=>false];
        });
        try{app(InventoryIntelligenceService::class)->refreshProduction($model->product_id);}catch(\App\Exceptions\ForecastUnavailableException $e){$result['warning']=$e->getMessage();}
        return $result;
    }
    public function shouldRetrain(Product $p):array {
        $latest=InventoryForecastModel::where('product_id',$p->id)->latest('id')->first();
        $history=$this->adapter->history($p,today()->subDay()->toDateString());
        $new=collect($history['series'])->where('date','>',$latest?->training_cutoff?->toDateString()??'0000-01-01')->whereNotNull('demand')->count();
        $elapsed=!$latest||$latest->created_at->lte(now()->subDays(config('inventory_intelligence.retrain_after_days')));
        $degraded=InventoryIntelligenceAlert::where('product_id',$p->id)->where('status','open')->whereIn('code',['accuracy_degraded','persistent_bias','demand_shift'])->exists();
        $stale=!$latest||$latest->created_at->lte(now()->subDays(config('inventory_intelligence.stale_model_days')));
        return ['justified'=>$elapsed&&$new>=config('inventory_intelligence.minimum_new_days')&&($degraded||$stale),'new_observed_days'=>$new,'elapsed'=>$elapsed,'degraded'=>$degraded,'stale'=>$stale,'automatic_enabled'=>(bool)config('inventory_intelligence.scheduled_training')];
    }
}
