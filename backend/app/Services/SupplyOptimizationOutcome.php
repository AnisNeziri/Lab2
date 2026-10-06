<?php
namespace App\Services;
use App\Models\{SupplyOptimizationPlan,AnalyticsSnapshot,Product,DecisionLearningRecord};
use Carbon\CarbonImmutable as Date;
/** Reuses V10's sampled outcome definitions; never manufactures a counterfactual. */
class SupplyOptimizationOutcome {
 public function capture(SupplyOptimizationPlan $p):?DecisionLearningRecord {
  if(!$p->approved||!$p->execution)return null;
  $old=DecisionLearningRecord::where('kind','optimization_outcome')->where('source_type','SupplyOptimizationPlan')->where('source_id',$p->id)->first();if($old)return $old;
  $start=Date::parse($p->evidence_cutoff)->startOfDay()->addDay();$end=$start->addDays($p->scope['horizon']-1);if($end->gte(today()))return null;$rows=[];
  foreach($p->input['rows'] as $r){$unit=$r['product']['unit'];if(Product::whereKey($r['product']['id'])->value('unit')!==$unit)return null;
   $w=$r['warehouse']['id']??0;$stock=AnalyticsSnapshot::where('entity_type',$w?'inventory':'product')->where('entity_id',$r['product']['id'])->where('warehouse_id',$w)->whereDate('snapshot_date','>=',$start->toDateString())->whereDate('snapshot_date','<=',$end->toDateString())->where('observed_at','<=',now())->orderBy('snapshot_date')->get()->keyBy(fn($s)=>$s->snapshot_date->toDateString());
   $days=[];for($d=$start;$d->lte($end);$d=$d->addDay()){$s=$stock->get($d->toDateString());if(!$s||($s->facts['unit']??null)!==$unit||!isset($s->facts['available'])||$s->observed_at->toDateString()!==$d->toDateString())return null;$days[]=['date'=>$d->toDateString(),'available'=>$s->facts['available'],'stockout'=>$s->facts['stockout']??false];}
   $baseline=app(InventoryPlanningMath::class)->calculate($r['input'],['base_quantity'=>0]);$score=app(DecisionLearningMath::class)->scorecard($days,$r['input']['policy']['maximum_stock']??null,$baseline['safety_stock']);$score['demand_observed']=null;$score['demand_qualification']='Stock samples alone do not measure demand.';$rows[]=['product_id'=>$r['product']['id'],'warehouse_id'=>$w?:null,'scorecard'=>$score];
  }
  return app(DecisionLearningService::class)->append('supply-outcome:'.$p->version,'optimization_outcome','inventory','supply_optimization',['state'=>'COMPLETED_OBSERVATION','plan_id'=>$p->id,'source_version'=>$p->version,'window'=>['from'=>$start->toDateString(),'to'=>$end->toDateString()],'rows'=>$rows,'qualification'=>'Actual sampled stock observations after review/execution. Not continuous fill rate, causal proof, lost-sales money or an observed alternative plan. Purchasing values remain in the protected source plan.'],'SupplyOptimizationPlan',$p->id,now());
 }
}
