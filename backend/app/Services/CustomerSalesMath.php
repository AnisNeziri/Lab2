<?php
namespace App\Services;
use App\Support\Money;
use Illuminate\Support\Facades\Date;

/** Interpretable cadence, comparable periods and genuine basket associations. */
final class CustomerSalesMath {
 public function quantile(array $values,float $q):?float {sort($values);$n=count($values);if(!$n)return null;$p=($n-1)*$q;$lo=(int)floor($p);$hi=(int)ceil($p);return $values[$lo]+($values[$hi]-$values[$lo])*($p-$lo);}
 public function cadence(array $dates,string $asOf,string $model='interval_iqr_baseline'):array {
  $dates=array_values(array_unique($dates));sort($dates);$gaps=[];for($i=1;$i<count($dates);$i++)$gaps[]=(int)Date::parse($dates[$i-1])->diffInDays(Date::parse($dates[$i]),false);
  $median=$this->quantile($gaps,.5);$q1=$this->quantile($gaps,.25);$q3=$this->quantile($gaps,.75);$p90=$this->quantile($gaps,.9);$spread=$median===null?null:$q3-$q1;$last=$dates?last($dates):null;$recency=$last?(int)Date::parse($last)->diffInDays(Date::parse($asOf),false):null;
  $irregular=$median!==null&&($median<=0||$spread>$median||max($gaps)>3*$median);$eligible=count($dates)>=config('customer_intelligence.minimum_purchases')&&!$irregular;
  $baseline=$last&&$eligible?['start'=>Date::parse($last)->addDays((int)floor($q1))->toDateString(),'end'=>Date::parse($last)->addDays((int)ceil($q3))->toDateString()]:null;
  $mad=$median===null?null:$this->quantile(array_map(fn($v)=>abs($v-$median),$gaps),.5);$tolerance=max(1,$mad??0);$challenger=$last&&$eligible?['start'=>Date::parse($last)->addDays((int)max(1,floor($median-$tolerance)))->toDateString(),'end'=>Date::parse($last)->addDays((int)ceil($median+$tolerance))->toDateString()]:null;
  return ['count'=>count($dates),'interval_count'=>count($gaps),'median_days'=>$median,'q25_days'=>$q1,'q75_days'=>$q3,'p90_days'=>$p90,'spread_days'=>$spread,'mad_days'=>$mad,'days_since_last'=>$recency,'eligible'=>$eligible,'irregular'=>$irregular,'baseline_window'=>$baseline,'challenger_window'=>$challenger,'window'=>$model==='median_mad'?$challenger:$baseline,'state'=>!$eligible?'INSUFFICIENT_DATA':($recency>2*max($p90,$median+$spread)?'INACTIVE':($recency>max($p90,$median+$spread)?'AT_RISK':'ACTIVE')),'confidence'=>$eligible?'moderate':'limited','qualification'=>'Unique purchase dates; cadence is relative to this customer, not a universal absence threshold.'];
 }
 public function seasonality(array $baskets,string $asOf):array {
  $dates=array_column($baskets,'date');$first=$dates?min($dates):$asOf;$years=[];$months=[];foreach($baskets as $b){$year=substr($b['date'],0,4);if((int)$year>=(int)substr($asOf,0,4)||$first>$year.'-01-01')continue;$years[$year]=true;$months[$year][(int)substr($b['date'],5,2)]=($months[$year][(int)substr($b['date'],5,2)]??0)+1;}
  $eligible=count($years)>=2&&count($baskets)>=24;$active=[];
  if($eligible)for($m=1;$m<=12;$m++){if(count(array_filter($months,fn($y)=>($y[$m]??0)>=2))>=2)$active[]=$m;}
  $seasonal=$eligible&&count($active)>=2&&count($active)<=8;$off=$seasonal&&!in_array((int)substr($asOf,5,2),$active);
  return ['supported'=>$eligible,'seasonal'=>$seasonal,'active_months'=>$active,'off_season'=>$off,'qualification'=>$eligible?'Repeated transaction patterns across at least two completed calendar years.':'Insufficient repeated seasonal history; seasonality is unknown.'];
 }
 public function trend(array $baskets,string $asOf,bool $seasonal=false):array {
  $end=Date::parse($asOf);$start=$end->copy()->subDays(89);$previousEnd=$seasonal?$end->copy()->subYear():$start->copy()->subDay();$previousStart=$seasonal?$start->copy()->subYear():$previousEnd->copy()->subDays(89);
  $recent=array_values(array_filter($baskets,fn($b)=>$b['date']>=$start->toDateString()&&$b['date']<=$asOf));$prior=array_values(array_filter($baskets,fn($b)=>$b['date']>=$previousStart->toDateString()&&$b['date']<=$previousEnd->toDateString()));
  $sum=fn($b)=>Money::decimal(array_sum(array_map(fn($v)=>Money::minor($v['value']),$b)));$a=$sum($recent);$b=$sum($prior);$eligible=count($recent)>=3&&count($prior)>=3;
  $values=array_map(fn($v)=>Money::minor($v['value']),$prior);$noise=$values?($this->quantile($values,.75)-$this->quantile($values,.25))*sqrt(count($prior)):0;$countNoise=(int)ceil(sqrt(max(1,count($prior))));$delta=Money::minor($a)-Money::minor($b);$change=count($recent)-count($prior);
  $state=!$eligible?'INSUFFICIENT_DATA':($delta>$noise&&$change>=$countNoise?'GROWING':($delta<-$noise&&$change<=-$countNoise?'DECLINING':'STABLE'));
  return ['state'=>$state,'recent_sales'=>$a,'previous_sales'=>$b,'recent_count'=>count($recent),'previous_count'=>count($prior),'change_percent'=>Money::minor($b)>0?round(100*$delta/Money::minor($b),1):null,'comparison'=>$seasonal?'same_period_previous_year':'previous_90_days','value_noise_tolerance'=>Money::decimal((int)round($noise)),'count_tolerance'=>$countNoise,'supported'=>$eligible];
 }
 public function affinities(array $baskets,string $kind='products'):array {
  $counts=[];$pairs=[];$customers=[];$n=count($baskets);foreach($baskets as $b){$ids=array_values(array_unique(array_filter($kind==='products'?array_keys($b['products']):array_keys($b['categories']))));sort($ids);foreach($ids as $id)$counts[$id]=($counts[$id]??0)+1;for($i=0;$i<count($ids);$i++)for($j=$i+1;$j<count($ids);$j++){$key=$ids[$i].':'.$ids[$j];$pairs[$key]=($pairs[$key]??0)+1;if($b['customer_id'])$customers[$key][$b['customer_id']]=true;}}
  $rows=[];foreach($pairs as $key=>$together){[$a,$b]=array_map('intval',explode(':',$key));foreach([[$a,$b],[$b,$a]] as [$source,$target]){$support=$together/max(1,$n);$confidence=$together/$counts[$source];$lift=$confidence/($counts[$target]/max(1,$n));if($n<config('customer_intelligence.minimum_baskets')||$together<config('customer_intelligence.minimum_pair_baskets')||count($customers[$key]??[])<config('customer_intelligence.minimum_pair_customers')||$support<config('customer_intelligence.minimum_support')||$confidence<config('customer_intelligence.minimum_confidence')||$lift<config('customer_intelligence.minimum_lift'))continue;$rows[]=['kind'=>$kind,'source_id'=>$source,'target_id'=>$target,'pair_baskets'=>$together,'customer_count'=>count($customers[$key]),'basket_count'=>$n,'support'=>round($support,4),'association_confidence'=>round($confidence,4),'lift'=>round($lift,3)];}}
  usort($rows,fn($a,$b)=>[$b['pair_baskets'],$b['lift']]<=>[$a['pair_baskets'],$a['lift']]);return $rows;
 }
}
