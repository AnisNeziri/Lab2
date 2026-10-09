<?php
namespace App\Services;

use App\Models\{Customer,Product,Shipment};
use Illuminate\Support\Facades\{Auth,Date};

/** Thin read-only adapters around V4/V6/V7 calculations, never a new prediction engine. */
final class AssistantScenarioService
{
    private function can(string $p):bool{return app(PermissionService::class)->roleHasPermission(Auth::user()->role,$p);}
    public function customerDelay(array $input):array
    {
        $v=validator($input,['customer_id'=>'required|integer|min:1','delay_days'=>'required|integer|min:1|max:180'])->validate();
        abort_unless($this->can('debts.view'),403);Customer::findOrFail($v['customer_id']);
        $f=app(FinancialIntelligenceService::class)->latest();abort_unless($f['state']==='ready',409,'No saved financial evidence exists yet.');$e=$f['evidence'];$count=0;
        foreach($e['receivables'] as &$r)if(($r['customer_id']??null)==$v['customer_id']&&$r['expected_date']){$r['expected_date']=Date::parse($r['expected_date'])->addDays($v['delay_days'])->toDateString();$r['evidence_type']='scenario';$count++;}unset($r);
        return ['base'=>$f['forecast'],'scenario'=>app(FinancialForecastMath::class)->calculate($e,30),'changed_obligations'=>$count,'as_of'=>$f['as_of'],'stale'=>$f['stale'],'read_only'=>true,'qualification'=>'Hypothetical delay of this customer’s recorded collections only. Advances are not invented as new cash receipts. Other customers and all business records are unchanged.'];
    }
    public function shipmentDelay(array $input):array
    {
        $v=validator($input,['shipment_id'=>'required|integer|min:1','delay_days'=>'required|integer|min:1|max:180'])->validate();
        $d=app(ShipmentIntelligenceService::class)->detail($v['shipment_id']);$s=Shipment::findOrFail($v['shipment_id']);$po=$s->purchaseOrders()->pluck('purchase_orders.id')->all();if($s->purchase_order_id)$po[]=$s->purchase_order_id;
        $rows=[];
        if($this->can('analytics.view')&&$this->can('inventory.view'))foreach(array_slice($d['impact']['products']??[],0,5) as $p){
            $id=$p['product']['id']??$p['product_id']??$p['id']??null;if(!$id)continue;$bundle=app(InventoryPlanningService::class)->decisionEvidence($id,['warehouse_id'=>$p['warehouse']['id']??null]);$i=$bundle['input'];$base=app(InventoryPlanningMath::class)->calculate($i,['base_quantity'=>0]);$count=0;
            foreach($i['firm_incoming'] as &$r)if(in_array($r['purchase_order_id']??null,$po)&&!empty($r['expected_at'])){$r['expected_at']=Date::parse($r['expected_at'])->addDays($v['delay_days'])->toDateString();$count++;}unset($r);
            $after=$count?app(InventoryPlanningMath::class)->calculate($i,['base_quantity'=>0]):null;
            $rows[]=['name'=>$i['product']['name'],'product_id'=>$id,'unit'=>$i['product']['unit'],'shifted_receipts'=>$count,'baseline_stockout'=>$base['scenario_stockout_date']??null,'scenario_stockout'=>$after['scenario_stockout_date']??null,'baseline_stockout_days'=>$base['scenario_stockout_days']??null,'scenario_stockout_days'=>$after['scenario_stockout_days']??null,'state'=>$count?'supported':'no_dated_firm_receipt','url'=>'/inventory-intelligence?product='.$id];
        }
        $cash=null;if($this->can('analytics.finance')&&$this->can('finance.view')&&$this->can('financial_accounts.view')){
            $f=app(FinancialIntelligenceService::class)->latest();if($f['state']==='ready'){$e=$f['evidence'];$n=0;foreach($e['commitments'] as &$r)if(($r['shipment_id']??null)==$s->id&&$r['payment_basis']==='arrival'&&$r['expected_date']){$r['expected_date']=Date::parse($r['expected_date'])->addDays($v['delay_days'])->toDateString();$n++;}unset($r);$cash=['base'=>$f['forecast'],'scenario'=>app(FinancialForecastMath::class)->calculate($e,30),'changed_obligations'=>$n,'stale'=>$f['stale']];}
        }
        $eta=$d['eta']['predicted']??null;
        return ['rows'=>$rows,'shipment_id'=>$s->id,'baseline_eta'=>$eta,'scenario_eta'=>$eta?Date::parse($eta)->addDays($v['delay_days'])->toDateString():null,'cash'=>$cash,'read_only'=>true,'stale'=>$d['stale']??true,'qualification'=>'Hypothetical delay, not a new vessel prediction. Only dated firm receipts linked to this shipment’s POs and documented arrival-linked payments are shifted. Product results are limited to five. Missing warehouse dates or demand remain unknown.'];
    }
    public function capacity(array $input):array
    {
        $v=validator($input,['product_id'=>'required|integer|min:1','customer_id'=>'required|integer|min:1','quantity'=>'required|numeric|gt:0|max:100000000'])->validate();
        abort_unless($this->can('inventory.view')&&$this->can('customers.manage'),403);$p=Product::findOrFail($v['product_id']);$c=Customer::findOrFail($v['customer_id']);app(UnitConversionService::class)->assertPrecision((float)$v['quantity'],$p->unit);
        $stock=app(InventorySnapshotService::class)->forProduct($p);$plan=$this->can('analytics.view')?app(InventoryPlanningService::class)->view($p->id):null;
        $credit=$this->can('debts.view')?app(CustomerCreditService::class)->exposure($c):null;
        return ['name'=>$p->name,'customer'=>$c->name,'quantity'=>$v['quantity'],'unit'=>$p->unit,'available_to_promise'=>$stock['available_to_promise'],'can_fulfil_now'=>(float)$v['quantity']<=(float)$stock['available_to_promise'],'incoming_schedule'=>$stock['incoming_schedule'],'planning'=>$plan['plan']??null,'customer_credit'=>$credit,'read_only'=>true,'qualification'=>'Physical availability only, not an order reservation or credit authorization. Incoming dates are recorded, not guaranteed. No selling price or debt amount is assumed; existing order credit checks remain authoritative.'];
    }
}
