<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\InventoryReturnItem;
use App\Models\ProcurementAward;
use App\Models\PurchaseOrder;
use App\Models\QualityDefect;
use App\Models\QualityInspection;
use App\Models\RfqSupplier;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use App\Models\ProductSupplier;
use App\Models\SupplierQuote;
use App\Models\SupplierQuoteItem;
use App\Models\SupplierScoreSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupplierPerformanceService
{
    public function scorecard(Supplier $supplier): array
    {
        $delivery = $this->delivery($supplier);
        $quality = $this->quality($supplier);
        $commercial = $this->commercial($supplier);
        $reliability = $this->reliability($delivery, $quality, $commercial);
        $settings = SupplierScoreSetting::query()->first() ?? new SupplierScoreSetting([
            'company_id'=>$supplier->company_id,'quality_weight'=>35,'delivery_weight'=>30,
            'commercial_weight'=>20,'reliability_weight'=>15,
        ]);
        $categories = [
            'QUALITY'=>['score'=>$quality['score'],'weight'=>(float)$settings->quality_weight],
            'DELIVERY'=>['score'=>$delivery['score'],'weight'=>(float)$settings->delivery_weight],
            'COMMERCIAL'=>['score'=>$commercial['score'],'weight'=>(float)$settings->commercial_weight],
            'RELIABILITY'=>['score'=>$reliability['score'],'weight'=>(float)$settings->reliability_weight],
        ];
        $available = collect($categories)->filter(fn($row)=>$row['score']!==null);
        $weight = (float)$available->sum('weight');
        $overall = $weight <= 0 ? null : round((float)$available->sum(fn($row)=>$row['score']*$row['weight'])/$weight,1);

        return [
            'supplier_id'=>$supplier->id,'supplier_name'=>$supplier->name,'overall_score'=>$overall,
            'data_status'=>$overall===null?'insufficient_data':'available','category_scores'=>$categories,
            'delivery'=>$delivery,'quality'=>$quality,'commercial'=>$commercial,'reliability'=>$reliability,
            'explanation'=>$this->explain($overall,$delivery,$quality,$commercial,$reliability),
            'weights'=>['quality'=>(float)$settings->quality_weight,'delivery'=>(float)$settings->delivery_weight,'commercial'=>(float)$settings->commercial_weight,'reliability'=>(float)$settings->reliability_weight],
        ];
    }

    public function ranking(int $limit=10): array
    {
        return Supplier::query()->where('is_active',true)->get()->map(fn($supplier)=>$this->scorecard($supplier))
            ->filter(fn($card)=>$card['quality']['defect_rate']!==null)->sortByDesc(fn($card)=>$card['quality']['defect_rate'])->take($limit)->values()->all();
    }

    public function updateWeights(array $data): array
    {
        $sum=array_sum(array_map('floatval',$data));
        if(abs($sum-100)>.01) throw \Illuminate\Validation\ValidationException::withMessages(['weights'=>['Supplier score weights must total 100%.']]);
        $settings=SupplierScoreSetting::query()->updateOrCreate(['company_id'=>Auth::user()->company_id],[...$data,'updated_by'=>Auth::id()]);
        \App\Models\ActivityLog::create(['company_id'=>Auth::user()->company_id,'user_id'=>Auth::id(),'action'=>'quality.score_weights.updated','entity'=>'SupplierScoreSetting','entity_id'=>$settings->id,'description'=>'Supplier performance weights updated.','new_value'=>$data]);
        return $settings->toArray();
    }

    private function delivery(Supplier $supplier): array
    {
        $orders=PurchaseOrder::query()->with(['items','goodsReceipts'])->where('supplier_id',$supplier->id)->whereNotIn('status',['draft','cancelled'])->get();
        $completed=$orders->whereIn('status',['received','completed']);$ordered=(float)$orders->flatMap->items->sum(fn($item)=>(float)($item->base_quantity?:$item->quantity));
        $received=(float)$orders->flatMap->items->sum(fn($item)=>(float)($item->received_base_quantity?:$item->received_quantity));
        $completedOrdered=(float)$completed->flatMap->items->sum(fn($item)=>(float)($item->base_quantity?:$item->quantity));
        $completedReceived=(float)$completed->flatMap->items->sum(fn($item)=>(float)($item->received_base_quantity?:$item->received_quantity));
        $assessed=[];$lead=[];$lateDays=[];
        foreach($orders as $order){
            $actual=$order->goodsReceipts->sortByDesc('received_at')->first()?->received_at;
            if($order->ordered_at&&$actual)$lead[]=$order->ordered_at->startOfDay()->diffInDays($actual->startOfDay());
            if($order->expected_at&&$actual){$late=max(0,$order->expected_at->startOfDay()->diffInDays($actual->startOfDay(),false));$assessed[]=$late===0;$lateDays[]=$late;}
        }
        $onTime=$assessed===[]?null:round(100*collect($assessed)->filter()->count()/count($assessed),1);
        $fill=$completedOrdered>0?round(min(100,100*$completedReceived/$completedOrdered),1):null;
        $score=$onTime===null&&$fill===null?null:round(collect([$onTime,$fill])->filter(fn($v)=>$v!==null)->avg(),1);
        return ['score'=>$score,'purchase_orders'=>$orders->count(),'completed_purchase_orders'=>$completed->count(),'total_purchased_value'=>round((float)$orders->sum(fn($o)=>(float)($o->total_amount_eur?:((float)$o->total_amount*(float)($o->exchange_rate?:1)))),2),'average_lead_time_days'=>$lead===[]?null:round(array_sum($lead)/count($lead),1),'on_time_delivery_percent'=>$onTime,'average_days_late'=>$lateDays===[]?null:round(array_sum($lateDays)/count($lateDays),1),'quantity_ordered'=>round($ordered,3),'quantity_received'=>round($received,3),'short_delivery_rate'=>$completedOrdered>0?round(100*max(0,$completedOrdered-$completedReceived)/$completedOrdered,1):null];
    }

    private function quality(Supplier $supplier): array
    {
        $inspections=QualityInspection::query()->where('supplier_id',$supplier->id)->whereNotNull('finalized_at')->get();
        $received=(float)$inspections->sum('received_quantity');$accepted=(float)$inspections->sum('accepted_quantity');$rejected=(float)$inspections->sum('rejected_quantity');$quarantine=(float)$inspections->sum('quarantine_quantity');
        $defects=QualityDefect::query()->where('supplier_id',$supplier->id);$defectCount=(clone $defects)->count();$critical=(clone $defects)->where('severity','CRITICAL')->count();
        $claims=SupplierClaim::query()->where('supplier_id',$supplier->id);$claimCount=(clone $claims)->count();$claimValue=(float)(clone $claims)->sum('affected_value');
        $returnQty=(float)InventoryReturnItem::query()->whereHas('inventoryReturn',fn($q)=>$q->where('type','supplier')->where('supplier_id',$supplier->id)->where('status','completed'))->sum('processed_quantity');
        $acceptance=$received>0?round(100*$accepted/$received,1):null;$defectRate=$received>0?round(100*(float)(clone $defects)->sum('affected_quantity')/$received,1):null;
        $score=$acceptance===null?null:round(max(0,min(100,$acceptance-($critical*5))),1);
        return ['score'=>$score,'inspections'=>$inspections->count(),'received_quantity'=>round($received,3),'accepted_quantity'=>round($accepted,3),'rejected_quantity'=>round($rejected,3),'quarantine_quantity'=>round($quarantine,3),'defect_count'=>$defectCount,'defect_rate'=>$defectRate,'critical_defect_count'=>$critical,'claim_count'=>$claimCount,'claim_value'=>round($claimValue,2),'return_quantity'=>round($returnQty,3),'acceptance_percent'=>$acceptance];
    }

    private function commercial(Supplier $supplier): array
    {
        $invited=RfqSupplier::query()->where('supplier_id',$supplier->id)->count();
        $quotes=SupplierQuote::query()->with('items')->where('supplier_id',$supplier->id)->get();
        $responded=$quotes->pluck('rfq_id')->unique()->count();$response=$invited>0?round(100*$responded/$invited,1):null;
        $lead=$quotes->flatMap->items->pluck('lead_time_days')->filter(fn($v)=>$v!==null)->map(fn($v)=>(float)$v);
        $awards=ProcurementAward::query()->whereHas('quoteItem.supplierQuote',fn($q)=>$q->where('supplier_id',$supplier->id))->count();
        $ratios=[];
        foreach($quotes as $quote){foreach($quote->items as $item){$normalized=(float)$item->unit_price*(float)($quote->exchange_rate?:1);$minimum=SupplierQuoteItem::query()->where('purchase_request_item_id',$item->purchase_request_item_id)->whereHas('supplierQuote',fn($q)=>$q->where('rfq_id',$quote->rfq_id))->join('supplier_quotes','supplier_quotes.id','=','supplier_quote_items.supplier_quote_id')->selectRaw('MIN(supplier_quote_items.unit_price * COALESCE(supplier_quotes.exchange_rate,1)) AS minimum')->value('minimum');if($minimum&&$normalized>0)$ratios[]=min(100,100*(float)$minimum/$normalized);}}
        $competitiveness=$ratios===[]?null:round(array_sum($ratios)/count($ratios),1);
        $validity=$quotes->isEmpty()?null:round(100*$quotes->filter(fn($q)=>!$q->valid_until||$q->valid_until->gte($q->created_at->startOfDay()))->count()/$quotes->count(),1);
        $priceMoves=ProductSupplier::query()->with('priceHistory')->where('supplier_id',$supplier->id)->get()->map(function($catalogue){$history=$catalogue->priceHistory->sortBy([['effective_at','asc'],['id','asc']])->values();if($history->count()<2)return null;$first=(float)$history->first()->base_currency_price;$last=(float)$history->last()->base_currency_price;return $first>0?round(100*($last-$first)/$first,1):null;})->filter(fn($v)=>$v!==null);
        $priceMovement=$priceMoves->isEmpty()?null:round($priceMoves->avg(),1);
        $score=collect([$response,$competitiveness,$validity])->filter(fn($v)=>$v!==null)->avg();
        return ['score'=>$score===null?null:round($score,1),'rfqs_invited'=>$invited,'rfqs_responded'=>$responded,'quote_response_rate'=>$response,'average_quoted_lead_time'=>$lead->isEmpty()?null:round($lead->avg(),1),'awards_won'=>$awards,'price_competitiveness'=>$competitiveness,'quote_reliability'=>$validity,'historical_price_movement_percent'=>$priceMovement,'currency_rule'=>'Every price comparison uses the quote exchange-rate snapshot; raw cross-currency prices are never compared.'];
    }

    private function reliability(array $delivery,array $quality,array $commercial): array
    {
        $signals=collect([$delivery['on_time_delivery_percent'],$quality['acceptance_percent'],$commercial['quote_response_rate']])->filter(fn($v)=>$v!==null);
        return ['score'=>$signals->isEmpty()?null:round($signals->avg(),1),'signals_used'=>$signals->count(),'rule'=>'Average of available on-time delivery, quality acceptance and RFQ response signals. Missing signals are excluded, not scored as zero.'];
    }

    private function explain(?float $overall,array $delivery,array $quality,array $commercial,array $reliability): array
    {
        if($overall===null)return ['Insufficient reliable delivery, quality or commercial history to calculate a score.'];
        return array_values(array_filter([
            $quality['score']===null?null:"Quality {$quality['score']}/100 from {$quality['inspections']} finalized inspections and {$quality['defect_count']} defects.",
            $delivery['score']===null?null:"Delivery {$delivery['score']}/100 from {$delivery['purchase_orders']} eligible Purchase Orders; missing promised dates are excluded.",
            $commercial['score']===null?null:"Commercial {$commercial['score']}/100 from response, normalized price competitiveness and quote reliability.",
            $reliability['score']===null?null:"Reliability {$reliability['score']}/100 from {$reliability['signals_used']} available repeatable signals.",
        ]));
    }
}
