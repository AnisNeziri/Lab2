<?php
namespace App\Services;
use App\Models\{Product,Warehouse,WarehouseStock,PurchaseOrder,PurchaseRequest,Shipment,AnalyticsSnapshot,AnalyticsPrediction,InventoryIntelligenceAlert,FinancialAccount};
use App\Support\Money;

/** Reads V1–V4 and canonical operational services. Never trains or writes. */
final class EnterpriseDecisionEvidence {
 public function assemble(int $id,?int $warehouse=null):array {
  $planning=app(InventoryPlanningService::class);$base=$planning->decisionEvidence($id,['warehouse_id'=>$warehouse]);$p=Product::findOrFail($id);$plan=$base['plan'];$input=$base['input'];
  $finance=app(InventoryPlanningInsights::class)->finance($input['currency']);
  $supplierOptions=app(InventoryPlanningInsights::class)->supplierOptions($input,true);$transfers=$this->transfers($p,$plan,$input);
  $variants=[];foreach(array_slice($supplierOptions,0,config('enterprise_decisions.supplier_limit')) as $s){$variants[]=[$this->supplierVariant($base,$s),$s];}
  if(!$variants)$variants[]=[$base,[]];
  $required=$plan['required_base_quantity']??max(0,max($input['minimum_safety'],$input['reorder_floor'])-($plan['stock']['available']-$plan['stock']['committed_outgoing']));
  $alternatives=[];
  foreach($variants as [$v,$s]){
   $alternatives[]=$this->option($v,$s,$required,null,$finance);
   if($transfers){$alternatives[]=$this->option($v,$s,$required,$transfers[0],$finance);}
  }
  if($transfers)$alternatives[]=$this->option($base,[],$required,$transfers[0],$finance,true);
  $alternatives[]=['key'=>'monitor','action'=>'monitor','supplier_id'=>null,'supplier_name'=>null,'supplier_risk'=>[],'base_quantity'=>0,'quantity'=>0,'unit'=>$p->unit,'unit_price'=>null,'base_cost'=>'0.00','currency'=>$input['currency'],'transfer_quantity'=>0,'transfer'=>null,'feasible'=>true,'constraints'=>[],'assumptions'=>['no_new_replenishment'],'impact'=>['stockout_before'=>$plan['stockout_date'],'stockout_after'=>$plan['stockout_date'],'stockout_days_after'=>$plan['stockout_days'],'arrival'=>null,'days_of_supply'=>$plan['days_of_supply'],'excess_quantity'=>$plan['excess_quantity'],'projection_supported'=>(bool)$plan['daily']]];
  $alternatives=app(EnterpriseDecisionRanker::class)->rank($alternatives,['required'=>$required,'forecast'=>(bool)$plan['daily'],'horizon'=>count($plan['daily']),'stockout_date'=>$plan['stockout_date'],'cash'=>$finance['recorded_cash']===null?null:(float)$finance['recorded_cash']]);
  $orders=PurchaseOrder::with(['items'=>fn($q)=>$q->where('product_id',$id)])->whereHas('items',fn($q)=>$q->where('product_id',$id))->whereNotIn('status',['draft','cancelled','completed','received'])->when($warehouse,fn($q)=>$q->where('warehouse_id',$warehouse))->orderBy('id')->limit(30)->get();
  $poIds=$orders->pluck('id');$shipments=$poIds->isEmpty()?collect():Shipment::with('containers','purchaseOrders')->where(fn($q)=>$q->whereIn('purchase_order_id',$poIds)->orWhereHas('purchaseOrders',fn($s)=>$s->whereIn('purchase_orders.id',$poIds)))->orderBy('id')->limit(30)->get();
  $intelligence=\App\Models\ShipmentIntelligence::whereIn('shipment_id',$shipments->pluck('id'))->where('is_current',true)->get()->keyBy('shipment_id');
  $logistics=$shipments->map(fn($s)=>['id'=>$s->id,'reference'=>$s->tracking_number,'status'=>$s->status,'eta'=>$s->eta?->toDateString(),'destination'=>$s->destination_port,'updated_at'=>$s->updated_at->toIso8601String(),
   'intelligence'=>isset($intelligence[$s->id])?['id'=>$intelligence[$s->id]->id,'risk'=>$intelligence[$s->id]->risk,'confidence'=>$intelligence[$s->id]->confidence,'eta'=>$intelligence[$s->id]->eta,'impact'=>$intelligence[$s->id]->impact,'checked_at'=>$intelligence[$s->id]->checked_at->toIso8601String(),'version'=>$intelligence[$s->id]->version]:null,
   'containers'=>$s->containers->map(fn($c)=>$c->only('id','container_number','status','destination_port')+['eta'=>$c->eta?->toDateString()])->all()])->all();
  $incoming=[];
  foreach($orders as $po){$remaining=round($po->items->sum(fn($i)=>max(0,(float)($i->base_quantity??$i->quantity)-(float)($i->received_base_quantity??$i->received_quantity))),3);if($remaining<=0)continue;
   $linked=$shipments->filter(fn($s)=>$s->purchase_order_id===$po->id||$s->purchaseOrders->contains('id',$po->id));
   $lateEta=$linked->contains(fn($s)=>$s->eta&&$po->expected_at&&$s->eta->gt($po->expected_at));$late=$po->expected_at&&$po->expected_at->lt(today());$v6=$linked->contains(fn($s)=>isset($intelligence[$s->id])&&in_array($intelligence[$s->id]->risk,['CRITICAL','DELAYED','AT_RISK']));$risk=in_array($po->id,$plan['at_risk_purchase_orders'])||$late||$lateEta||!$po->expected_at||$v6;
   $incoming[]=['id'=>$po->id,'reference'=>$po->po_number,'supplier_id'=>$po->supplier_id,'status'=>$po->status,'expected_at'=>$po->expected_at?->toDateString(),'remaining_quantity'=>$remaining,'at_risk'=>$risk,'reason'=>$lateEta?'shipment_eta_late':($late?'overdue_receipt':(!$po->expected_at?'undated_receipt':($risk?'milestone_delay':'recorded_promise'))),'attribution'=>'uncertain','updated_at'=>$po->updated_at->toIso8601String()];
  }
  // V5 adds direct logistics ETA evidence without changing V4 or supplier
  // training labels. A delayed shipment is not a firm on-time receipt.
  $riskIds=collect($incoming)->where('at_risk',true)->pluck('id')->all();
  if($intelligence->isNotEmpty()||collect($input['firm_incoming'])->contains(fn($r)=>in_array($r['purchase_order_id']??null,$riskIds,true))){
   $base=$this->logisticsTiming($base,$riskIds,$intelligence->all(),$id,$warehouse);$plan=$base['plan'];$input=$base['input'];$required=$plan['required_base_quantity']??max(0,max($input['minimum_safety'],$input['reorder_floor'])-($plan['stock']['available']-$plan['stock']['committed_outgoing']));$alternatives=[];
   foreach($variants as [$v,$s]){$v=$this->logisticsTiming($v,$riskIds,$intelligence->all(),$id,$warehouse);$alternatives[]=$this->option($v,$s,$required,null,$finance);if($transfers)$alternatives[]=$this->option($v,$s,$required,$transfers[0],$finance);}
   if($transfers)$alternatives[]=$this->option($base,[],$required,$transfers[0],$finance,true);
   $alternatives[]=['key'=>'monitor','action'=>'monitor','supplier_id'=>null,'supplier_name'=>null,'supplier_risk'=>[],'base_quantity'=>0,'quantity'=>0,'unit'=>$p->unit,'unit_price'=>null,'base_cost'=>'0.00','currency'=>$input['currency'],'transfer_quantity'=>0,'transfer'=>null,'feasible'=>true,'constraints'=>[],'assumptions'=>['no_new_replenishment'],'impact'=>['stockout_before'=>$plan['stockout_date'],'stockout_after'=>$plan['stockout_date'],'stockout_days_after'=>$plan['stockout_days'],'arrival'=>null,'days_of_supply'=>$plan['days_of_supply'],'excess_quantity'=>$plan['excess_quantity'],'projection_supported'=>(bool)$plan['daily']]];
   $alternatives=app(EnterpriseDecisionRanker::class)->rank($alternatives,['required'=>$required,'forecast'=>(bool)$plan['daily'],'horizon'=>count($plan['daily']),'stockout_date'=>$plan['stockout_date'],'cash'=>$finance['recorded_cash']===null?null:(float)$finance['recorded_cash']]);
  }
  $requests=PurchaseRequest::whereHas('items',fn($q)=>$q->where('product_id',$id))->whereIn('status',['draft','submitted','approved','sourcing'])->orderBy('id')->get(['id','request_number','status']);
  $alerts=InventoryIntelligenceAlert::where('product_id',$id)->where('status','open')->get(['id','evidence']);
  $unknowns=array_values(array_filter([!$plan['daily']?'forecast_unavailable':null,$plan['scope']==='warehouse_demand_unavailable'?'warehouse_demand_unavailable':null,!$plan['lead_evidence']?'supplier_variability_unknown':null,$finance['recorded_cash']===null?'cash_completeness_unknown':null,count(array_filter($incoming,fn($r)=>$r['at_risk']))?'delay_attribution_uncertain':null,$transfers&&$transfers[0]['expected_arrival']===null?'transfer_arrival_unknown':null]));
  $confidence=!$plan['daily']||$alerts->isNotEmpty()?'limited':($plan['quality']['qualified']&&$plan['lead_evidence']&&str_starts_with($plan['quality']['forecast_source'],'approved_model:')&&count($unknowns)<=1?'high':'moderate');
  $threshold=max($input['minimum_safety'],$input['reorder_floor']);$excess=$plan['excess_quantity'];$ceiling=$input['policy']['maximum_stock']??($p->high_stock_threshold>0?$p->high_stock_threshold:null);
  if(!$plan['daily']&&$ceiling!==null)$excess=max(0,$plan['stock']['available']-$ceiling);
  $sources=['product_id'=>$id,'warehouse_id'=>$warehouse,'planning_policy_id'=>$input['policy_id'],'demand_observation_ids'=>AnalyticsSnapshot::where('entity_type','demand_observation')->where('entity_id',$id)->whereDate('snapshot_date','<',today())->whereDate('snapshot_date','>=',today()->subDays(90))->orderBy('snapshot_date')->limit(90)->pluck('id')->all(),'forecast_prediction_id'=>AnalyticsPrediction::where('entity_type','product')->where('entity_id',$id)->where('model_key','inventory-demand-v1')->where('generated_at','<=',now())->where('valid_until','>',now())->latest('id')->value('id'),'purchase_order_ids'=>$poIds->all(),'purchase_request_ids'=>$requests->pluck('id')->all(),'shipment_ids'=>$shipments->pluck('id')->all(),'financial_account_ids'=>FinancialAccount::where('is_active',true)->pluck('id')->all()];
  $evidence=['product'=>$plan['product'],'warehouse'=>$plan['warehouse'],'inventory'=>$plan['stock'],'inventory_position'=>$plan['inventory_position'],'safety_stock'=>$plan['safety_stock'],'reorder_point'=>$plan['reorder_point'],'threshold_target'=>$threshold,'required_quantity'=>round($required,3),'excess_quantity'=>$excess,'forecast'=>['available'=>(bool)$plan['daily'],'source'=>$plan['quality']['forecast_source'],'quality'=>$plan['quality'],'stockout_date'=>$plan['stockout_date'],'horizon'=>count($plan['daily']),'days_of_supply'=>$plan['days_of_supply'],'health_alert_ids'=>$alerts->pluck('id')->all()],'supplier_options'=>$supplierOptions,'transfers'=>$transfers,'procurement'=>['orders'=>$incoming,'requests'=>$requests->toArray()],'logistics'=>$logistics,'finance'=>$finance,'unknowns'=>$unknowns,'constraints'=>['service_level'=>$plan['service_level'],'unit'=>$p->unit,'quality_holds'=>['damaged'=>$plan['stock']['damaged'],'quarantine'=>$plan['stock']['quarantine'],'blocked'=>$plan['stock']['blocked']]],'sources'=>$sources,'versions'=>['planning'=>$plan['logic_version'],'policy'=>$plan['policy_version'],'decision'=>config('enterprise_decisions.version')]];
  $evidence['stock_ceiling']=$ceiling;$evidence['versions']['demand']=$plan['quality']['forecast_source'];
  // Cached V7 evidence adds a financial trade-off, never changes operational ranking.
  $financial=\App\Models\FinancialIntelligenceSnapshot::whereNull('evidence->archived')->latest('id')->first();
  if($financial){
   $c=$financial->forecast[30]['currencies'][$input['currency']]??null;$tradeoffs=[];
   foreach($alternatives as $option)if(($option['currency']??null)===$input['currency']&&$option['base_cost']!==null)$tradeoffs[]=['key'=>$option['key'],'action'=>$option['action'],'quantity'=>$option['base_quantity'],'transfer_quantity'=>$option['transfer_quantity'],'additional_purchase'=>Money::normalize($option['base_cost']),'expected_cash_if_paid_within_horizon'=>($c['expected_closing_cash']??null)===null?null:Money::subtract($c['expected_closing_cash'],$option['base_cost']),'stockout_after'=>$option['impact']['stockout_after'],'stockout_days_after'=>$option['impact']['stockout_days_after'],'coverage_supported'=>$option['impact']['projection_supported'],'excess_quantity'=>$option['impact']['excess_quantity']];
   $evidence['financial_intelligence']=['snapshot_id'=>$financial->id,'as_of'=>$financial->as_of->toDateString(),'currency'=>$input['currency'],'cash_forecast'=>$c,'confidence'=>$financial->evidence['confidence'],'cash_complete'=>$financial->evidence['cash']['complete'],'purchase_tradeoffs'=>$tradeoffs,'qualification'=>'Hypothetical full payment inside the 30-day horizon, not a payment schedule. Additional purchase is not yet a payable. Cash preservation does not override stockout risk.'];
  }
  $evidence['purchase_timing']=['reorder_date'=>$plan['reorder_date'],'expected_arrival'=>$plan['expected_arrival'],'lead_days'=>$plan['lead_days'],'review_days'=>$input['policy']['review_days']];
  $forecast=str_starts_with($plan['quality']['forecast_source'],'approved_model:')?AnalyticsPrediction::where('entity_type','product')->where('entity_id',$id)->where('model_key','inventory-demand-v1')->where('value->horizon',90)->where('model_version',substr($plan['quality']['forecast_source'],15))->where('generated_at','<=',now())->where('valid_until','>',now())->latest('id')->first():null;
  $evidence['sources']['forecast_prediction_id']=$forecast?->id;$evidence['forecast']['valid_until']=$forecast?->valid_until?->toIso8601String();
  // Exclude incidental timestamps from stable identity, but retain the exact
  // current operational fingerprint for confirmation-time revalidation.
  $fingerprint=hash('sha256',json_encode([$plan['fingerprint'],$incoming,$logistics,$requests->toArray(),$finance,$transfers,$supplierOptions,config('enterprise_decisions')]));
  $severity=$plan['stock']['available']-$plan['stock']['committed_outgoing']<=0&&$required>0?'critical':($plan['risk']==='high'?'high':($required>0?'warning':'normal'));
  $policy=app(DecisionLearningService::class)->currentPolicy();$evidence['versions']['decision']=$policy['version'];
  $fingerprint=hash('sha256',$fingerprint.json_encode($policy));
  return ['evidence'=>$evidence,'alternatives'=>$alternatives,'source_fingerprint'=>$fingerprint,'confidence'=>$confidence,'severity'=>$severity,'required'=>$required,'plan'=>$plan,'learning_input'=>$input,'ranking_context'=>['required'=>$required,'forecast'=>(bool)$plan['daily'],'horizon'=>count($plan['daily']),'stockout_date'=>$plan['stockout_date'],'cash'=>$finance['recorded_cash']===null?null:(float)$finance['recorded_cash']]];
 }
 /** Reuse the assembled operational input instead of re-reading the entire
  * supplier catalogue, warehouse balances and history for each alternative. */
 private function supplierVariant(array $base,array $s):array {
  $i=$base['input'];$i['supplier']=collect($i['suppliers'])->firstWhere('supplier_id',$s['supplier_id']);$i['lead']=[];$i['landed_unit_allowance']=null;
  if($i['supplier']['lead_evidence']['eligible']??false){$rows=app(SupplierHistoryService::class)->orders(\App\Models\Supplier::findOrFail($s['supplier_id']));$values=collect($rows)->filter(fn($r)=>$r['complete']&&$r['lead_days']!==null&&$r['label_known_at']<=today()->toDateString()&&collect($r['lines'])->contains('product_id',$i['product']['id']))->pluck('lead_days');if($values->count()>=5){$mean=$values->avg();$i['lead']=['mean'=>$mean,'variance'=>$values->sum(fn($d)=>($d-$mean)**2)/max(1,$values->count()-1),'p90'=>$i['supplier']['lead_evidence']['p90_days'],'samples'=>$values->count(),'source'=>'completed_receipts'];}}
  $r=app(InventoryPlanningMath::class)->calculate($i);$r+=$base['plan'];$r['supplier_id']=$s['supplier_id'];$r['supplier_name']=$s['name'];$r['lead_evidence']=$i['lead'];$r['fingerprint']=hash('sha256',json_encode([$base['plan']['fingerprint'],$i['supplier'],$i['lead']]));return ['input'=>$i,'plan'=>app(InventoryPlanningInsights::class)->enrich($r,$i)];
 }
 private function logisticsTiming(array $v,array $riskIds,array $intelligence,int $product,?int $warehouse):array {
  $i=$v['input'];$firm=app(ShipmentArrivalTiming::class)->apply($i,$riskIds,$intelligence,$product,$warehouse);
  $i['firm_incoming']=$firm;$r=app(InventoryPlanningMath::class)->calculate($i,$v['plan']['planning_options']);$r+=$v['plan'];$r['fingerprint']=hash('sha256',json_encode([$v['plan']['fingerprint'],$firm]));$r['at_risk_purchase_orders']=array_values(array_unique(array_merge($r['at_risk_purchase_orders'],$riskIds)));return ['input'=>$i,'plan'=>app(InventoryPlanningInsights::class)->enrich($r,$i)];
 }
 private function option(array $v,array $s,float $required,?array $transfer,array $finance,bool $transferOnly=false):array {
  $i=$v['input'];$before=$v['plan'];$math=app(InventoryPlanningMath::class);$transferQty=(float)($transfer['quantity']??0);$assumptions=[];
  if($transfer){if($transfer['expected_arrival'])$i['firm_incoming'][]=['expected_at'=>$transfer['expected_arrival'],'quantity'=>$transferQty];else $assumptions[]='transfer_arrival_unknown';$assumptions[]='transfer_requires_separate_authorization';if(isset($transfer['qualification']))$assumptions[]=$transfer['qualification'];}
  $fallback=!$before['coverage_supported'];$options=[];
  if($fallback){$step=$math->step((float)($i['supplier']['pack_size']??1),(float)$i['factor'],$i['unit_fractional'],$i['base_fractional'])/1000;$need=max(0,$required-$transferQty);$options['base_quantity']=$need>0?ceil(max($need,(float)($i['supplier']['minimum_order_quantity']??0))/$step)*$step:0;$assumptions[]='configured_threshold_not_forecast';}
  if($transferOnly)$options['base_quantity']=0;
  $r=$math->calculate($i,$options);
  $multiplier=app(DecisionLearningService::class)->currentPolicy()['quantity_multiplier'];
  if(!$transferOnly&&$multiplier!=1&&($r['base_quantity']??0)>0){$q=ceil(max($r['moq'],$r['base_quantity']*$multiplier)/max(.001,$r['step']))*$r['step'];$r=$math->calculate($i,array_replace($options,['base_quantity'=>$q]));}
  $quantity=$r['base_quantity']??0;
  $action=$transferQty>0?($quantity>0?'transfer_purchase':'transfer'):($quantity>0?'purchase':'monitor');
  $supplierRisk=$transferOnly?[]:$s;
  return ['key'=>($transferOnly?'transfer':('supplier-'.($s['supplier_id']??0))).($transfer?'-from-'.$transfer['source_warehouse_id']:''),'action'=>$action,'supplier_id'=>$transferOnly?null:($s['supplier_id']??null),'supplier_name'=>$transferOnly?null:($s['name']??null),'supplier_risk'=>$supplierRisk,'base_quantity'=>$quantity,'quantity'=>$r['quantity']??0,'unit'=>$r['unit'],'unit_price'=>$transferOnly?null:$r['unit_price'],'base_cost'=>$transferOnly?'0.00':$r['base_cost'],'currency'=>$r['currency'],'transfer_quantity'=>$transferQty,'transfer'=>$transfer,'feasible'=>$r['feasible']&&($transferOnly||$quantity<=0||!empty($s['supplier_id'])),'constraints'=>['moq'=>$r['moq'],'step'=>$r['step'],'factor'=>$r['factor'],'quantity_constraints'=>$r['constraints']],'assumptions'=>$assumptions,'impact'=>['stockout_before'=>$before['stockout_date'],'stockout_after'=>$r['scenario_stockout_date'],'stockout_days_after'=>$r['scenario_stockout_days'],'arrival'=>$transferOnly?($transfer['expected_arrival']??null):$r['expected_arrival'],'days_of_supply'=>$r['post_arrival_days_of_supply'],'excess_quantity'=>$r['timeline']?max(0,last($r['timeline'])['with_order']-$r['safety_stock']):null,'projection_supported'=>(bool)$r['daily']]];
 }
 private function transfers(Product $p,array $plan,array $input):array {
  if(!$plan['warehouse'])return [];$measured=app(InventoryPlanningInsights::class)->transfers($p,$plan);if($measured)return array_slice($measured,0,3);
  $target=max($input['minimum_safety'],$input['reorder_floor']);$need=max(0,$target-$plan['stock']['available']+$plan['stock']['committed_outgoing']);if($need<=0)return [];
  $sources=Warehouse::where('is_active',true)->whereKeyNot($plan['warehouse']['id'])->whereHas('stock',fn($q)=>$q->where('product_id',$p->id))->orderBy('id')->limit(20)->get();$rows=[];
  foreach($sources as $w){$stock=app(InventorySnapshotService::class)->forProduct($p,null,$w->id);$surplus=max(0,$stock['available_to_promise']-$target);$qty=min($surplus,$need);$qty=$input['base_fractional']?floor($qty*1000)/1000:floor($qty);if($qty<=0)continue;$days=app(InventoryPlanningData::class)->transferLead($w->id,$plan['warehouse']['id']);
   $rows[]=['source_warehouse_id'=>$w->id,'source_warehouse_name'=>$w->name,'destination_warehouse_id'=>$plan['warehouse']['id'],'quantity'=>$qty,'unit'=>$p->unit,'source_excess'=>$surplus,'transit_days'=>$days,'expected_arrival'=>$days===null?null:today()->addDays((int)ceil($days))->toDateString(),'requires_authorization'=>true,'qualification'=>'configured_company_floor_for_review_not_a_local_demand_forecast','url'=>'/warehouse-operations'];
  }usort($rows,fn($a,$b)=>($b['quantity']<=>$a['quantity'])?:($a['source_warehouse_id']<=>$b['source_warehouse_id']));return array_slice($rows,0,3);
 }
}
