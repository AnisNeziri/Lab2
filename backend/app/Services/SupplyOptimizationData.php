<?php
namespace App\Services;
use App\Models\{Product,Warehouse,Shipment,FinancialIntelligenceSnapshot,CustomerSalesSnapshot};
use App\Support\CompanyCurrency;

/** Point-in-time adapter; operational and forecasting authorities stay in V4–V8. */
class SupplyOptimizationData {
 public function collect(array $scope):array {
  $query=Product::query()->where('lifecycle_status','active')->orderBy('id');
  foreach(['product_ids'=>'id','category_ids'=>'category_id'] as $key=>$column)if($scope[$key]??[])$query->whereIn($column,$scope[$key]);
  if($scope['supplier_ids']??[])$query->whereHas('supplierCatalogue',fn($q)=>$q->whereIn('supplier_id',$scope['supplier_ids'])->where('is_active',true));
  $products=$query->limit(41)->get();abort_unless($products->count()&&$products->count()<=40,422,'Select between 1 and 40 products; narrow the scope for larger catalogues.');
  $warehouses=$scope['warehouse_ids']??[];
  if($warehouses)abort_unless(Warehouse::where('is_active',true)->whereIn('id',$warehouses)->count()===count($warehouses),422,'Select active warehouses belonging to this company.');
  $rows=[];$resources=[];$donors=[];$math=app(InventoryPlanningMath::class);$h=$scope['horizon'];
  foreach($products as $p)foreach($warehouses?:[null] as $w){
   $e=app(EnterpriseDecisionEvidence::class)->assemble($p->id,$w);$input=$e['learning_input'];
   $input['daily']=array_slice($e['plan']['daily'],0,$h);$input['policy']['review_days']=max(1,$h-(int)($e['plan']['lead_days']??0)-1);
   $eligible=array_values(array_filter($input['suppliers'],fn($s)=>!($scope['supplier_ids']??[])||in_array($s['supplier_id'],$scope['supplier_ids'])));
   $transfers=[];
   if($w&&($scope['allow_transfers']??true))foreach(Warehouse::where('is_active',true)->whereKeyNot($w)->orderBy('id')->limit(20)->get() as $donor){
    $key=$p->id.':'.$donor->id;
    if(!array_key_exists($key,$donors)){
     $di=app(InventoryPlanningData::class)->input($p,['warehouse_id'=>$donor->id]);$dp=$math->calculate($di,['base_quantity'=>0]);
     $path=array_slice($dp['timeline'],0,$h);$floor=$path?min(array_column($path,'baseline')):null;
     // Never export stock based only on an arbitrary allocation share or an unknown donor forecast.
     $qualified=$di['scope']==='qualified_warehouse'&&$dp['quality']['qualified']&&count($path)>=$h;
     $surplus=$qualified?max(0,min($di['stock']['available_to_promise'],$floor-$dp['safety_stock'])):0;
     $surplus=$di['base_fractional']?floor($surplus*1000)/1000:floor($surplus);
     $donors[$key]=['quantity'=>$surplus,'input'=>$di,'fingerprint'=>$this->fingerprint($di,[])];$resources[$key]=(int)round($surplus*1000);
    }
    if($donors[$key]['quantity']<=0)continue;
    $days=app(InventoryPlanningData::class)->transferLead($donor->id,$w);$assumed=false;
    if($days===null&&isset($scope['transfer_lead_days'])){$days=$scope['transfer_lead_days'];$assumed=true;}
    if($days===null)continue; // Unknown arrival cannot protect a dated shortage.
    $transfers[]=['resource'=>$key,'source_warehouse_id'=>$donor->id,'source_warehouse_name'=>$donor->name,'destination_warehouse_id'=>$w,'available'=>$donors[$key]['quantity'],'expected_at'=>today()->addDays($days)->toDateString(),'assumed_route_days'=>$assumed];
   }
   $rows[]=['key'=>$p->id.':'.($w??0),'product'=>$input['product'],'warehouse'=>$input['warehouse'],'input'=>$input,'suppliers'=>$eligible,'supplier_risk'=>$e['evidence']['supplier_options'],
    'transfers'=>$transfers,'existing_requests'=>$e['evidence']['procurement']['requests'],'logistics'=>$e['evidence']['logistics'],'confidence'=>$e['confidence'],
    'fingerprint'=>$this->fingerprint($input,$e['evidence']['procurement']['requests']),'severity'=>$e['severity']];
  }
  $shipmentIds=collect($rows)->flatMap(fn($r)=>array_column($r['logistics'],'id'))->unique();
  $shipmentLinks=Shipment::with('purchaseOrders')->whereIn('id',$shipmentIds)->get()->map(fn($s)=>['id'=>$s->id,'reference'=>$s->tracking_number,'purchase_order_ids'=>array_values(array_unique(array_filter(array_merge([$s->purchase_order_id],$s->purchaseOrders->pluck('id')->all()))))])->all();
  $currency=CompanyCurrency::current();$financial=FinancialIntelligenceSnapshot::whereNull('evidence->archived')->latest('id')->first();
  $customer=CustomerSalesSnapshot::whereNull('evidence->archived')->latest('id')->first();
  return ['cutoff'=>now()->toIso8601String(),'scope'=>$scope,'rows'=>$rows,'resources'=>$resources,'donors'=>$donors,'shipment_links'=>$shipmentLinks,'currency'=>$currency,
   'policy'=>app(DecisionLearningService::class)->currentPolicy(),'finance'=>app(InventoryPlanningInsights::class)->finance($currency),
   'v7'=>$financial?['id'=>$financial->id,'as_of'=>$financial->as_of->toDateString(),'forecast'=>$financial->forecast[$h]??null,'evidence'=>$financial->evidence]:null,
   'v8'=>$customer?['snapshot_id'=>$customer->id,'qualification'=>'Customer intelligence is context only; canonical reservations and sales commitments already reduce availability.']:null,
   'limitations'=>['Supplier capacity and contractual supplier order minimum not recorded.','Container dimensions/cost savings unavailable; consolidation is an opportunity, not a claimed saving.','Holding, lost-sales and stockout monetary costs not recorded.','Catalogue prices are estimates, not accepted supplier quotes.','Undated/risky incoming without a qualified ETA is excluded, not silently counted as on-time.','Warehouse forecasts require qualified local observations or an explicit existing allocation policy.']];
 }
 public function fingerprint(array $i,array $requests):string {return hash('sha256',json_encode([$i,$requests]));}
 public function material(array $a,array $b):bool {
  if($a['policy']['version']!==$b['policy']['version']||count($a['rows'])!==count($b['rows']))return true;
  foreach($a['rows'] as $n=>$r){$old=$r['input'];$new=$b['rows'][$n]['input'];
   if($r['key']!==$b['rows'][$n]['key']||json_encode($old['suppliers'])!==json_encode($new['suppliers'])||json_encode($old['firm_incoming'])!==json_encode($new['firm_incoming'])||json_encode($r['existing_requests'])!==json_encode($b['rows'][$n]['existing_requests'])||json_encode($old['policy'])!==json_encode($new['policy']))return true;
   foreach(['minimum_safety','reorder_floor','unit','factor','unit_fractional','base_fractional','lead','forecast_error','landed_unit_allowance','currency'] as $key)if(json_encode($old[$key]??null)!==json_encode($new[$key]??null))return true;
   $v=(float)$old['stock']['available_to_promise'];$nv=(float)$new['stock']['available_to_promise'];
   if(abs($v-$nv)>=max(1,abs($v)*.1)||($v>0&&$nv<=0))return true;
   $d=array_sum(array_column($old['daily'],'quantity'));$nd=array_sum(array_column($new['daily'],'quantity'));
   if(abs($d-$nd)>=max(1,$d*.1))return true;
  }
  return $a['resources']!==$b['resources'];
 }
}
