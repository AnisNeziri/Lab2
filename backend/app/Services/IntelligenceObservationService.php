<?php
namespace App\Services;
use App\Models\{Product,AnalyticsSnapshot,StockMovement};
use Carbon\CarbonImmutable as Date;

final class IntelligenceObservationService {
    public function collectProduct(Product $product,string $from,string $to):int {
        app(InventoryIntelligenceService::class)->authorize();
        abort_unless((int)$product->company_id===(int)auth()->user()->company_id,404);
        $start=Date::parse($from)->startOfDay();$end=Date::parse($to)->startOfDay();
        abort_if($end->gte(today())||$start->gt($end)||$start->diffInDays($end)>89,422,'Capture 1–90 completed days only. Historical stock is never reconstructed from current stock.');
        $effective=$start->max(Date::parse($product->created_at)->startOfDay());if($effective->gt($end))return 0;
        $already=AnalyticsSnapshot::where('entity_type','demand_observation')->where('entity_id',$product->id)->whereDate('snapshot_date','>=',$effective)->whereDate('snapshot_date','<=',$to)->count();
        if($already>=$effective->diffInDays($end)+1)return 0;
        $raw=app(InventoryIntelligenceDatasetAdapter::class)->rawHistory($product,$to,$from);
        $stocks=AnalyticsSnapshot::where('entity_type','product')->where('entity_id',$product->id)->where('warehouse_id',0)->whereDate('snapshot_date','>=',$from)->whereDate('snapshot_date','<=',$to)->get()->keyBy(fn($s)=>$s->snapshot_date->toDateString());
        $movements=StockMovement::where('product_id',$product->id)->where('affects_company_quantity',true)->where('unit_snapshot',$product->unit)
            ->whereDate('occurred_at','>=',$from)->whereDate('occurred_at','<=',$to)->orderBy('occurred_at')->orderBy('id')->get();
        $zeroDays=$movements->filter(fn($m)=>$m->quantity_before<=0||$m->quantity_after<=0)->map(fn($m)=>$m->occurred_at->toDateString())->flip();
        $byDay=$movements->groupBy(fn($m)=>$m->occurred_at->toDateString());
        $receipts=\App\Models\GoodsReceipt::with(['items'=>fn($q)=>$q->where('product_id',$product->id)])->where('status','posted')
            ->whereHas('items',fn($q)=>$q->where('product_id',$product->id))->whereDate('received_at','<=',$to)->whereDate('created_at','<=',$to)
            ->where(fn($q)=>$q->whereDate('received_at','>=',$from)->orWhereDate('created_at','>=',$from))->get()
            ->groupBy(fn($r)=>max($r->received_at->toDateString(),$r->created_at->toDateString()));
        $count=0;
        foreach($raw['series'] as $day){if($day['date']<$from||$day['date']>$to)continue;
            $snapshot=$stocks->get($day['date']);$f=$snapshot?->facts??[];
            $stockout=$day['censored']||$zeroDays->has($day['date']);
            $demand=$stockout?null:$day['demand'];
            $dailyMovements=$byDay->get($day['date'],collect());
            $intervals=[];$zeroSince=null;
            foreach($dailyMovements as $movement){
                if($movement->quantity_before<=0&&$movement->quantity_after>0&&$zeroSince===null)$intervals[]=['from'=>null,'to'=>$movement->occurred_at->toIso8601String()];
                if($movement->quantity_after<=0&&$zeroSince===null)$zeroSince=$movement->occurred_at->toIso8601String();
                if($movement->quantity_after>0&&$zeroSince!==null){$intervals[]=['from'=>$zeroSince,'to'=>$movement->occurred_at->toIso8601String()];$zeroSince=null;}
            }
            if($zeroSince!==null)$intervals[]=['from'=>$zeroSince,'to'=>null];
            $dayReceipts=$receipts->get($day['date'],collect());$receiptItems=$dayReceipts->flatMap(fn($r)=>$r->items);
            $facts=['unit'=>$product->unit,'demand'=>$demand,'sales_quantity'=>$day['gross_recorded'],'returns_quantity'=>$day['returned'],'net_sales_quantity'=>$day['net_recorded'],
                'accepted_receipt_quantity'=>$receiptItems->contains(fn($i)=>$i->inventory_unit!==$product->unit)?null:round($receiptItems->sum('accepted_base_quantity'),3),
                'stock_adjustment_quantity'=>round($dailyMovements->filter(fn($m)=>str_contains((string)$m->movement_code,'adjust'))->sum(fn($m)=>(float)$m->quantity_after-(float)$m->quantity_before),3),
                'known_stockout_intervals'=>$intervals,'interval_coverage'=>'recorded_movements_only_not_continuous_telemetry',
                'provenance'=>['sales'=>$day['source_references'],'stock_movement_ids'=>$dailyMovements->pluck('id')->all(),'stock_snapshot_id'=>$snapshot?->id,'receipt_ids'=>$dayReceipts->pluck('id')->all()],
                'reconciled_at'=>now()->toIso8601String(),
                'available'=>$day['available'],'reserved'=>$day['available']===null?null:($f['reserved']??null),'incoming'=>$day['available']===null?null:($f['incoming']??null),
                'stockout'=>$stockout,'complete'=>$demand!==null,'potentially_censored'=>true,
                'availability_evidence'=>$day['available']===null?'missing':'sampled_not_continuous',
                'quality'=>$stockout?'stockout_censored':($demand===null?$day['quality']:'observed_sampled_availability'),
                'stock_snapshot_id'=>$snapshot?->id,'backfilled'=>$day['date']<today()->subDay()->toDateString(),
                'target'=>'recognized_fulfilled_sales_not_unconstrained_demand'];
            $s=AnalyticsSnapshot::firstOrCreate(['company_id'=>$product->company_id,'entity_type'=>'demand_observation','entity_id'=>$product->id,'warehouse_id'=>0,'snapshot_date'=>$day['date']],
                ['feature_version'=>'closed-demand-v2','observed_at'=>now(),'facts'=>$facts]);
            if($s->wasRecentlyCreated)$count++;
        }return $count;
    }
    public function collectBatch(?float $deadline=null):int {
        app(InventoryIntelligenceService::class)->authorize();$to=today()->subDay()->toDateString();$count=0;
        $done=AnalyticsSnapshot::where('entity_type','demand_observation')->whereDate('snapshot_date',$to)->select('entity_id');
        foreach(Product::where('lifecycle_status','active')->whereDate('created_at','<=',$to)->whereNotIn('id',$done)->orderBy('id')->limit(config('inventory_intelligence.observation_batch_limit',100))->get() as $p){
            if($deadline!==null&&microtime(true)>=$deadline)break;
            $count+=$this->collectProduct($p,today()->subDays(14)->toDateString(),$to);
        }
        return $count;
    }
}
