<?php
namespace App\Services;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
/** Pure, fixed-precision cash movements. Never sums different currencies. */
final class FinancialForecastMath {
 public function calculate(array $e,int $horizon=90):array {
  $start=Date::parse($e['as_of']);$end=$start->copy()->addDays($horizon)->toDateString();$currencies=array_unique(array_merge(array_keys($e['cash']['by_currency']),array_column($e['receivables'],'currency'),array_column($e['commitments'],'currency'),array_column($e['other_scheduled']??[],'currency')));sort($currencies);$out=[];
  foreach($currencies as $currency){$opening=$e['cash']['by_currency'][$currency]??null;$balance=$opening;$in='0.00';$outflow='0.00';$rows=[];$pressure=[];
   $events=array_merge(array_map(fn($r)=>$r+['direction'=>'inflow'],$e['receivables']),array_map(fn($r)=>$r+['direction'=>'outflow'],$e['commitments']),$e['other_scheduled']??[]);
   $events=array_values(array_filter($events,fn($r)=>$r['currency']===$currency&&!empty($r['expected_date'])&&$r['expected_date']>=$e['as_of']&&$r['expected_date']<=$end));
   usort($events,fn($a,$b)=>[$a['expected_date'],$a['key']]<=>[$b['expected_date'],$b['key']]);
   $byDate=collect($events)->groupBy('expected_date');
   foreach($byDate as $date=>$dated){$di='0.00';$do='0.00';foreach($dated as $r){if($r['direction']==='inflow')$di=Money::add($di,$r['amount']);else $do=Money::add($do,$r['amount']);}
    $in=Money::add($in,$di);$outflow=Money::add($outflow,$do);$net=Money::subtract($di,$do);if($balance!==null)$balance=Money::add($balance,$net);
    $threshold=$e['settings']['minimum_cash'][$currency]??'0.00';$isPressure=$balance===null?Money::minor($net)<0:Money::compare($balance,$threshold)<0;
    $row=['date'=>$date,'inflows'=>$di,'outflows'=>$do,'net_change'=>$net,'expected_cash'=>$balance,'events'=>$dated->values()->all(),'pressure'=>$isPressure];$rows[]=$row;
    if($isPressure)$pressure[]=['date'=>$date,'currency'=>$currency,'net_requirement'=>Money::maximum(0,Money::subtract($do,$di)),'expected_cash'=>$balance,'threshold'=>$threshold,'qualification'=>$balance===null?'Net payment concentration; opening cash unknown.':'Projected pressure on recorded accounts, not an insolvency assessment.'];
   }
   $out[$currency]=['opening_recorded_cash'=>$opening,'inflows'=>$in,'outflows'=>$outflow,'expected_closing_cash'=>$balance,'net_change'=>Money::subtract($in,$outflow),'timeline'=>$rows,'pressure'=>$pressure,'range'=>null,'range_reason'=>'No calibrated uncertainty range from completed observations.'];
  }
  return ['horizon'=>$horizon,'as_of'=>$e['as_of'],'ends_on'=>$end,'currencies'=>$out,'advisory'=>true];
 }
}
