<?php
namespace App\Services;
/** Pure multi-objective comparisons. No opaque combined success score. */
final class DecisionLearningMath {
 public function response(array $history,string $status,array $recommended):array {
  $choice=collect($history)->last(fn($h)=>isset($h['base_quantity']));
  $action=collect($history)->last(fn($h)=>in_array($h['action']??'', ['accepted','modified','dismissed','created_pr']));
  $state=$action?($action['action']==='created_pr'?($status==='modified'?'MODIFIED':'ACCEPTED'):strtoupper($action['action'])):match($status){'superseded'=>'SUPERSEDED','resolved'=>'EXPIRED',default=>'NOT_REVIEWED'};
  return ['state'=>$state,'at'=>$action['at']??null,'user_id'=>$action['user_id']??null,
   'recommended_quantity'=>$recommended['base_quantity']??null,'chosen_quantity'=>$choice['base_quantity']??null,
   'recommended_supplier_id'=>$recommended['supplier_id']??null,'chosen_supplier_id'=>$choice['supplier_id']??null,
   'action_recorded'=>(bool)$choice,'note'=>$action['note']??null];
 }
 public function scorecard(array $days,?float $ceiling,?float $safety=null):array {
  $stockout=count(array_filter($days,fn($d)=>($d['stockout']??false)||$d['available']<=0));
  $excess=$ceiling===null?null:array_sum(array_map(fn($d)=>max(0,$d['available']-$ceiling),$days))/max(1,count($days));
  $durations=[];foreach($days as $day)foreach($day['known_stockout_intervals']??[] as $v)if(!empty($v['from'])&&!empty($v['to']))$durations[]=\Carbon\CarbonImmutable::parse($v['from'])->diffInHours(\Carbon\CarbonImmutable::parse($v['to']));
  return ['stockout_days'=>$stockout,'service_observation_days'=>count($days)-$stockout,'excess_mean_quantity'=>$excess,
   'ending_available'=>last($days)['available']??null,'excess_quantity'=>$safety===null?null:max(0,(last($days)['available']??0)-$safety),'excess_definition'=>'V4 ending available minus original safety stock; declared-ceiling mean is a separate dimension','recorded_shortage_hours'=>$durations?array_sum($durations):null,
   'demand_observed'=>collect($days)->sum('sales_quantity'),'unconstrained_demand'=>null,'lost_revenue'=>null,
   'availability_coverage'=>'sampled_not_continuous','purchase_cost'=>null,'supplier_delay_days'=>null,'cash_pressure'=>null];
 }
 public function compare(array $pairs):array {
  $n=count($pairs);$dimensions=[];
  foreach(['stockout_days','excess_quantity','excess_mean_quantity','purchase_cost'] as $key){
   $valid=array_values(array_filter($pairs,fn($p)=>isset($p['champion'][$key],$p['challenger'][$key])));
   $a=$valid?collect($valid)->avg(fn($p)=>(float)$p['champion'][$key]):null;
   $b=$valid?collect($valid)->avg(fn($p)=>(float)$p['challenger'][$key]):null;
   $change=$a===null?null:$b-$a;
   if($key==='purchase_cost'&&$valid){$a=\App\Support\Money::divide(\App\Support\Money::add(...array_column(array_column($valid,'champion'),'purchase_cost')),count($valid));$b=\App\Support\Money::divide(\App\Support\Money::add(...array_column(array_column($valid,'challenger'),'purchase_cost')),count($valid));$change=\App\Support\Money::subtract($b,$a);}
   $dimensions[$key]=['samples'=>count($valid),'champion_mean'=>$a,'challenger_mean'=>$b,'change'=>$change,'improvement_fraction'=>$a>0?((float)$a-(float)$b)/(float)$a:null];
  }
  $service=$dimensions['stockout_days'];$excess=$dimensions['excess_quantity']['samples']?$dimensions['excess_quantity']:$dimensions['excess_mean_quantity'];$cost=$dimensions['purchase_cost'];
  $stable=$n>0&&collect($pairs)->every(fn($p)=>$p['challenger']['stockout_days']<=$p['champion']['stockout_days']&&(!isset($p['champion']['excess_quantity'],$p['challenger']['excess_quantity'])||$p['challenger']['excess_quantity']<=$p['champion']['excess_quantity']+.0005)&&($p['challenger']['excess_mean_quantity']===null||$p['champion']['excess_mean_quantity']===null||$p['challenger']['excess_mean_quantity']<=$p['champion']['excess_mean_quantity']+.0005)&&isset($p['champion']['purchase_cost'],$p['challenger']['purchase_cost'])&&\App\Support\Money::compareDecimal($p['challenger']['purchase_cost'],$p['champion']['purchase_cost'])<=0);
  $improved=max($excess['improvement_fraction']??0,$cost['improvement_fraction']??0,$service['improvement_fraction']??0)>=config('decision_learning.minimum_improvement');
  $products=count(array_unique(array_column($pairs,'product_id')));
  $eligible=$n>=config('decision_learning.minimum_samples')&&$products>=config('decision_learning.minimum_products')&&$stable&&$improved;
  // Both chronological halves must show non-degradation, not just a favorable overall average.
  $half=(int)floor($n/2);foreach([array_slice($pairs,0,$half),array_slice($pairs,$half)] as $group)if(!$group||!collect($group)->every(fn($p)=>$p['challenger']['stockout_days']<=$p['champion']['stockout_days']))$eligible=false;
  return ['independent_samples'=>$n,'independent_products'=>$products,'minimum_samples'=>config('decision_learning.minimum_samples'),'minimum_products'=>config('decision_learning.minimum_products'),
   'dimensions'=>$dimensions,'stable_no_service_degradation'=>$stable,'meaningful_improvement'=>$improved,'eligible_for_human_review'=>$eligible,
   'state'=>$eligible?'READY_FOR_HUMAN_REVIEW':'COLLECTING_DECISION_OUTCOMES','evidence_kind'=>'estimated_paired_scenarios_with_genuine_observations',
   'qualification'=>config('decision_learning.qualification')];
 }
}
