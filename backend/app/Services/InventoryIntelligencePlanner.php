<?php
namespace App\Services;
use App\Models\{Product,ProductSupplier};
use App\Support\{CompanyCurrency,Money};
use Carbon\CarbonImmutable as Date;

final class InventoryIntelligencePlanner {
    public function suppliers(Product $product):array {
        return $product->supplierCatalogue()->where('is_active',true)->whereHas('supplier',fn($q)=>$q->where('is_active',true))
            ->with('supplier')->orderByDesc('is_preferred')->get()->map(function($row)use($product){
                return $row->only(['id','supplier_id','purchase_price','currency','exchange_rate_to_base','usual_lead_time_days','minimum_order_quantity','pack_size','is_preferred'])
                    +['name'=>$row->supplier->name,'performance'=>app(SupplierPerformanceService::class)->scorecard($row->supplier),
                      'lead_evidence'=>app(SupplierHistoryService::class)->distribution(app(SupplierHistoryService::class)->orders($row->supplier),$product->id)];
            })->all();
    }
    public function calculate(Product $product,array $daily,array $suppliers,?int $supplierId=null,?string $unit=null):array {
        $stock=app(InventorySnapshotService::class)->forProduct($product);
        $supplier=collect($suppliers)->firstWhere('supplier_id',$supplierId)??($supplierId?null:($suppliers[0]??null));
        abort_if($supplierId && !$supplier,422,'The selected supplier is no longer active for this product.');
        $unit=$unit?:$product->unit;$conversion=app(UnitConversionService::class)->resolve($product,1,$unit);
        $factor=(float)($conversion['conversion_factor']??1);
        $historicalLead=($supplier['lead_evidence']['eligible']??false)?$supplier['lead_evidence']['p90_days']:null;
        $lead=isset($supplier['usual_lead_time_days'])?(int)$supplier['usual_lead_time_days']:($historicalLead===null?null:(int)ceil($historicalLead));
        if($historicalLead!==null)$lead=max($lead??0,(int)ceil($historicalLead));
        $review=max(1,(int)($product->replenishment_review_days??14));
        $today=today()->toDateString();$daily=array_values(array_filter($daily,fn($d)=>$d['date']>=$today));
        $coverageEnd=$daily?end($daily)['date']:null;
        $schedule=$stock['incoming_schedule']??[];
        // Re-read authoritative milestones: a stale advisory row must not keep a recovered PO excluded.
        $atRisk=[];$history=app(SupplierHistoryService::class);$signals=app(SupplierIntelligenceService::class);
        foreach(\App\Models\PurchaseOrder::with(['items.product','goodsReceipts.items','changes'])->whereIn('id',array_column($schedule,'purchase_order_id'))->get() as $po){
            $row=$history->order($po);if($signals->assess($row,['eligible'=>false,'samples'=>0])['risk']==='high')$atRisk[]=$po->id;
        }
        $firm=array_values(array_filter($schedule,fn($r)=>!empty($r['expected_at'])&&$r['expected_at']>=$today&&!in_array($r['purchase_order_id']??null,$atRisk)));
        $uncertain=round(collect($schedule)->filter(fn($r)=>empty($r['expected_at'])||$r['expected_at']<$today||in_array($r['purchase_order_id']??null,$atRisk))->sum('quantity'),3);
        // Available already excludes reservations. Only unreserved customer
        // commitments are additionally deducted, never reservations twice.
        $available=(float)$stock['available'];$backorders=(float)($stock['committed_outgoing']??0);
        $balance=$available-$backorders;$stockout=$balance<0?$today:null;$path=[];
        foreach($daily as $d){$balance+=collect($firm)->where('expected_at',$d['date'])->sum('quantity');$balance-=$d['quantity'];
            if($balance<-.0005 && !$stockout)$stockout=$d['date'];$path[]=['date'=>$d['date'],'projected_available'=>round($balance,3)];}
        $targetDate=$lead===null?null:Date::parse($today)->addDays($lead+$review)->toDateString();
        $supported=$targetDate&&$coverageEnd&&$targetDate<=$coverageEnd;
        $leadDate=$lead===null?null:Date::parse($today)->addDays($lead)->toDateString();
        $risk=!$daily?'insufficient_data':($stockout&&$leadDate&&$stockout<=$leadDate?'high':($stockout?'watch':($uncertain>0?'uncertain_inbound':'normal')));
        $safety=max((float)($product->safety_stock??0),(float)($product->min_quantity??0));
        $demand=$supported?collect($daily)->where('date','<=',$targetDate)->sum('quantity'):null;
        $incoming=$supported?collect($firm)->where('expected_at','<=',$targetDate)->sum('quantity'):0;
        $raw=$demand===null?null:max(0,$demand+$safety+$backorders-$available-$incoming);
        $baseQuantity=$raw;
        if($raw!==null && $raw>0){
            $pack=max(.001,(float)($supplier['pack_size']??1));$moq=(float)($supplier['minimum_order_quantity']??0);
            // Catalogue pack/MOQ are base inventory units; fixed purchase packs
            // and their multiples must both hold, using integer milli-units.
            $step=$this->lcm((int)round($pack*1000),(int)round($factor*1000));
            if(in_array(strtolower($unit),['m','meter','metre','meters','metres','metër','metra'],true))$step=(int)round($pack*1000);
            $baseQuantity=ceil(max($raw,$moq)*1000/max(1,$step))*max(1,$step)/1000;
            app(UnitConversionService::class)->resolve($product,$baseQuantity/$factor,$unit);
        }
        $quantity=$baseQuantity===null?null:round($baseQuantity/$factor,3);
        $price=$supplier['purchase_price']??null;$cost=$price===null||$baseQuantity===null?null:Money::multiply($baseQuantity,$price);
        $reorder=$stockout&&$lead!==null?Date::parse($stockout)->subDays($lead)->max(today())->toDateString():null;
        $result=['available'=>$available,'reserved'=>(float)$stock['reserved'],'unreserved_backorders'=>$backorders,'incoming'=>(float)$stock['incoming'],
            'incoming_schedule'=>$schedule,'uncertain_incoming'=>$uncertain,'stockout_date'=>$stockout,'risk'=>$risk,'lead_time_days'=>$lead,
            'lead_time_source'=>$historicalLead!==null?'completed_receipts_p90':(isset($supplier['usual_lead_time_days'])?'supplier_catalogue':'unknown'),
            'supplier_lead_evidence'=>$supplier['lead_evidence']??null,'at_risk_purchase_orders'=>$atRisk,
            'review_days'=>$review,'safety_stock'=>$safety,'service_level'=>null,'forecast_coverage_end'=>$coverageEnd,'coverage_supported'=>(bool)$supported,
            'reorder_date'=>$reorder,'expected_date'=>$leadDate,'base_quantity'=>$baseQuantity,'quantity'=>$quantity,'unit'=>$unit,'factor'=>$factor,
            'supplier_id'=>$supplier['supplier_id']??null,'supplier_name'=>$supplier['name']??null,'unit_price'=>$price===null?null:Money::multiply($price,$factor),
            'estimated_cost'=>$cost,'currency'=>$supplier['currency']??CompanyCurrency::current(),'projected_stock'=>$path,
            'assumptions'=>['gross_fulfilled_demand','sampled_availability','reservations_already_excluded','unreserved_backorders_deducted','dated_po_receipts_are_estimates','overdue_undated_receipts_excluded','fixed_safety_stock_not_calibrated_service_level','no_automatic_purchase'],
            'formula'=>'max(0, forecast(lead+review) + max(minimum,safety) + unreserved_backorders - available - dated_incoming), rounded up to MOQ and fixed pack multiples'];
        $result['fingerprint']=hash('sha256',json_encode([$product->id,$product->unit,$stock,collect($supplier??[])->except('performance')->all(),$result]));
        return $result;
    }
    private function lcm(int $a,int $b):int {$a=max(1,$a);$b=max(1,$b);$x=$a;$y=$b;while($y){$t=$y;$y=$x%$y;$x=$t;}return (int)($a/$x*$b);}
}
