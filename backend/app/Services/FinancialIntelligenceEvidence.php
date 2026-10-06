<?php
namespace App\Services;
use App\Models\{Company,Customer,Expense,FinancialAccount,Invoice,LandedCost,Product,PurchaseOrder,PurchaseRequest,InventoryRecommendation,FinancialIntelligencePolicy,Shipment,ShipmentIntelligence};
use App\Support\Money;
use Illuminate\Support\Facades\{Auth,Date};

/** Read-only adapter over existing monetary authorities, not another ledger. */
final class FinancialIntelligenceEvidence {
 public function assemble(string $model='due_date_baseline'):array {
  $company=Company::findOrFail(Auth::user()->company_id);$base=$company->base_currency?:'EUR';$settings=FinancialIntelligencePolicy::latest('id')->first()?->settings??[];
  $e=['as_of'=>today()->toDateString(),'cutoff'=>now()->toIso8601String(),'base_currency'=>$base,'settings'=>$settings,'cash'=>['by_currency'=>[],'accounts'=>[],'complete'=>false],'receivables'=>[],'customers'=>[],'commitments'=>[],'other_scheduled'=>[],'potential'=>[],'health'=>[],'scenario_options'=>['products'=>[],'suppliers'=>[]]];
  $e['calculation_version']=config('financial_intelligence.version');
  foreach(FinancialAccount::where('is_active',true)->whereDate('opening_date','<=',today())->get() as $a){
   $q=$a->transactions()->where('status','posted')->whereDate('transaction_date','<=',today())->where('created_at','<=',now());
   // Same posted movement classifications as FinancialAccountService::balance,
   // with a point-in-time date cutoff (the live balance method has no cutoff).
   $in=(clone $q)->whereIn('type',['inflow','transfer_in','adjustment_in','refund_in'])->sum('amount');$out=(clone $q)->whereIn('type',['outflow','transfer_out','adjustment_out','refund_out'])->sum('amount');
   $balance=Money::subtract(Money::add($a->opening_balance,$in),$out);
   $e['cash']['by_currency'][$a->currency]=Money::add($e['cash']['by_currency'][$a->currency]??0,$balance);
   $e['cash']['accounts'][]=['id'=>$a->id,'name'=>$a->name,'currency'=>$a->currency,'balance'=>$balance,'url'=>'/money-accounts','evidence_type'=>'observed'];
   foreach($a->transactions()->where('status','posted')->whereDate('transaction_date','>',today())->where('created_at','<=',now())->get() as $t){$inflow=in_array($t->type,['inflow','transfer_in','adjustment_in','refund_in']);$e['other_scheduled'][]=['key'=>'cash-transaction:'.$t->id,'reference'=>$t->reference_number?:$a->name.' #'.$t->id,'currency'=>$t->currency,'amount'=>Money::normalize($t->amount),'expected_date'=>$t->transaction_date->toDateString(),'direction'=>$inflow?'inflow':'outflow','source_type'=>'dated_cash_transaction','source_id'=>$t->id,'evidence_type'=>'scheduled','url'=>'/money-accounts'];}
  }
  $e['cash']['complete']=!empty($settings['cash_coverage_confirmed'])&&count($e['cash']['accounts'])>0;
  if(!$e['cash']['complete'])$e['health'][]=['code'=>'cash_incomplete','message'=>'Complete cash position unavailable. Only registered cash/bank accounts are included.','url'=>'/money-accounts'];
  $timing=app(FinancialPaymentTiming::class);$linkedInvoices=[];
  foreach(Customer::withTrashed()->get() as $c){
   $history=$timing->history($c);$open=app(CustomerCreditService::class)->obligations($c);$openTotal='0.00';
   foreach($open as $r){if($r->invoice_id)$linkedInvoices[]=$r->invoice_id;if(Money::minor($r->outstanding_amount)<=0)continue;
    $prediction=$timing->predict($r->due_date?->toDateString(),$history,$model);$amount=Money::normalize($r->outstanding_amount);$openTotal=Money::add($openTotal,$amount);
    $e['receivables'][]=$prediction+['key'=>'debt:'.$r->id,'customer_id'=>$c->id,'customer'=>$c->name,'reference'=>$r->reference_number?:'Debt #'.$r->id,'amount'=>$amount,'original_amount'=>Money::normalize($r->amount),'allocated_reduction'=>Money::subtract($r->amount,$amount),'obligation_date'=>$r->transaction_date->toDateString(),'currency'=>$base,'due_date'=>$r->due_date?->toDateString(),'source_type'=>'customer_ledger','source_id'=>$r->id,'evidence_type'=>$prediction['timing_method']==='historical_median'?'predicted':'scheduled','segment'=>$history['segment'],'samples'=>$history['count'],'url'=>'/customer-debts?customer='.$c->id];
   }
   $payments=$c->debtTransactions()->where('type','payment')->whereNull('reversed_transaction_id')->whereDoesntHave('reversals')->whereDate('transaction_date','<=',today())->where('created_at','<=',now())->orderBy('id')->get()->map(fn($p)=>['id'=>$p->id,'amount'=>Money::normalize($p->amount),'payment_date'=>$p->transaction_date->toDateString(),'known_at'=>$p->created_at->toIso8601String()])->all();
   $e['customers'][]=['id'=>$c->id,'name'=>$c->name,'debt'=>Money::normalize($c->current_debt),'advance'=>Money::normalize($c->current_credit),'currency'=>$base,'history'=>$history,'recorded_payments'=>$payments,'ledger_outstanding'=>$openTotal,'url'=>'/customer-debts?customer='.$c->id];
   if(Money::compare($openTotal,$c->current_debt)!==0)$e['health'][]=['code'=>'customer_balance_mismatch','message'=>'Customer ledger allocation and summary differ; review the authoritative ledger.','customer_id'=>$c->id,'url'=>'/customer-debts?customer='.$c->id];
  }
  // Even fully allocated invoice-linked debts identify the economic event.
  $linkedInvoices=array_unique(array_merge($linkedInvoices,\App\Models\CustomerDebtTransaction::whereNotNull('invoice_id')->pluck('invoice_id')->all()));
  $linkedSales=\App\Models\CustomerDebtTransaction::whereNotNull('daily_sale_id')->whereIn('type',['debt_added','opening_balance','positive_adjustment'])->pluck('daily_sale_id')->unique()->all();
  foreach(Invoice::where('status','issued')->where('document_type','!=','credit_note')->whereNotIn('id',$linkedInvoices)->where(fn($q)=>$q->whereNull('daily_sale_id')->orWhereNotIn('daily_sale_id',$linkedSales))->with('customer')->get() as $i){
   if(Money::minor($i->remaining_balance)<=0)continue;$history=$i->customer?$timing->history($i->customer):['eligible'=>false,'count'=>0,'median_delay'=>null,'segment'=>'insufficient_history'];
   $prediction=$timing->predict($i->due_at?->toDateString(),$history,$model);
   $e['receivables'][]=$prediction+['key'=>'invoice:'.$i->id,'customer_id'=>$i->customer_id,'customer'=>$i->customer_name?:$i->customer?->name,'reference'=>$i->invoice_number,'amount'=>Money::normalize($i->remaining_balance),'original_amount'=>Money::normalize($i->grand_total),'allocated_reduction'=>Money::subtract($i->grand_total,$i->remaining_balance),'obligation_date'=>$i->invoice_date->toDateString(),'currency'=>$i->currency,'due_date'=>$i->due_at?->toDateString(),'source_type'=>'invoice','source_id'=>$i->id,'evidence_type'=>$prediction['timing_method']==='historical_median'?'predicted':'scheduled','segment'=>$history['segment'],'samples'=>$history['count'],'url'=>'/invoices?invoice='.$i->id];
  }
  $expenses=Expense::where('status','posted')->where('document_type','!=','credit_note')->with(['payments','purchaseOrderPaymentAllocations.payment','supplierCredits','supplier'])->get();
  foreach($expenses as $x){if(Money::minor($x->remaining_amount)<=0)continue;$e['commitments'][]=$this->schedule(['key'=>'expense:'.$x->id,'reference'=>$x->document_number?:'Expense #'.$x->id,'amount'=>Money::normalize($x->remaining_amount),'currency'=>$x->currency,'due_date'=>$x->due_date?->toDateString(),'expected_date'=>$x->due_date?->toDateString(),'source_type'=>'supplier_payable','source_id'=>$x->id,'supplier'=>$x->supplier?->name?:$x->vendor_name,'purchase_order_id'=>$x->purchase_order_id,'evidence_type'=>'scheduled','payment_basis'=>'fixed_date','url'=>'/finance?expense='.$x->id],$settings);}
  $orders=PurchaseOrder::whereNotIn('status',['draft','cancelled'])->with(['supplier','payments'])->get();
  foreach($orders as $po){
   $linked=$expenses->where('purchase_order_id',$po->id);$invoiced='0.00';$allocated='0.00';$fxUnknown=false;
   foreach($linked as $x){if($x->currency!==$po->currency){$fxUnknown=true;continue;}$invoiced=Money::add($invoiced,$x->gross_amount);$allocated=Money::add($allocated,$x->purchaseOrderPaymentAllocations->sum('amount'));}
   if($fxUnknown){$e['health'][]=['code'=>'po_invoice_currency_mismatch','message'=>'Linked PO and supplier document currencies differ. The uninvoiced PO remainder is excluded to prevent double counting without reliable settlement conversion.','url'=>'/purchase-orders?po='.$po->id];continue;}
   $unallocatedDeposit=Money::maximum(0,Money::subtract($po->total_paid,$allocated));$remaining=Money::maximum(0,Money::subtract($po->total_amount,Money::add($invoiced,$unallocatedDeposit)));
   if(Money::compare($invoiced,$po->total_amount)>0)$e['health'][]=['code'=>'po_invoice_mismatch','message'=>'Supplier documents exceed the linked PO value; supplier documents remain authoritative.','url'=>'/purchase-orders?po='.$po->id];
   if(Money::minor($remaining)>0)$e['commitments'][]=$this->schedule(['key'=>'po:'.$po->id,'reference'=>$po->po_number,'amount'=>$remaining,'currency'=>$po->currency,'due_date'=>$po->due_at?->toDateString(),'expected_date'=>$po->due_at?->toDateString(),'source_type'=>'purchase_order','source_id'=>$po->id,'supplier'=>$po->supplier?->name,'purchase_order_id'=>$po->id,'evidence_type'=>'scheduled','payment_basis'=>'fixed_date','url'=>'/purchase-orders?po='.$po->id],$settings);
  }
  // Preserve real payment dates, partial reductions and settled documents for
  // future validation. These observations do not add another cash event.
  $e['payable_observations']=[];
  foreach($expenses as $x)$e['payable_observations'][]=['key'=>'expense:'.$x->id,'obligation_date'=>$x->invoice_date->toDateString(),'due_date'=>$x->due_date?->toDateString(),'currency'=>$x->currency,'original_amount'=>Money::normalize($x->gross_amount),'remaining'=>Money::normalize($x->remaining_amount),'credits'=>$x->supplierCredits->map(fn($c)=>['id'=>$c->id,'amount'=>Money::normalize($c->gross_amount),'known_at'=>$c->created_at->toIso8601String()])->all(),'payments'=>$x->payments->where('status','completed')->map(fn($p)=>['id'=>$p->id,'amount'=>Money::normalize($p->amount),'payment_date'=>$p->payment_date->toDateString(),'known_at'=>$p->created_at->toIso8601String()])->values()->all(),'deposit_allocations'=>$x->purchaseOrderPaymentAllocations->map(fn($p)=>['payment_id'=>$p->purchase_order_payment_id,'amount'=>Money::normalize($p->amount),'payment_date'=>$p->payment?->payment_date?->toDateString(),'allocated_at'=>$p->allocated_at?->toIso8601String()])->all()];
  foreach($orders as $po)$e['payable_observations'][]=['key'=>'po:'.$po->id,'obligation_date'=>$po->ordered_at?->toDateString(),'due_date'=>$po->due_at?->toDateString(),'currency'=>$po->currency,'original_amount'=>Money::normalize($po->total_amount),'paid_amount'=>Money::normalize($po->total_paid),'payments'=>$po->payments->where('status','completed')->map(fn($p)=>['id'=>$p->id,'amount'=>Money::normalize($p->amount),'payment_date'=>$p->payment_date?->toDateString(),'known_at'=>$p->created_at->toIso8601String()])->values()->all()];
  foreach(PurchaseRequest::whereIn('status',['draft','submitted','approved','sourcing'])->get() as $r)$e['potential'][]=['stage'=>'purchase_request','id'=>$r->id,'reference'=>$r->request_number,'amount'=>Money::normalize($r->estimated_total),'currency'=>$r->currency,'status'=>$r->status,'included_in_forecast'=>false,'url'=>'/procurement?request='.$r->id];
  foreach(InventoryRecommendation::whereIn('status',['open','viewed'])->whereNotNull('planning_key')->latest('id')->get()->unique(fn($r)=>$r->product_id.':'.($r->warehouse_id??0)) as $r){$p=$r->explanation;if(($p['base_cost']??null)!==null)$e['potential'][]=['stage'=>'recommendation','id'=>$r->id,'product_id'=>$r->product_id,'reference'=>$p['product']['name']??'Product #'.$r->product_id,'amount'=>Money::normalize($p['base_cost']),'currency'=>$p['currency']??$base,'included_in_forecast'=>false,'url'=>'/inventory-intelligence?view=planning&product='.$r->product_id];}
  $e['inventory']=$this->capital($base);$e['landed_costs']=LandedCost::whereIn('status',['draft','posted'])->get()->map(fn($r)=>['id'=>$r->id,'reference'=>$r->reference_number,'amount'=>$r->amount,'currency'=>$r->currency,'classification'=>$r->status==='posted'?'allocated_accounting_cost':'recorded_estimate','cash_included'=>false,'qualification'=>'Valuation allocation is not a separate payable. A posted supplier document is needed for a cash obligation.'])->all();
  if(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'inventory.view')&&app(PermissionService::class)->roleHasPermission(Auth::user()->role,'analytics.view')){
   $e['scenario_options']=['products'=>Product::where('lifecycle_status','active')->orderBy('name')->get(['id','name','unit'])->toArray(),'suppliers'=>\App\Models\Supplier::where('is_active',true)->orderBy('name')->get(['id','name'])->toArray()];
  }
  foreach(['receivables','commitments'] as $k){$n=count(array_filter($e[$k],fn($r)=>empty($r['expected_date'])));if($n)$e['health'][]=['code'=>$k.'_undated','message'=>$n.' outstanding records have no reliable future date and are excluded from dated cash projections.','count'=>$n,'url'=>$k==='receivables'?'/customer-debts':'/purchase-orders'];}
  $sparse=count(array_filter($e['customers'],fn($c)=>Money::minor($c['debt'])>0&&!$c['history']['eligible']));if($sparse)$e['health'][]=['code'=>'payment_history_sparse','message'=>'Insufficient history for ML-supported prediction on '.$sparse.' customers. Due-date baseline retained.','count'=>$sparse,'url'=>'/customer-debts'];
  if($e['inventory']['unvalued_products'])$e['health'][]=['code'=>'inventory_unvalued','message'=>'Some products have no recorded inventory valuation. Inventory totals are the known-valued subtotal only.','count'=>$e['inventory']['unvalued_products'],'url'=>'/stock'];
  $e['working_capital']=['state'=>'partial','qualification'=>'Partial working-capital view. Current-account classification, all external accounts, average inventory/receivables/payables and compatible credit sales/COGS are not established.','dso'=>null,'dio'=>null,'dpo'=>null,'cash_conversion_cycle'=>null];
  $e['local_model']=app(LocalFinancialTimingProvider::class)->analyze($e['customers']);
  if($e['local_model']['state']==='unavailable')$e['health'][]=['code'=>'local_model_unavailable','message'=>$e['local_model']['qualification'],'url'=>'/financial-intelligence'];
  $e['totals']=[];foreach(array_unique(array_merge(array_column($e['receivables'],'currency'),array_column($e['commitments'],'currency'),[$base])) as $currency){$ar=$payable=$po=$advance='0.00';foreach($e['receivables'] as $r)if($r['currency']===$currency)$ar=Money::add($ar,$r['amount']);foreach($e['commitments'] as $r)if($r['currency']===$currency){if($r['source_type']==='purchase_order')$po=Money::add($po,$r['amount']);else $payable=Money::add($payable,$r['amount']);}foreach($e['customers'] as $c)if($c['currency']===$currency)$advance=Money::add($advance,$c['advance']);$e['totals'][$currency]=['recorded_receivables'=>$ar,'supplier_payables'=>$payable,'uninvoiced_po_commitments'=>$po,'customer_advances'=>$advance];}
  $e['confidence']=$e['health']?'limited':'moderate';return $e;
 }
 private function schedule(array $r,array $settings):array {
  $term=$settings['terms'][$r['key']]??null;$r['documented_terms']=$term;
  if($term&&$term['basis']==='arrival'){
   $s=Shipment::find($term['shipment_id']);$int=$s?ShipmentIntelligence::where('shipment_id',$s->id)->where('is_current',true)->latest('id')->first():null;
   $related=$s&&($s->purchase_order_id==$r['purchase_order_id']||$s->purchaseOrders()->whereKey($r['purchase_order_id'])->exists());
   $eta=$related&&$int&&$int->eta['target']==='warehouse'&&$int->checked_at->gte(now()->subHours(24))?($int->eta['predicted']??null):null;
   $r['payment_basis']='arrival';$r['shipment_id']=$s?->id;
   $r['expected_date']=$eta?Date::parse($eta)->addDays((int)($term['offset_days']??0))->toDateString():null;
   $r['evidence_type']=$eta?'predicted':'scheduled';$r['timing_qualification']=$eta?'Arrival-dependent documented terms; V6 warehouse ETA, not a contractual due-date change.':'Warehouse arrival evidence unavailable; payment date unknown.';
  }
  if($r['expected_date']&&$r['expected_date']<today()->toDateString()){$r['overdue']=true;$r['expected_date']=today()->toDateString();$r['timing_qualification']='Overdue payable shown as immediate cash requirement, not a guaranteed payment.';}
  return $r;
 }
 private function capital(string $currency):array {
  $rows=[];$categories=[];$warehouses=[];$total='0.00';$missing=0;
  $plans=InventoryRecommendation::whereNotNull('planning_key')->whereIn('status',['open','viewed'])->latest('id')->get()->unique('product_id')->keyBy('product_id');
  foreach(Product::with(['category','warehouseStock.warehouse'])->get() as $p){if($p->inventory_value===null){$missing++;continue;}$value=Money::normalize($p->inventory_value);$total=Money::add($total,$value);$plan=$plans->get($p->id)?->explanation??[];$unitCost=$p->weighted_average_cost;
   $excess=$unitCost!==null&&isset($plan['excess_quantity'])?Money::multiply(min(max(0,$p->quantity),max(0,$plan['excess_quantity'])),$unitCost):null;$slow=($plan['optimization_state']??null)==='slow_moving';
   $rows[]=['id'=>$p->id,'name'=>$p->name,'quantity'=>$p->quantity,'unit'=>$p->unit,'value'=>$value,'excess_value'=>$excess,'slow_moving_value'=>$slow?$value:null,'planned_purchase_despite_excess'=>Money::minor($excess??0)>0&&($plan['base_quantity']??0)>0,'url'=>'/inventory-intelligence?view=planning&product='.$p->id];
   $key=$p->category?->name??'Uncategorised';$categories[$key]=Money::add($categories[$key]??0,$value);
   foreach($p->warehouseStock as $stock){$name=$stock->warehouse?->name??'Unknown warehouse';if($unitCost!==null)$warehouses[$name]=Money::add($warehouses[$name]??0,Money::multiply($stock->quantity,$unitCost));}
  }
  usort($rows,fn($a,$b)=>Money::compare($b['value'],$a['value']));
  return ['currency'=>$currency,'total'=>!$rows&&$missing?null:$total,'products'=>$rows,'categories'=>$categories,'warehouses'=>$warehouses,'unvalued_products'=>$missing,'warehouse_qualification'=>'Quantity × existing weighted-average unit cost; product inventory_value remains the valuation authority. Unlocated inventory is not assigned to a warehouse.','qualification'=>'Slow/excess stock remains an asset. No write-down or lost-money claim.'];
 }
}
