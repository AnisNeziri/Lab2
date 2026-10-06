<?php
namespace App\Services;
use App\Models\{Product,AnalyticsSnapshot,AnalyticsPrediction,InventoryForecastModel,InventoryModelDecision,InventoryIntelligenceAlert,InventoryRecommendation,ProcurementAward,PurchaseOrder};
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable as Date;

final class IntelligencePerformanceService {
    public function evaluate(?float $deadline=null):int {
        app(InventoryIntelligenceService::class)->authorize();$count=0;
        $mature=AnalyticsPrediction::where('model_key','inventory-demand-v1')->whereNull('evaluated_at')->where(function($q){
            foreach([7,30,90] as $h)$q->orWhere(fn($x)=>$x->where('value->horizon',$h)->whereDate('generated_at','<=',today()->subDays($h)));
        });
        foreach($mature->orderBy('id')->limit(200)->get() as $p){
            if($deadline!==null&&microtime(true)>=$deadline)break;
            $daily=$p->value['daily']??[];$first=$daily[0]['date']??null;$last=last($daily)['date']??null;$h=(int)($p->value['horizon']??0);
            if(!$last||$last>=today()->toDateString())continue;
            $valid=in_array($h,[7,30,90],true)&&count($daily)===$h;
            $trainingCutoff=$p->value['training_cutoff']??null;$forecastCutoff=$p->value['cutoff']??null;
            if(!$trainingCutoff||!$forecastCutoff||$first<=$trainingCutoff||$first<=$forecastCutoff||$p->generated_at->toDateString()>$first)$valid=false;
            foreach($daily as $i=>$day)if($first&&$day['date']!==Date::parse($first)->addDays($i)->toDateString())$valid=false;
            if(!$valid){$p->update(['evaluated_at'=>now(),'evaluation'=>['version'=>2,'eligible'=>false,'status'=>'invalid_period','observed_days'=>0,'excluded_days'=>$h]]);continue;}
            $product=Product::find($p->entity_id);if(!$product)continue;
            app(IntelligenceObservationService::class)->collectProduct($product,$first,$last);
            $observations=AnalyticsSnapshot::where('entity_type','demand_observation')->where('entity_id',$product->id)->whereDate('snapshot_date','>=',$first)->whereDate('snapshot_date','<=',$last)->get()->keyBy(fn($s)=>$s->snapshot_date->toDateString());
            $baseline=collect($p->value['baseline_daily']??[])->keyBy('date');$rows=[];$excluded=[];$qualified=0;
            foreach($daily as $day){$s=$observations->get($day['date']);$f=$s?->facts??[];
                if(!$s||($f['unit']??null)!==($p->value['unit']??null)||!($f['complete']??false)||($f['stockout']??false)||!isset($f['demand'])){$excluded[]=['date'=>$day['date'],'reason'=>$f['quality']??'missing_observation'];continue;}
                $actual=(float)$f['demand'];$error=$day['quantity']-$actual;$b=$baseline->get($day['date']);
                $rows[]=['date'=>$day['date'],'observation_id'=>$s->id,'actual'=>$actual,'predicted'=>$day['quantity'],'error'=>$error,'absolute_error'=>abs($error),'baseline'=>$b['quantity']??null];
                if($f['potentially_censored']??true)$qualified++;
            }
            $actual=collect($rows)->sum('actual');$absolute=collect($rows)->sum('absolute_error');$signed=collect($rows)->sum('error');$n=count($rows);
            $baseComplete=$n&&collect($rows)->whereNotNull('baseline')->count()===$n;
            $baseAbs=$baseComplete?collect($rows)->sum(fn($x)=>abs($x['baseline']-$x['actual'])):null;
            $evaluation=['version'=>2,'source'=>'closed_business_observations','status'=>$excluded?'incomplete':'completed','eligible'=>!$excluded,'from'=>$first,'to'=>$last,'horizon'=>$h,
                'model_version'=>$p->model_version,'training_cutoff'=>$p->value['training_cutoff']??null,'observed_days'=>$n,'excluded_days'=>count($excluded),'excluded'=>$excluded,
                'qualified_availability_days'=>$qualified,'qualification'=>'Fulfilled sales only. Sampled availability cannot prove absence of intraday lost demand.',
                'absolute_error'=>$absolute,'signed_error'=>$signed,'mae'=>$n?$absolute/$n:null,'wape'=>$actual>0?100*$absolute/$actual:null,'bias'=>$n?$signed/$n:null,
                'baseline_mae'=>$baseComplete?$baseAbs/$n:null,'baseline_absolute_error'=>$baseAbs,'actual_total'=>$actual,'predicted_total'=>collect($daily)->sum('quantity')];
            DB::transaction(function()use($p,$evaluation,$actual,$rows,&$count){$locked=AnalyticsPrediction::whereKey($p->id)->lockForUpdate()->firstOrFail();if($locked->evaluated_at)return;
                $locked->update(['evaluated_at'=>now(),'actual_value'=>['observed_demand'=>$actual,'days'=>$rows],'evaluation'=>$evaluation]);$count++;});
        }return $count;
    }
    public function independent($predictions):array {
        $end=null;$result=[];
        foreach($predictions->sortBy(fn($p)=>$p->value['daily'][0]['date']??'') as $p){$e=$p->evaluation;
            if(!($e['eligible']??false)||($e['version']??1)!==2)continue;
            if($end&&$e['from']<=$end)continue;$result[]=$p;$end=$e['to'];
        }return $result;
    }
    public function monitor(Product $product):array {
        app(InventoryIntelligenceService::class)->product($product->id);$signals=[];
        $history=collect(app(InventoryIntelligenceDatasetAdapter::class)->history($product,today()->subDay()->toDateString())['series'])->take(-42)->values();
        $recent=$history->take(-14);$missing=$recent->whereNull('demand')->count();
        if($recent->count()>=7&&$missing>=3)$signals['0:data_quality']=['horizon'=>0,'code'=>'data_quality','evidence'=>['period_days'=>$recent->count(),'unusable_days'=>$missing,'stockout_days'=>$recent->where('censored',true)->count(),'threshold_days'=>3,'reason'=>'missing_or_censored_observations']];
        $prior=$history->slice(0,-14)->whereNotNull('demand');$week1=$history->slice(-14,7)->whereNotNull('demand');$week2=$history->take(-7)->whereNotNull('demand');
        if($prior->count()>=21&&$week1->count()>=6&&$week2->count()>=6&&$prior->avg('demand')>=1){
            $mean=$prior->avg('demand');$a=$week1->avg('demand')/$mean-1;$b=$week2->avg('demand')/$mean-1;
            if(abs($a)>=.5&&abs($b)>=.5&&$a*$b>0)$signals['0:demand_shift']=['horizon'=>0,'code'=>'demand_shift','evidence'=>['previous_daily_mean'=>$mean,'first_week_change_percent'=>100*$a,'second_week_change_percent'=>100*$b,'threshold_percent'=>50]];
        }
        foreach(InventoryForecastModel::where('product_id',$product->id)->where('status','active')->get() as $model){
            if(app(InventoryLearningService::class)->unit($model)!==$product->unit)continue;
            $rows=$this->independent(AnalyticsPrediction::where('entity_id',$product->id)->where('entity_type','product')->where('model_version',$model->version)->whereNotNull('evaluated_at')->get());
            $rows=array_slice($rows,-config('inventory_intelligence.drift_min_windows'));if(count($rows)<config('inventory_intelligence.drift_min_windows'))continue;
            $actual=collect($rows)->sum(fn($r)=>$r->evaluation['actual_total']);if($actual<10)continue;
            $abs=collect($rows)->sum(fn($r)=>$r->evaluation['absolute_error']);$signed=collect($rows)->sum(fn($r)=>$r->evaluation['signed_error']);
            $wape=100*$abs/$actual;$bias=100*$signed/$actual;
            $bad=collect($rows)->every(fn($r)=>$r->evaluation['baseline_mae']!==null&&$r->evaluation['mae']>max(.001,$r->evaluation['baseline_mae'])*config('inventory_intelligence.drift_baseline_ratio'));
            $evidence=['windows'=>count($rows),'prediction_ids'=>array_map(fn($r)=>$r->id,$rows),'wape'=>$wape,'signed_bias_percent'=>$bias,'model_version'=>$model->version,'minimum_windows'=>config('inventory_intelligence.drift_min_windows')];
            if($bad&&$wape>=config('inventory_intelligence.drift_wape_percent'))$signals[$model->horizon.':accuracy_degraded']=['horizon'=>$model->horizon,'code'=>'accuracy_degraded','evidence'=>$evidence+['threshold_wape'=>config('inventory_intelligence.drift_wape_percent'),'baseline_ratio'=>config('inventory_intelligence.drift_baseline_ratio')]];
            $sameSign=collect($rows)->every(fn($r)=>$signed>0?$r->evaluation['signed_error']>0:$r->evaluation['signed_error']<0);
            if($sameSign&&abs($bias)>=config('inventory_intelligence.drift_bias_percent'))$signals[$model->horizon.':persistent_bias']=['horizon'=>$model->horizon,'code'=>'persistent_bias','evidence'=>$evidence+['direction'=>$signed>0?'overforecast':'underforecast','threshold_percent'=>config('inventory_intelligence.drift_bias_percent')]];
        }
        DB::transaction(function()use($signals,$product){
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            foreach($signals as $s){$alert=InventoryIntelligenceAlert::firstOrNew(['company_id'=>$product->company_id,'product_id'=>$product->id,'horizon'=>$s['horizon'],'code'=>$s['code']]);
                $new=!$alert->exists||$alert->status==='resolved';$episode=$alert->exists?(int)$alert->episode+($new?1:0):1;
                $alert->fill(['status'=>'open','episode'=>$episode,'evidence'=>$s['evidence'],'opened_at'=>$new?now():$alert->opened_at,'resolved_at'=>null])->save();
                if($new)app(BusinessEventService::class)->record($s['code']==='data_quality'?'intelligence_data_quality_problem':'forecast_accuracy_degraded',$alert,$product->name,['horizon'=>$s['horizon'],'code'=>$s['code'],'evidence'=>$s['evidence']],'intelligence-alert:'.$alert->id.':'.$episode);
            }
            foreach(InventoryIntelligenceAlert::where('product_id',$product->id)->where('status','open')->get() as $a)if(!isset($signals[$a->horizon.':'.$a->code]))$a->update(['status'=>'resolved','resolved_at'=>now()]);
        });
        return InventoryIntelligenceAlert::where('product_id',$product->id)->where('status','open')->get()->toArray();
    }
    public function outcomes(Product $product):array {
        $result=[];
        foreach(InventoryRecommendation::with('purchaseRequest','prediction')->whereNull('planning_key')->where('product_id',$product->id)->whereIn('status',['accepted','adjusted','dismissed','superseded'])->latest('id')->limit(20)->get() as $r){
            $poIds=$r->purchase_request_id?ProcurementAward::whereHas('requestItem',fn($q)=>$q->where('purchase_request_id',$r->purchase_request_id))->whereNotNull('purchase_order_id')->pluck('purchase_order_id'):collect();
            $pos=PurchaseOrder::whereIn('id',$poIds)->get();$before=$r->explanation['available']??null;
            $receipts=\App\Models\GoodsReceipt::with(['items'=>fn($q)=>$q->where('product_id',$product->id)])->whereIn('purchase_order_id',$poIds)->where('status','posted')->get();
            $receiptEvidence=$receipts->map(function($receipt)use($pos,$r){$po=$pos->firstWhere('id',$receipt->purchase_order_id);
                $sameUnit=$receipt->items->where('inventory_unit',$r->prediction?->value['unit']);
                return ['id'=>$receipt->id,'reference'=>$receipt->receipt_number,'purchase_order_id'=>$receipt->purchase_order_id,'received_at'=>$receipt->received_at->toIso8601String(),
                    'accepted_base_quantity'=>$sameUnit->count()===$receipt->items->count()?round($sameUnit->sum('accepted_base_quantity'),3):null,
                    'expected_at'=>$po?->expected_at?->toDateString(),'delay_days'=>$po?->expected_at?(int)$po->expected_at->startOfDay()->diffInDays($receipt->received_at->copy()->startOfDay(),false):null];})->all();
            $latest=AnalyticsSnapshot::where('entity_type','demand_observation')->where('entity_id',$product->id)->whereDate('snapshot_date','>',$r->created_at->toDateString())->latest('snapshot_date')->first();
            $after=($latest?->facts['unit']??null)===($r->prediction?->value['unit']??null)?($latest?->facts['available']??null):null;
            $orders=$pos->map(fn($p)=>['id'=>$p->id,'reference'=>$p->po_number,'status'=>$p->status,'expected_at'=>$p->expected_at?->toDateString(),'received_at'=>$p->received_at?->toDateString(),'late'=>$p->expected_at&&($p->received_at?$p->received_at->toDateString()>$p->expected_at->toDateString():$p->expected_at->isPast()&&!in_array($p->status,['received','completed','cancelled']))])->all();
            $realized=AnalyticsSnapshot::where('entity_type','demand_observation')->where('entity_id',$product->id)->where('facts->unit',$r->prediction?->value['unit'])->whereDate('snapshot_date','>',$r->created_at)->whereDate('snapshot_date','<=',substr(last($r->prediction?->value['daily']??[])['date']??today()->subDay()->toDateString(),0,10))->get();
            $outcome=['decision'=>$r->status==='superseded'?'ignored':$r->status,'purchase_request_id'=>$r->purchase_request_id,'purchase_request_status'=>$r->purchaseRequest?->status,'purchase_orders'=>$orders,'before_available'=>$before,'after_available'=>$after,'after_observation_id'=>$latest?->id,
                'receipts'=>$receiptEvidence,'observed_stockout_days'=>$realized->filter(fn($s)=>$s->facts['stockout']??false)->count(),'outcome_observation_days'=>$realized->count(),
                'recommended_base_quantity'=>$r->feedback['recommended_base_quantity']??$r->explanation['base_quantity']??null,'action_base_quantity'=>$r->feedback['base_quantity']??null,
                'action_difference'=>isset($r->feedback['base_quantity'],$r->feedback['recommended_base_quantity'])?round($r->feedback['base_quantity']-$r->feedback['recommended_base_quantity'],3):null,
                'stock_change'=>$before!==null&&$after!==null?round($after-$before,3):null,'forecast_evaluation'=>$r->prediction?->evaluation,'supplier_delays'=>collect($orders)->where('late',true)->count(),
                'causal_claim'=>false,'qualification'=>'Stock change is observational, not proof that this recommendation caused improvement. Purchases, other sales, user choices and missing observations affect outcomes.'];
            if(!$r->outcome||InventoryIntelligenceService::artifactHash($outcome)!==InventoryIntelligenceService::artifactHash($r->outcome)){
                // Append-only, knowledge-time evidence for future point-in-time datasets.
                app(AnalyticsDataService::class)->audit('inventory_intelligence.outcome_observed',['product_id'=>$product->id,'recommendation_id'=>$r->id,'available_for_features_after'=>now()->toIso8601String(),'outcome'=>$outcome]);
                $r->update(['outcome'=>$outcome,'outcome_updated_at'=>now()]);
            }$result[]=['id'=>$r->id]+$outcome;
        }return $result;
    }
    public function report(int $id,int $h=30,?string $version=null):array {
        $p=app(InventoryIntelligenceService::class)->product($id);abort_unless(in_array($h,[7,30,90],true),422);
        $learning=app(InventoryLearningService::class);$active=$learning->active($p,$h);
        $models=InventoryForecastModel::where('product_id',$id)->where('horizon',$h)->latest('id')->limit(20)->get();
        $evaluations=AnalyticsPrediction::where('entity_type','product')->where('entity_id',$id)->where('model_key','inventory-demand-v1')->where('value->horizon',$h)->where('value->unit',$p->unit)->whereNotNull('evaluated_at')->when($version,fn($q)=>$q->where('model_version',$version))->latest('id')->limit(100)->get();
        $scored=$this->independent($evaluations);$n=collect($scored)->sum(fn($r)=>$r->evaluation['observed_days']);$actual=collect($scored)->sum(fn($r)=>$r->evaluation['actual_total']);$absolute=collect($scored)->sum(fn($r)=>$r->evaluation['absolute_error']);
        $observations=AnalyticsSnapshot::where('entity_type','demand_observation')->where('entity_id',$id)->where('facts->unit',$p->unit)->whereDate('snapshot_date','>=',today()->subDays(90))->orderBy('snapshot_date')->get();
        $lastDecision=InventoryModelDecision::where('product_id',$id)->where('horizon',$h)->latest('id')->first();
        $rollback=$lastDecision&&(int)$lastDecision->to_model_id===$active?->id?InventoryForecastModel::find($lastDecision->from_model_id):null;
        $alerts=InventoryIntelligenceAlert::where('product_id',$id)->where('status','open')->get();
        $usable=$observations->filter(fn($s)=>$s->facts['complete']??false);$latestUsable=$usable->last()?->snapshot_date;
        $stale=!$latestUsable||$latestUsable->lt(today()->subDays(config('inventory_intelligence.feature_stale_days')));
        $healthPeriods=$this->independent($evaluations->where('model_version',$version??$active?->version));
        $health=['state'=>$alerts->isNotEmpty()?'warning':(($stale||count($healthPeriods)<config('inventory_intelligence.drift_min_windows'))?'insufficient_evidence':'healthy'),
            'stale_features'=>$stale,'latest_usable_date'=>$latestUsable?->toDateString(),'independent_periods'=>count($healthPeriods),'required_periods'=>config('inventory_intelligence.drift_min_windows'),
            'stockout_days'=>$observations->filter(fn($s)=>$s->facts['stockout']??false)->count(),'unusable_days'=>$observations->count()-$usable->count()];
        $pending=AnalyticsPrediction::where('entity_type','product')->where('entity_id',$id)->where('model_key','inventory-demand-v1')->where('value->horizon',$h)->where('value->unit',$p->unit)->whereNull('evaluated_at')->when($version,fn($q)=>$q->where('model_version',$version))->latest('id')->limit(30)->get();
        $training=\App\Models\ActivityLog::whereIn('action',['inventory_intelligence.training_started','inventory_intelligence.training_finished','inventory_intelligence.training_failed'])->where('new_value->product_id',$id)->latest('id')->limit(60)->get()->unique(fn($a)=>$a->new_value['attempt'])->values();
        return ['product'=>$p->only('id','name','unit'),'horizon'=>$h,'production_version'=>$active?->version,
            'health'=>$health,'training_history'=>$training->map(fn($a)=>['at'=>$a->created_at->toIso8601String(),'status'=>$a->new_value['status']??($a->created_at->lt(now()->subMinutes(3))?'interrupted':'running'),'details'=>$a->new_value])->all(),
            'pending'=>$pending->map(fn($r)=>['id'=>$r->id,'from'=>$r->value['daily'][0]['date']??null,'to'=>last($r->value['daily']??[])['date']??null,'model_version'=>$r->model_version,'status'=>(last($r->value['daily']??[])['date']??'9999')<today()->toDateString()?'awaiting_evaluation':'pending'])->all(),
            'models'=>$models->map(fn($m)=>$m->makeHidden('artifact')->toArray()+['inventory_unit'=>$learning->unit($m),'gate'=>$learning->gates($m,$active)])->all(),
            'rollback_target'=>$rollback?->only('id','version','algorithm'),'decisions'=>InventoryModelDecision::where('product_id',$id)->where('horizon',$h)->latest('id')->limit(20)->get(),
            'summary'=>['completed_windows'=>count($scored),'mae'=>$n?$absolute/$n:null,'wape'=>$actual>0?100*$absolute/$actual:null,'bias'=>$n?collect($scored)->sum(fn($r)=>$r->evaluation['signed_error'])/$n:null,'actual'=>$actual,'status'=>$n?'measured_business_results':'insufficient_real_data'],
            'evaluations'=>$evaluations->map(fn($r)=>['id'=>$r->id,'model_version'=>$r->model_version,'evaluation'=>$r->evaluation,'actual'=>$r->actual_value])->all(),
            'coverage'=>['captured_days'=>$observations->count(),'usable_days'=>$observations->filter(fn($s)=>$s->facts['complete']??false)->count(),'stockout_days'=>$observations->filter(fn($s)=>$s->facts['stockout']??false)->count(),'latest'=>$observations->last()?->snapshot_date?->toDateString(),'availability'=>'sampled_not_continuous'],
            'alerts'=>$alerts,'retraining'=>$learning->shouldRetrain($p),
            'outcomes'=>InventoryRecommendation::where('product_id',$id)->whereNotNull('outcome')->latest('id')->limit(20)->get(['id','outcome','outcome_updated_at']),
            'qualification'=>'Measured fulfilled-sales outcomes, not lost-sales estimates. Overlapping windows excluded from summary. Candidate holdout scores are not future production accuracy.'];
    }
}
