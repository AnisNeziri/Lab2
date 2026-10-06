<?php
namespace App\Services;
final class EnterpriseDecisionRanker {
 public function rank(array $options,array $context,?array $weights=null,?string $version=null):array {
  $policy=app(DecisionLearningService::class)->currentPolicy();$weights??=$policy['weights'];$version??=$policy['version'];
  if(array_keys($weights)!==['stockout','availability','delay','quality','cost','excess','commitment']||array_sum($weights)<=0||$weights['stockout']<20||$weights['availability']<10||$weights['cost']>30)throw new \LogicException('Invalid decision scoring policy.');
  foreach($weights as $weight)if(!is_numeric($weight)||$weight<0||$weight>60)throw new \LogicException('Decision weights must be bounded.');
  $costs=array_column($options,'base_cost');$maxCost=max(1,...array_map(fn($v)=>(float)($v??0),$costs));$required=max(.001,(float)$context['required']);$hasRequirement=$context['required']>0;
  foreach($options as &$o){
   $forecast=$context['forecast'];$horizon=max(1,(int)$context['horizon']);
   $covered=(float)($o['base_quantity']??0)+(float)($o['transfer_quantity']??0);
   $components=[
    // A cheap partial action must not win by leaving the configured planning
    // requirement uncovered. Balance horizon exposure with target shortfall.
    'stockout'=>$forecast?.5*min(1,($o['impact']['stockout_days_after']??$horizon)/$horizon)+.5*min(1,max(0,$required-$covered)/$required):min(1,max(0,$required-$covered)/$required),
    'availability'=>$o['action']==='monitor'?($hasRequirement?1:0):($o['impact']['arrival']===null?.7:($context['stockout_date']&&$o['impact']['arrival']>$context['stockout_date']?min(1,\Carbon\Carbon::parse($context['stockout_date'])->diffInDays(\Carbon\Carbon::parse($o['impact']['arrival']))/max(1,min(30,$horizon))):0)),
    'delay'=>$o['action']==='monitor'?0:(($o['supplier_risk']['late_delivery_percent']??null)===null?.5:min(1,$o['supplier_risk']['late_delivery_percent']/100)),
    'quality'=>$o['action']==='monitor'?0:(($o['supplier_risk']['quality_acceptance_percent']??null)===null?.5:max(0,1-$o['supplier_risk']['quality_acceptance_percent']/100)),
    'cost'=>$o['base_cost']===null?.5:min(1,(float)$o['base_cost']/$maxCost),
    'excess'=>min(1,max(0,$covered-$required)/$required),
    'commitment'=>$context['cash']===null?.5:($context['cash']>0?min(1,(float)($o['base_cost']??0)/$context['cash']):($o['base_cost']>0?1:0)),
   ];
   if(!$o['feasible'])$components['stockout']=$components['availability']=1;
   $o['score_components']=array_map(fn($key,$v)=>['criterion'=>$key,'risk'=>round($v,3),'weight'=>$weights[$key]],array_keys($components),array_values($components));
   $o['risk_score']=round(100*array_sum(array_map(fn($k,$v)=>$weights[$k]*$v,array_keys($components),array_values($components)))/array_sum($weights),2);
   $o['policy_version']=$version;
  }unset($o);
  usort($options,fn($a,$b)=>(($b['feasible']<=>$a['feasible'])?:($a['risk_score']<=>$b['risk_score'])?:strcmp($a['key'],$b['key'])));
  foreach($options as $k=>&$o)$o['rank']=$k+1;unset($o);return $options;
 }
}
