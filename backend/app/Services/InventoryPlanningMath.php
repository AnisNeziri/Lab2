<?php
namespace App\Services;
use App\Support\Money;
use Carbon\CarbonImmutable as Date;

/** Pure deterministic planning. Quantities are rounded only at order boundaries;
 * budget comparisons use integer minor currency units. No stock/financial writes. */
final class InventoryPlanningMath {
 public function calculate(array $i,array $s=[]):array {
  // Frozen simulations inject their date without changing Carbon's process clock.
  $today=isset($s['as_of'])||isset($i['as_of'])?Date::parse($s['as_of']??$i['as_of'])->startOfDay():Date::instance(today());
  $p=array_replace(['service_level'=>.95,'priority'=>3,'review_days'=>14,'maximum_stock'=>null],$i['policy'],$s);
  $level=(string)$p['service_level'];$z=['0.9'=>1.281551566,'0.95'=>1.644853627,'0.98'=>2.053748911,'0.99'=>2.326347874][$level]??1.644853627;
  $series=$i['history'];$usable=array_values(array_filter($series,fn($r)=>$r['demand']!==null&&!($r['censored']??false)));
  $values=array_map(fn($r)=>(float)$r['demand'],$usable);$n=count($values);$coverage=count($series)?$n/count($series):0;
  $mean=$n?array_sum($values)/$n:null;$variance=$n>1?array_sum(array_map(fn($v)=>($v-$mean)**2,$values))/($n-1):0;
  $multiplier=(float)($s['demand_multiplier']??1);$latest=$usable?last($usable)['date']:null;$stale=!$latest||$latest<$today->subDays(7)->toDateString();
  $left=array_slice($values,0,(int)floor($n/2));$right=array_slice($values,(int)floor($n/2));$older=$left?array_sum($left)/count($left):0;$newer=$right?array_sum($right)/count($right):0;
  $shift=$n>=28&&abs($newer-$older)>max(1,$older*.75);$mean=$mean===null?null:$mean*$multiplier;$variance*=$multiplier**2;
  $positives=count(array_filter($values,fn($v)=>$v>0));$intermittent=$n&&$positives/$n<.5;
  $sorted=$values;sort($sorted);$q=fn($r)=>$sorted?(float)$sorted[(int)floor((count($sorted)-1)*$r)]:0;
  $outlier=$n>=10&&max($values)>max(1,$q(.75)+3*($q(.75)-$q(.25)));
  $forecastVariance=$i['forecast_error']['daily_variance']??null;
  if($forecastVariance!==null)$variance=max($variance,(float)$forecastVariance*$multiplier**2);
  $lead=$i['lead']['mean']??$i['supplier']['usual_lead_time_days']??null;
  $lead=$lead===null?null:max(0,(float)$lead);$leadVariance=(float)($i['lead']['variance']??0);
  $planningLead=$lead===null?null:(int)ceil(max($lead,(float)($i['lead']['p90']??$lead)));
  $safety=(float)$i['minimum_safety'];$method='configured_floor';$dynamic=null;
  if($n>=28&&$coverage>=.8&&$lead!==null&&!$outlier&&!$intermittent&&!$stale&&!$shift){
   $dynamic=$z*sqrt($lead*$variance+($mean??0)**2*$leadVariance);$safety=max($safety,$dynamic);$method='independent_demand_lead_variance';
  }elseif($intermittent&&$lead!==null&&$n>=28&&$coverage>=.8){
   $window=max(1,(int)ceil($lead));$sums=[];$run=[];
   foreach($series as $r){if($r['demand']===null||($r['censored']??false)){$run=[];continue;}$run[]=(float)$r['demand'];if(count($run)>$window)array_shift($run);if(count($run)===$window)$sums[]=array_sum($run);}
   if(count($sums)>=20&&!$stale){sort($sums);$dynamic=max(0,$sums[(int)ceil($p['service_level']*(count($sums)-1))]*$multiplier-($mean??0)*$lead);$safety=max($safety,$dynamic);$method='empirical_lead_window';}
  }
  $demandMultiplier=(float)($s['demand_multiplier']??1);$daily=$i['daily'];
  if(!$daily&&$n>=7&&$coverage>=.5&&!$stale){for($d=0;$d<90;$d++)$daily[]=['date'=>$today->addDays($d)->toDateString(),'quantity'=>round($mean/$multiplier,6)];}
  $daily=array_values(array_filter($daily,fn($r)=>$r['date']>=$today->toDateString()));
  foreach($daily as &$d)$d['quantity']=max(0,(float)$d['quantity']*$demandMultiplier);unset($d);
  $orderDate=$s['order_date']??$today->toDateString();$arrival=$planningLead===null?null:Date::parse($orderDate)->addDays($planningLead+(int)($s['delay_days']??0))->toDateString();
  $target=$arrival?Date::parse($arrival)->addDays((int)$p['review_days'])->toDateString():null;
  $supported=$daily&&$target&&$target<=last($daily)['date'];
  $firm=$i['firm_incoming'];$balance=(float)$i['stock']['available']-(float)($i['stock']['committed_outgoing']??0);
  $leadDemand=$arrival&&$daily&&$arrival<=last($daily)['date']?array_sum(array_column(array_filter($daily,fn($d)=>$d['date']<$arrival),'quantity')):null;
  $targetDemand=$supported?array_sum(array_column(array_filter($daily,fn($d)=>$d['date']<=$target),'quantity')):null;
  $incoming=$supported?array_sum(array_column(array_filter($firm,fn($r)=>$r['expected_at']<=$target),'quantity')):0;
  $raw=$targetDemand===null?null:max(0,$targetDemand+$safety-$balance-$incoming);
  $factor=(float)$i['factor'];$step=$this->step((float)($i['supplier']['pack_size']??1),$factor,$i['unit_fractional'],$i['base_fractional']);
  $moq=(float)($i['supplier']['minimum_order_quantity']??0);
  $desired=$raw===null?null:($raw>0?ceil(max($moq,$raw)*1000/$step)*$step/1000:0);
  $quantity=isset($s['base_quantity'])?(float)$s['base_quantity']:$desired;
  $beforeCap=$quantity;$constraints=[];$price=$i['supplier']['purchase_price']??null;$rate=$i['supplier']['exchange_rate_to_base']??null;
  $sameCurrency=$i['currency']===($i['supplier']['currency']??$i['currency']);
  // Existing PR prices are two-decimal purchase-unit values. Planning costs
  // and budget feasibility use exactly that price, not a parallel valuation.
  $unitPrice=$price===null?null:Money::multiply($price,$factor);
  $costFor=fn($q)=>$unitPrice===null?null:Money::multiply($unitPrice,Money::divide($q,$factor,6));
  $baseCostFor=fn($q)=>$unitPrice===null?null:($sameCurrency?$costFor($q):($rate>0?Money::multiply($costFor($q),$rate):null));
  $basePrice=$unitPrice===null?null:($sameCurrency?Money::divide($unitPrice,$factor,6):($rate>0?Money::divide(Money::multiply($unitPrice,$rate,6),$factor,6):null));
  if($quantity!==null&&$p['maximum_stock']!==null){$max=max(0,(float)$p['maximum_stock']-max(0,$balance+$incoming-($leadDemand??0)));if($quantity>$max){$quantity=floor($max*1000/$step)*$step/1000;$constraints[]='declared_stock_ceiling';}}
  if(isset($s['budget'])){if($basePrice===null){$quantity=0;$constraints[]='unknown_base_price';}elseif(Money::compareDecimal($basePrice,'0')>0&&$quantity!==null){
   while(Money::minor($baseCostFor($quantity))>Money::minor($s['budget'])&&$quantity>0){$affordable=(float)Money::divide($s['budget'],$basePrice,6);$quantity=max(0,min($quantity-$step/1000,floor($affordable*1000/$step)*$step/1000));}$constraints[]='explicit_budget';}}
  if($quantity!==null&&$quantity>0&&$quantity<$moq){$quantity=0;$constraints[]='below_moq';}
  $feasible=$quantity!==null&&abs($quantity*1000/$step-round($quantity*1000/$step))<.00001&&($quantity<=0||$quantity>=$moq);
  $path=[];$stockout=null;$scenarioOut=null;$base=$balance;$with=$balance;$stockoutDays=0;$scenarioDays=0;
  foreach($daily as $d){$arrivals=array_sum(array_column(array_filter($firm,fn($r)=>$r['expected_at']===$d['date']),'quantity'));$base+=$arrivals-$d['quantity'];$with+=$arrivals-$d['quantity'];if($arrival===$d['date'])$with+=(float)$quantity;
   if($base<-.0005){$stockout??=$d['date'];$stockoutDays++;}if($with<-.0005){$scenarioOut??=$d['date'];$scenarioDays++;}
   $path[]=['date'=>$d['date'],'demand'=>round($d['quantity'],3),'baseline'=>round($base,3),'with_order'=>round($with,3)];}
  $average=$daily?array_sum(array_column($daily,'quantity'))/count($daily):null;$atArrival=$arrival?collect($path)->firstWhere('date',$arrival):null;
  return ['logic_version'=>'inventory-planning-v4.1','method'=>$method,'service_level'=>$p['service_level'],'priority'=>(int)$p['priority'],'safety_stock'=>round($safety,3),'dynamic_safety_stock'=>$dynamic===null?null:round($dynamic,3),
   'reorder_point'=>$leadDemand===null?null:round(max((float)$i['reorder_floor'],$leadDemand+$safety),3),'expected_lead_demand'=>$leadDemand===null?null:round($leadDemand,3),
   'days_of_supply'=>$average>0?round(max(0,$balance)/$average,1):null,'post_arrival_days_of_supply'=>$average>0&&$atArrival?round(max(0,$atArrival['with_order'])/$average,1):null,
   'lead_days'=>$planningLead,'expected_arrival'=>$arrival,'reorder_date'=>$stockout&&$planningLead!==null?Date::parse($stockout)->subDays($planningLead)->max($today)->toDateString():null,
   'stockout_date'=>$stockout,'scenario_stockout_date'=>$scenarioOut,'stockout_days'=>$stockoutDays,'scenario_stockout_days'=>$scenarioDays,
   'required_base_quantity'=>$raw===null?null:round($raw,3),'base_quantity'=>$quantity===null?null:round($quantity,3),'desired_base_quantity'=>$desired,'quantity'=>$quantity===null?null:round($quantity/$factor,3),'unit'=>$i['unit'],'factor'=>$factor,'step'=>$step/1000,'moq'=>$moq,
   'feasible'=>$feasible,'coverage_supported'=>(bool)$supported,'shortfall'=>max(0,(float)$desired-(float)$quantity),'constraints'=>$constraints,
   'estimated_cost'=>$quantity===null?null:$costFor($quantity),'base_cost'=>$quantity===null?null:$baseCostFor($quantity),'base_unit_price'=>$basePrice,'unit_price'=>$unitPrice,
   'currency'=>$i['supplier']['currency']??$i['currency'],'base_currency'=>$i['currency'],'estimated_landed_cost'=>isset($i['landed_unit_allowance'])&&$quantity!==null&&$basePrice!==null?Money::multiply($quantity,(string)\Brick\Math\BigDecimal::of($basePrice)->plus($i['landed_unit_allowance'])):null,
   'risk'=>!$daily?'insufficient_data':($stockout&&$arrival&&$stockout<=$arrival?'high':($stockout?'watch':'normal')),'daily'=>$daily,'timeline'=>$path,
   'quality'=>['observed_days'=>$n,'history_days'=>count($series),'coverage'=>round($coverage,3),'intermittent'=>(bool)$intermittent,'outliers'=>$outlier,'stockout_days'=>count(array_filter($series,fn($r)=>$r['censored']??false)),'returns'=>round(array_sum(array_column($series,'returned')),3),'forecast_source'=>$i['forecast_source'],
    'qualified'=>$n>=28&&$coverage>=.8&&!$outlier&&!$stale&&!$shift,'stale'=>$stale,'demand_shift'=>$shift,'service_target_validated'=>false],
   'assumptions'=>array_values(array_filter([$method==='independent_demand_lead_variance'?'independent stationary demand and lead-time variance':null,$method==='empirical_lead_window'?'overlapping empirical windows; requested service target is not a proven fill rate':null,$method==='configured_floor'?'configured safety floor; insufficient variability evidence':null,
    'gross fulfilled demand; returns separate; lost demand unknown','available already excludes reservations; only unreserved commitments additionally deducted','firm dated receipts remain estimates; risky/undated receipts excluded',
    $beforeCap!==$quantity?'constraints leave unmet target; service level was not silently lowered':null,'physical storage capacity and available credit are unknown','no automatic purchase or financial entry']))];
 }
 public function step(float $pack,float $factor,bool $unitFractional,bool $baseFractional):int {
  $a=max(1,(int)round($pack*1000));$b=$unitFractional?1:max(1,(int)round($factor*1000));$step=$this->lcm($a,$b);return $baseFractional?$step:$this->lcm($step,1000);
 }
 private function lcm(int $a,int $b):int{$x=$a;$y=$b;while($y){[$x,$y]=[$y,$x%$y];}return (int)($a/$x*$b);}
}
