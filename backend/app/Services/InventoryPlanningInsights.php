<?php
namespace App\Services;

use App\Models\{Expense,FinancialAccount,InventoryRecommendation,Product,PurchaseOrder,Warehouse};
use App\Support\Money;

/** Advisory interpretation of existing planning evidence. No operational writes. */
final class InventoryPlanningInsights {
 public function enrich(array $r,array $i):array {
  $balance=(float)$r['stock']['available']-(float)($r['stock']['committed_outgoing']??0);
  $incoming=array_sum(array_column($i['firm_incoming'],'quantity'));
  $end=$r['timeline']?last($r['timeline'])['baseline']:null;
  $maximum=$r['policy']['maximum_stock'];
  $excess=$end===null?null:round(max(0,$end-$r['safety_stock']),3);
  $state=match(true){
   !$r['daily']||!$r['coverage_supported']=>'insufficient_data',
   $balance<=0&&$r['expected_lead_demand']>0=>'critical',
   $r['stockout_date']&&$r['stockout_date']<=($r['expected_arrival']??'0000')=>'potential_stockout',
   $maximum!==null&&$balance>$maximum=>'overstock',
   array_sum(array_column($r['daily'],'quantity'))==0&&$balance>0=>'slow_moving',
   $r['reorder_point']!==null&&$balance+$incoming<$r['reorder_point']=>'reorder_now',
   $r['desired_base_quantity']>0=>'reorder_soon',
   $excess>0=>'excess_stock',
   default=>'healthy',
  };
  $r['optimization_state']=$state;$r['excess_quantity']=$excess;
  $r['inventory_position']=['usable_on_hand'=>$r['stock']['available'],'unreserved_commitments'=>$r['stock']['committed_outgoing']??0,'confirmed_dated_incoming'=>round($incoming,3),'position'=>round($balance+$incoming,3),'undated_or_risky_incoming'=>max(0,round(($r['stock']['incoming']??0)-collect($i['firm_incoming'])->whereNotNull('purchase_order_id')->sum('quantity'),3))];
  $r['confidence_state']=$r['method']==='configured_floor'?'low_confidence':($r['quality']['qualified']?'qualified_observations':'limited_observations');
  $r['supplier_risk']=$this->supplier($i['supplier'],$i['currency']);
  $r['input_snapshot']=['as_of'=>today()->toDateString(),'history'=>$i['history'],'forecast_error'=>$i['forecast_error']??[],'firm_incoming'=>$i['firm_incoming'],'forecast_source'=>$i['forecast_source'],'lead'=>$i['lead'],'minimum_safety'=>$i['minimum_safety'],'reorder_floor'=>$i['reorder_floor']];
  $r['reasons']=[['code'=>'inventory_position','value'=>$r['inventory_position']],['code'=>'lead_demand','value'=>$r['expected_lead_demand']],['code'=>'safety_method','value'=>['method'=>$r['method'],'quantity'=>$r['safety_stock'],'confidence'=>$r['confidence_state']]],['code'=>'fixed_order_constraints','value'=>['required'=>$r['required_base_quantity'],'recommended'=>$r['desired_base_quantity'],'moq'=>$r['moq'],'step'=>$r['step'],'factor'=>$r['factor']]],['code'=>'optimization_state','value'=>$state]];
  return $r;
 }
 private function supplier(array $s,string $currency):array {
  $e=$s['lead_evidence']??[];$p=$s['performance']??[];$price=$s['purchase_price']??null;
  $basePrice=$price===null?null:(($s['currency']??$currency)===$currency?Money::normalizeDecimal($price,6):(($s['exchange_rate_to_base']??0)>0?Money::multiply($price,$s['exchange_rate_to_base'],6):null));
  return ['supplier_id'=>$s['supplier_id']??null,'name'=>$s['name']??null,'unit_price'=>$price,'base_unit_price'=>$basePrice,'currency'=>$s['currency']??$currency,'base_currency'=>$currency,'lead_days'=>($e['eligible']??false)?$e['median_days']:($s['usual_lead_time_days']??null),'lead_p90'=>($e['eligible']??false)?$e['p90_days']:null,'lead_variability'=>($e['eligible']??false)?$e['variability_days']:null,'late_delivery_percent'=>($e['eligible']??false)&&($e['promised_samples']??0)>=5&&isset($e['on_time_percent'])?round(100-$e['on_time_percent'],1):null,'quality_acceptance_percent'=>$p['quality']['acceptance_percent']??null,'quality_inspections'=>$p['quality']['inspections']??0,'claims'=>$p['quality']['claim_count']??0,'reliability_score'=>$p['reliability']['score']??null,'samples'=>$e['samples']??0,'minimum_order_quantity'=>$s['minimum_order_quantity']??0,'pack_size'=>$s['pack_size']??1,'is_preferred'=>$s['is_preferred']??false,'lead_source'=>($e['eligible']??false)?'completed_receipts':'catalogue_or_unknown'];
 }
 public function supplierOptions(array $i,bool $finance):array {
  $rows=array_map(fn($s)=>$this->supplier($s,$i['currency']),$i['suppliers']);
  foreach($rows as &$r){$r['tradeoffs']=[];foreach($rows as $other){if($other['supplier_id']===$r['supplier_id'])continue;
   foreach(['base_unit_price','lead_days','late_delivery_percent'] as $key)if($r[$key]!==null&&$other[$key]!==null&&$r[$key]<$other[$key])$r['tradeoffs'][]=['advantage'=>$key,'compared_with'=>$other['supplier_id']];
  }$r['qualification']='Recorded trade-offs, not an automatic winner; unknown evidence is not ranked as zero.';
  if(!$finance){$r['unit_price']=$r['base_unit_price']=null;$r['tradeoffs']=array_values(array_filter($r['tradeoffs'],fn($t)=>$t['advantage']!=='base_unit_price'));}}
  unset($r);return $rows;
 }
 public function transfers(Product $p,array $destination):array {
  if(!$destination['warehouse']||!$destination['coverage_supported']||!($destination['desired_base_quantity']>0))return [];
  $rows=[];
  foreach(Warehouse::where('is_active',true)->whereKeyNot($destination['warehouse']['id'])->orderBy('id')->limit(20)->get() as $w){
   $i=app(InventoryPlanningData::class)->input($p,['warehouse_id'=>$w->id]);
   // Only measured local demand can justify an excess-stock donor. Declared
   // shares are explicit scenarios, not warehouse forecast evidence.
   if($i['scope']!=='qualified_warehouse')continue;
   $source=app(InventoryPlanningMath::class)->calculate($i);if(!$source['coverage_supported']||!$source['timeline'])continue;
   $minimum=min(array_column($source['timeline'],'baseline'));
   $excess=max(0,min($i['stock']['available_to_promise'],$minimum-$source['safety_stock']));
   $quantity=min($excess,$destination['desired_base_quantity']);$quantity=$i['base_fractional']?floor($quantity*1000)/1000:floor($quantity);if($quantity<=0)continue;
   $days=app(InventoryPlanningData::class)->transferLead($w->id,$destination['warehouse']['id']);
   $arrival=$days===null?null:today()->addDays((int)ceil($days))->toDateString();
   $rows[]=['source_warehouse_id'=>$w->id,'source_warehouse_name'=>$w->name,'destination_warehouse_id'=>$destination['warehouse']['id'],'quantity'=>$quantity,'unit'=>$p->unit,'source_excess'=>round($excess,3),'transit_days'=>$days,'expected_arrival'=>$arrival,'arrives_before_stockout'=>$arrival&&$destination['stockout_date']?$arrival<$destination['stockout_date']:null,'requires_authorization'=>true,'url'=>'/warehouse-operations'];
  }return $rows;
 }
 public function changes(array $current,?array $previous):array {
  if(!$previous)return [];$rows=[];
  foreach(['safety_stock','reorder_point','expected_lead_demand','lead_days','desired_base_quantity','optimization_state'] as $key){$before=$previous[$key]??null;$after=$current[$key]??null;if($before!==$after)$rows[]=['field'=>$key,'previous'=>$before,'current'=>$after];}
  return $rows;
 }
 public function finance(string $currency):array {
  $accounts=FinancialAccount::where('is_active',true)->whereDate('opening_date','<=',today())->get();
  $matching=$accounts->where('currency',$currency);$cash=$matching->isEmpty()?null:Money::add(...$matching->map(fn($a)=>app(FinancialAccountService::class)->balance($a))->all());
  $orders=PurchaseOrder::whereNotIn('status',['draft','cancelled'])->get();$remaining=[];$upcoming=[];$commitments=[];$unknown=0;
  foreach($orders as $po){$balance=Money::maximum(0,Money::subtract($po->total_amount,$po->total_paid));$rate=$po->currency===$currency?1:$po->exchange_rate;if(!$rate||$rate<=0){$unknown++;continue;}
   $base=Money::multiply($balance,$rate);$remaining[]=$base;if(in_array($po->status,['confirmed','ordered','partially_received']))$commitments[]=Money::multiply($po->total_amount,$rate);
   if(Money::minor($base)>0&&$po->due_at&&$po->due_at->lte(today()->addDays(30)))$upcoming[]=['reference'=>$po->po_number,'amount'=>$base,'due_date'=>$po->due_at->toDateString(),'url'=>'/purchase-orders?po='.$po->id];
  }
  // PO obligations and supplier documents are deliberately separate sources:
  // adding both would count linked supplier invoices twice.
  $expenses=Expense::with('payments')->where('status','posted')->where('document_type','purchase_invoice')->whereNull('purchase_order_id')->whereNotNull('supplier_id')->get();$unlinked=[];
  foreach($expenses as $e){$rate=$e->currency===$currency?1:$e->exchange_rate;if($rate>0)$unlinked[]=Money::multiply($e->remaining_amount,$rate);else $unknown++;}
  return ['base_currency'=>$currency,'recorded_cash'=>$cash,'excluded_currency_accounts'=>$accounts->count()-$matching->count(),'po_outstanding'=>Money::add(...$remaining),'open_po_commitments'=>Money::add(...$commitments),'unlinked_supplier_documents'=>Money::add(...$unlinked),'upcoming_po_payments'=>$upcoming,'unknown_fx_records'=>$unknown,'qualification'=>'Recorded balances and separate source totals only; completeness, available credit and affordability are not inferred.'];
 }
}
