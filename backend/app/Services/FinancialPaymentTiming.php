<?php
namespace App\Services;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Support\Facades\Date;

/** Transparent history: only completed, non-reversed CASH payments known by cutoff.
 * Adjustments close obligations but never become payment-behaviour labels. */
final class FinancialPaymentTiming {
 public function history(Customer $c):array {
  $rows=$c->debtTransactions()->whereNull('reversed_transaction_id')->whereDoesntHave('reversals')->whereDate('transaction_date','<=',today())->where('created_at','<=',now())->orderBy('transaction_date')->orderBy('id')->get();
  $open=[];$samples=[];
  foreach($rows as $r){
   if(in_array($r->type,['debt_added','opening_balance','positive_adjustment'])){$open[$r->id]=['remaining'=>Money::normalize($r->amount),'due'=>$r->due_date?->toDateString(),'created'=>$r->transaction_date->toDateString(),'cash_only'=>true];continue;}
   if(!in_array($r->type,['payment','negative_adjustment','return','cancellation']))continue;
   $amount=Money::normalize($r->amount);
   uasort($open,fn($a,$b)=>[$a['due']??'9999',$a['created']]<=>[$b['due']??'9999',$b['created']]);
   foreach($open as $id=>&$o){if(Money::minor($amount)<=0)break;$paid=Money::minimum($amount,$o['remaining']);if(Money::minor($paid)<=0)continue;
    $o['remaining']=Money::subtract($o['remaining'],$paid);$amount=Money::subtract($amount,$paid);$o['cash_only']=$o['cash_only']&&$r->type==='payment';
    if(Money::minor($o['remaining'])===0&&$o['cash_only']&&$o['due'])$samples[]=['obligation_id'=>$id,'due_date'=>$o['due'],'paid_date'=>$r->transaction_date->toDateString(),'known_at'=>$r->created_at->toIso8601String(),'delay_days'=>(int)Date::parse($o['due'])->diffInDays($r->transaction_date,false)];
   }unset($o);
  }
  $delays=array_column($samples,'delay_days');sort($delays);$n=count($delays);$eligible=$n>=config('financial_intelligence.minimum_timing_samples');
  $median=$n?($delays[(int)floor(($n-1)/2)]+$delays[(int)floor($n/2)])/2:null;
  $spread=$eligible?$delays[(int)floor(($n-1)*.75)]-$delays[(int)floor(($n-1)*.25)]:null;
  return ['samples'=>$samples,'count'=>$n,'eligible'=>$eligible,'median_delay'=>$median,'spread_days'=>$spread,'segment'=>!$eligible?'insufficient_history':($median>7?'frequently_late':($spread>14?'variable_payer':'consistent_payer')),'method'=>$eligible?'historical_median':'due_date_baseline'];
 }
 public function predict(?string $due,array $history,string $model='historical_median'):array {
  $eligible=$history['eligible'];$delay=$eligible&&$model==='historical_median'?(int)round($history['median_delay']):0;
  $date=$due?Date::parse($due)->addDays($delay)->toDateString():null;
  $overdue=$due&&$due<today()->toDateString();
  $riskDelay=$eligible?(int)round($history['median_delay']):0;
  return ['expected_date'=>$date&&$date>=today()->toDateString()?$date:null,'due_date_baseline'=>$due,'historical_date'=>$due&&$eligible?Date::parse($due)->addDays((int)round($history['median_delay']))->toDateString():null,'risk'=>$overdue?'OVERDUE':(!$due||!$eligible?'INSUFFICIENT_DATA':($riskDelay>30?'HIGH_RISK':($riskDelay>14?'ELEVATED':($riskDelay>3?'WATCH':'ON_TRACK')))),'confidence'=>$eligible?'moderate':'limited','timing_method'=>$due?($eligible?$model:'due_date_baseline'):'undated','qualification'=>$date&&$date<today()->toDateString()?'Expected collection date has passed; collection date is unknown.':null];
 }
}
