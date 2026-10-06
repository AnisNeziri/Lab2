<?php
namespace App\Services;
use App\Models\{DailySale,Invoice,Product,InventoryReturn};
use App\Support\{Money,CompanyCurrency};
use Illuminate\Support\Collection;

/** One economic fact per sale line. Orders are demand, never additional revenue. */
class AnalyticsSalesLedger {
    public function rows(string $from,string $to,bool $demandOnly=false): Collection {
        $rows=collect();$currency=CompanyCurrency::current();
        $sales=DailySale::with(['items','outboundDispatch.order.intake'])->where(fn($q)=>$q->whereDate('sale_date','>=',$from)
                ->when($demandOnly,fn($q)=>$q->orWhereDate('inventory_applied_at','>=',$from)->orWhereDate('finalized_at','>=',$from)))->whereDate('sale_date','<=',$to)
            ->where(fn($q)=>$q->whereNotNull('inventory_applied_at')->orWhere('status','finalized'))->get();
        $invoices=Invoice::with('items')->whereNotNull('issued_at')->whereNull('voided_at')->whereNotIn('status',['draft','void'])
            ->where(fn($q)=>$q->where(fn($q)=>$q->whereDate('invoice_date','>=',$from)->whereDate('invoice_date','<=',$to))->orWhereIn('daily_sale_id',$sales->pluck('id'))
                ->when($demandOnly,fn($q)=>$q->orWhere(fn($q)=>$q->whereDate('issued_at','>=',$from)->whereDate('issued_at','<=',$to))))->orderBy('id')->get();
        $linked=$invoices->where('document_type','!=','credit_note')->whereNotNull('daily_sale_id')->groupBy('daily_sale_id');
        foreach($sales as $sale){
            $invoice=$linked->get($sale->id)?->first();
            if(!$demandOnly && $invoice && $invoice->currency!==$currency)continue; // No invented exchange rate.
            if($demandOnly && ($sale->inventory_applied_at ?? $sale->finalized_at)?->toDateString()>$to)continue;
            if($demandOnly)$invoice=null; // A later invoice must not rewrite historical physical demand.
            $date=$sale->sale_date->toDateString();
            if($demandOnly)$date=max($date,($sale->inventory_applied_at??$sale->finalized_at)?->toDateString()??$date);
            $items=$invoice?$invoice->items:$sale->items;
            foreach($items as $item)$rows->push($this->row($item,'sale:'.$sale->id,$date,$sale->customer_id,$sale->customer_name,$invoice!==null,1,
                '/daily-sales?date='.$sale->sale_date->toDateString(),$sale->outboundDispatch?->order?->intake?->order_channel_id?'channel:'.$sale->outboundDispatch->order->intake->order_channel_id:'direct'));
        }
        foreach($invoices as $invoice){
            $credit=$invoice->document_type==='credit_note';
            if(!$credit && $invoice->daily_sale_id)continue;
            $date=$demandOnly?max($invoice->invoice_date->toDateString(),$invoice->issued_at->toDateString()):$invoice->invoice_date->toDateString();
            if($date<$from || $date>$to || (!$demandOnly && $invoice->currency!==$currency))continue;
            if($demandOnly && $invoice->issued_at->toDateString()>$to)continue;
            foreach($invoice->items as $item)$rows->push($this->row($item,'invoice:'.$invoice->id,$date,$invoice->customer_id,$invoice->customer_name,true,$credit?-1:1,'/invoices?invoice='.$invoice->id,'invoice'));
        }
        // Completed unbilled refunds are real sales reversals. Invoiced returns are represented by
        // their credit note, never added here a second time. Stock-only returns are not revenue.
        $returns=InventoryReturn::with('items.dailySaleItem','customer')->where('type','customer')->where('status','completed')->when(!$demandOnly,fn($q)=>$q->where('currency',$currency))
            ->whereIn('financial_resolution',['cash_refund','debt_credit'])->whereDate('completed_at','>=',$from)->whereDate('completed_at','<=',$to)->get();
        $invoiced=Invoice::whereNotNull('issued_at')->whereNull('voided_at')->where('document_type','invoice')->whereIn('daily_sale_id',$returns->pluck('daily_sale_id'))->pluck('daily_sale_id');
        foreach($returns as $return){
            if($invoiced->contains($return->daily_sale_id))continue;
            $remaining=Money::minor($return->financial_amount);$weight=$return->items->sum(fn($i)=>Money::minor($i->line_value));$index=0;
            foreach($return->items as $item){$index++;$part=$index===$return->items->count()?$remaining:($weight>0?(int)round(Money::minor($return->financial_amount)*Money::minor($item->line_value)/$weight):0);$remaining-=$part;
                $rows->push(['sale_key'=>'return:'.$return->id,'date'=>$return->completed_at->toDateString(),'product_id'=>$item->product_id,'customer_id'=>$return->customer_id,'customer'=>$return->customer?->name,'description'=>$item->dailySaleItem?->product_name,
                    'line_unit'=>$item->unit,'quantity'=>Money::normalizeDecimal(-(float)$item->processed_quantity,3),'revenue'=>Money::decimal(-$part),
                    'cost'=>$item->unit_cost===null?null:Money::multiply(-1,Money::multiply($item->unit_cost,$item->processed_quantity)),
                    'discount'=>'0.00','return'=>true,'channel'=>'return','url'=>'/operations-center?tab=returns']);
            }
        }
        $products=Product::with('category:id,name')->whereIn('id',$rows->pluck('product_id')->filter()->unique())->get()->keyBy('id');
        return $rows->map(function($r)use($products){$p=$products->get($r['product_id']);return $r+['product'=>$p?->name ?? $r['description'],'unit'=>$p?->unit ?? $r['line_unit'],'category'=>$p?->category?->name,'category_id'=>$p?->category_id,'supplier_id'=>$p?->supplier_id];});
    }
    private function row($i,string $sale,string $date,?int $customer,?string $name,bool $invoice,int $sign,string $url,string $channel):array {
        $amount=$invoice?$i->taxable_amount:$i->line_total;$cost=$i->cost_total;
        return ['sale_key'=>$sale,'date'=>$date,'product_id'=>$i->product_id,'customer_id'=>$customer,'customer'=>$name,'description'=>$i->description ?? $i->product_name,
            'line_unit'=>$i->unit,'quantity'=>Money::normalizeDecimal($sign*(float)($i->base_quantity ?? $i->quantity),3),
            'revenue'=>Money::decimal($sign*Money::minor($amount ?? 0)),'cost'=>$cost===null?null:Money::decimal($sign*Money::minor($cost)),
            'discount'=>Money::decimal($sign*Money::minor($i->discount_amount ?? 0)),'return'=>$sign<0,'channel'=>$channel,'url'=>$url];
    }
    public function summary(Collection $rows):array {
        $revenue=self::sum($rows,'revenue');$known=$rows->whereNotNull('cost');$cost=self::sum($known,'cost');
        $profit=$known->count()===$rows->count()?Money::subtract($revenue,$cost):null;
        $count=$rows->where('return',false)->pluck('sale_key')->unique()->count();
        return ['revenue'=>$revenue,'sales_count'=>$count,'average_sale'=>$count?Money::divide($revenue,$count):null,'gross_profit'=>$profit,
            'gross_margin'=>$profit!==null && Money::minor($revenue)>0?round(100*Money::minor($profit)/Money::minor($revenue),2):null,
            'discounts'=>self::sum($rows,'discount'),'return_value'=>Money::normalize(abs((float)self::sum($rows->where('return',true),'revenue'))),
            'cost_coverage_percent'=>$rows->count()?round(100*$known->count()/$rows->count(),2):null];
    }
    public static function sum(Collection $rows,string $field):string {return Money::decimal($rows->sum(fn($r)=>Money::minor($r[$field]??0)));}
}
