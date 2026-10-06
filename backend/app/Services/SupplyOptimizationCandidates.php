<?php
namespace App\Services;
use App\Support\Money;
use Carbon\CarbonImmutable as Date;

/** Enumerates bounded valid bundles, then scores only documented operational effects. */
class SupplyOptimizationCandidates {
 public function build(array $data):array {
  $groups=[];
  foreach($data['rows'] as $row){$i=$row['input'];$math=app(InventoryPlanningMath::class);$base=$math->calculate($i,['base_quantity'=>0]);$base['timeline']=array_slice($base['timeline'],0,$data['scope']['horizon']);
   $need=$base['timeline']?max(0,$base['safety_stock']-min(array_column($base['timeline'],'baseline'))):max(0,max($i['minimum_safety'],$i['reorder_floor'])-$i['stock']['available']+$i['stock']['committed_outgoing']);
   $row+=['need'=>$need,'baseline'=>$base];$options=[$this->candidate($row,[],[],0,'monitor',$data)];
   $donors=array_slice($row['transfers'],0,3);$bundles=[[]];foreach($donors as $t)$bundles[]=[$t];
   foreach($donors as $a)foreach($donors as $b)if($a['resource']!==$b['resource'])$bundles[]=[$a,$b];
   foreach($bundles as $bundle){$tq=0;$ts=[];foreach($bundle as $t){$q=min(max(0,$need-$tq),$t['available']);$q=$i['base_fractional']?floor($q*1000)/1000:floor($q);if($q>0){$ts[]=array_replace($t,['quantity'=>$q]);$tq+=$q;}}
    if($ts)$options[]=$this->candidate($row,[],$ts,0,'transfer',$data);
    if($row['existing_requests'])continue; // Existing sourcing must be reviewed rather than duplicated.
    foreach(array_slice($row['suppliers'],0,6) as $supplier){
     $si=$i;$si['supplier']=$supplier;$si['lead']=[];$si['landed_unit_allowance']=$supplier['supplier_id']===($i['supplier']['supplier_id']??null)?$i['landed_unit_allowance']:null;
     if($supplier['lead_evidence']['eligible']??false)$si['lead']=['p90'=>$supplier['lead_evidence']['p90_days'],'mean'=>$supplier['lead_evidence']['p90_days']];
     $r=$math->calculate($si,['base_quantity'=>0]);if($r['unit_price']===null||$r['base_unit_price']===null||$r['lead_days']===null)continue;
     $step=$r['step'];$required=max(0,$need-$tq);$max=ceil(max($required*$data['policy']['quantity_multiplier'],$r['moq'])/$step)*$step;
     if($required<=0)continue;
     $quantities=array_unique(array_map(fn($f)=>ceil(max($r['moq'],$max*$f)/$step)*$step,[.25,.5,.75,1]));
     $bridge=$base['timeline']?max(0,-min(array_column(array_filter($base['timeline'],fn($d)=>$d['date']>=$r['expected_arrival']),'baseline')?:[0])):0;
     if($bridge>0)$quantities[]=ceil(max($r['moq'],$bridge-$tq)/$step)*$step;
     $delays=[0];$stockout=$base['stockout_date'];if($stockout){$delay=max(0,today()->diffInDays(Date::parse($stockout),false)-$r['lead_days']-1);if($delay>0)$delays[]=(int)min(30,$delay);}
     foreach(array_unique($quantities) as $q)foreach($delays as $delay){$order=today()->addDays($delay)->toDateString();$pr=$math->calculate($si,['base_quantity'=>$q,'order_date'=>$order]);if(!$pr['feasible']||$pr['base_quantity']!=$q||$q<=0||$pr['base_cost']===null)continue;
      $risk=collect($row['supplier_risk'])->firstWhere('supplier_id',$supplier['supplier_id'])??[];
      $purchase=['product_id'=>$i['product']['id'],'supplier_id'=>$supplier['supplier_id'],'supplier_name'=>$supplier['name'],'warehouse_id'=>$i['warehouse']['id']??null,'warehouse_name'=>$i['warehouse']['name']??null,
       'base_quantity'=>$q,'quantity'=>$pr['quantity'],'unit'=>$pr['unit'],'unit_price'=>$pr['unit_price'],'base_cost'=>$pr['base_cost'],'quote_cost'=>$pr['estimated_cost'],'currency'=>$pr['currency'],'moq'=>$pr['moq'],'step'=>$step,'factor'=>$pr['factor'],
       'order_date'=>$order,'expected_at'=>$pr['expected_arrival'],'lead_days'=>$pr['lead_days'],'risk'=>$risk,'landed_estimate'=>$pr['estimated_landed_cost'],'landed_basis'=>$si['landed_unit_allowance']!==null?'recorded_allocation_allowance_not_a_freight_quote':null];
      $options[]=$this->candidate($row,[$purchase],$ts,$delay,$ts?'transfer_purchase':'purchase',$data);
     }
    }
   }
   // Valid split bundles use two independently MOQ/pack-valid half-quantities.
   $halves=array_values(array_filter($options,fn($c)=>$c&&count($c['purchases'])===1&&!$c['transfers']&&$c['purchases'][0]['base_quantity']<=$need*.65&&$c['purchases'][0]['order_date']===today()->toDateString()));
   foreach(array_slice($halves,0,8) as $a)foreach(array_slice($halves,0,8) as $b)if($a['purchases'][0]['supplier_id']<$b['purchases'][0]['supplier_id']&&$a['purchase_quantity']+$b['purchase_quantity']<=$need+max($a['purchases'][0]['step'],$b['purchases'][0]['step'])){
    $options[]=$this->candidate($row,[$a['purchases'][0],$b['purchases'][0]],[],0,'split_purchase',$data);
   }
   $dedup=[];foreach($options as $o)if($o){if(($data['scope']['protect_critical']??false)&&$row['severity']==='critical'&&(!$o['coverage_supported']||$o['stockout_days']>0))continue;$dedup[$o['id']]=$o;}
   $bounded=array_values($dedup);$truncated=count($bounded)>90;
   if($truncated){$selected=[];foreach(['cost','service','policy'] as $criterion){$sorted=$bounded;usort($sorted,function($a,$b)use($criterion,$data){$score=fn($c)=>match($criterion){'cost'=>$c['cost_minor'],'service'=>($c['stockout_days']??1000)*1000+$c['unmet_target'],default=>array_sum(array_map(fn($k,$v)=>($data['policy']['weights'][$k]??0)*$v,array_keys($c['penalties']),array_values($c['penalties'])))};return ($score($a)<=>$score($b))?:strcmp($a['id'],$b['id']);});foreach(array_slice($sorted,0,30) as $c)$selected[$c['id']]=$c;}$bounded=array_values($selected);}
   $groups[]=['key'=>$row['key'],'product'=>$row['product'],'warehouse'=>$row['warehouse'],'need'=>round($need,3),'confidence'=>$row['confidence'],'candidates'=>$bounded,'candidate_set_truncated'=>$truncated];
  }
  return ['groups'=>$groups,'resources'=>$data['resources'],'weights'=>$data['policy']['weights'],'commitment_limit_minor'=>isset($data['scope']['commitment_limit'])?Money::minor($data['scope']['commitment_limit']):null,'time_limit'=>12];
 }
 public function candidate(array $row,array $purchases,array $transfers,int $delay,string $action,array $data,array $stress=[]):?array {
  $i=$row['input'];$baseline=$row['baseline'];$path=[];$balance=$i['stock']['available']-($i['stock']['committed_outgoing']??0);$short=0;$out=0;$excess=0;$oldest=null;
  foreach($i['daily'] as $d){$date=$d['date'];
   foreach($i['firm_incoming'] as $r){$arrival=$r['expected_at'];if(isset($r['purchase_order_id'])&&(!isset($stress['incoming_order_ids'])||in_array($r['purchase_order_id'],$stress['incoming_order_ids'],true)))$arrival=Date::parse($arrival)->addDays($stress['shipment_delay']??0)->toDateString();if($arrival===$date)$balance+=$r['quantity'];}
   foreach($transfers as $t)if($t['expected_at']===$date)$balance+=$t['quantity'];
   foreach($purchases as $p){$arrival=Date::parse($p['expected_at'])->addDays((!isset($stress['supplier_id'])||$stress['supplier_id']===$p['supplier_id'])?($stress['supplier_delay']??0):0)->toDateString();if($arrival===$date)$balance+=$p['base_quantity'];}
   $balance-=$d['quantity']*($stress['demand_multiplier']??1);if($balance<-.0005){$out++;$oldest??=$date;$short+=-$balance;}$excess+=max(0,$balance-$baseline['safety_stock']);$path[]=['date'=>$date,'balance'=>round($balance,3)];
  }
  $qty=array_sum(array_column($purchases,'base_quantity'));$tq=array_sum(array_column($transfers,'quantity'));$cost=Money::add(...array_column($purchases,'base_cost'));$minor=Money::minor($cost);
  $cap=$i['policy']['maximum_stock']??null;if($cap!==null&&$path&&max(array_column($path,'balance'))>$cap+.0005)return null;
  $norm=max(1,$row['need']);$h=max(1,count($path));$risk=0;$quality=0;
  foreach($purchases as $p){$share=$qty>0?$p['base_quantity']/$qty:0;$risk+=$share*(isset($p['risk']['late_delivery_percent'])?$p['risk']['late_delivery_percent']/100:.5);$quality+=$share*(isset($p['risk']['quality_acceptance_percent'])?1-$p['risk']['quality_acceptance_percent']/100:.5);}
  $resources=[];foreach($transfers as $t)$resources[$t['resource']]=(int)round($t['quantity']*1000);
  $missing=!$path;$unmet=$path?max(0,$baseline['safety_stock']-last($path)['balance']):max(0,$row['need']-$qty-$tq);
  $penalties=['stockout'=>($missing?$unmet/$norm:$out/$h+min(1,$short/($norm*$h)))*max(1,(int)$baseline['priority'])/3,
   'availability'=>min(1,$unmet/$norm),'delay'=>$risk,'quality'=>$quality,'cost'=>$minor/max(100,isset($data['scope']['commitment_limit'])?Money::minor($data['scope']['commitment_limit']):1000000),
   'excess'=>$excess/($norm*$h),'commitment'=>($minor/max(100,isset($data['scope']['commitment_limit'])?Money::minor($data['scope']['commitment_limit']):1000000))*max(.7,1-$delay/100)+count($transfers)*.08/max(1,count($data['rows']))];
  $reason=$action==='monitor'?($row['existing_requests']?'existing_request':($row['need']<=0?'covered_by_stock_and_incoming':(!$path?'insufficient_demand_evidence':'limited_commitment_or_moq_tradeoff'))):($tq>0?'protected_donor_surplus':($qty<$row['need']?'partial_or_bridge_purchase':'horizon_coverage'));
  return ['id'=>hash('sha256',json_encode([$row['key'],$purchases,$transfers,$delay])),'group'=>$row['key'],'product'=>$row['product'],'warehouse'=>$row['warehouse'],
   'action'=>$action,'timing'=>$action==='monitor'?'monitor':($delay===0?'now':($delay<=7?'soon':'delay')),'current_stock'=>$i['stock']['available_to_promise'],'incoming'=>array_sum(array_column($i['firm_incoming'],'quantity')),
   'projected_need'=>round($row['need'],3),'purchases'=>$purchases,'transfers'=>$transfers,'purchase_quantity'=>$qty,'transfer_quantity'=>$tq,'cost_minor'=>$minor,'resources'=>$resources,'penalties'=>$penalties,
   'stockout_days'=>$missing?null:$out,'first_stockout'=>$oldest,'unmet_target'=>round($unmet,3),'coverage_supported'=>!$missing,'excess_mean'=>round($excess/$h,3),'timeline'=>$path,'reason'=>$reason,
   'confidence'=>$row['confidence'],'constraints'=>['moq_and_multiples'=>'enforced','shared_donor_stock'=>'enforced'],'assumptions'=>$baseline['assumptions']];
 }
 public function stress(array $data,array $lines,array $scenario):array {
  $rows=[];foreach($lines as $c){$row=collect($data['rows'])->firstWhere('key',$c['group']);$row+=['baseline'=>app(InventoryPlanningMath::class)->calculate($row['input'],['base_quantity'=>0]),'need'=>$c['projected_need']];$r=$this->candidate($row,$c['purchases'],$c['transfers'],0,$c['action'],$data,$scenario);$rows[]=['product'=>$c['product'],'warehouse'=>$c['warehouse'],'baseline_stockout_days'=>$c['stockout_days'],'stressed_stockout_days'=>$r['stockout_days']??null,'first_stockout'=>$r['first_stockout']??null,'coverage_supported'=>$r['coverage_supported']??false];}
  return ['scenario'=>$scenario,'rows'=>$rows,'affected_scopes'=>count(array_filter($rows,fn($r)=>$r['stressed_stockout_days']!==null&&$r['stressed_stockout_days']>$r['baseline_stockout_days'])), 'qualification'=>'Frozen quantities and dated receipts; this is a named scenario, not an outcome prediction or a probability.'];
 }
}
