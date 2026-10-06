<?php
namespace App\Services;
use App\Models\{Product,AnalyticsSnapshot,AnalyticsDataset,AnalyticsDatasetRow,DailySale};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Carbon\CarbonImmutable as Date;

final class InventoryIntelligenceDatasetAdapter {
    public const VERSION='demand-daily-v1';
    public function history(Product $product,string $cutoff):array {
        $history=$this->rawHistory($product,$cutoff);
        $closed=AnalyticsSnapshot::where('entity_type','demand_observation')->where('entity_id',$product->id)->whereDate('snapshot_date','<=',$cutoff)->get()->keyBy(fn($s)=>$s->snapshot_date->toDateString());
        foreach($history['series'] as &$day){$s=$closed->get($day['date']);if(!$s)continue;
            $f=$s->facts;$sameUnit=($f['unit']??null)===$product->unit;
            $day=array_replace($day,['demand'=>$sameUnit?($f['demand']??null):null,'gross_recorded'=>$f['sales_quantity']??0,'returned'=>$f['returns_quantity']??0,
                'available'=>$f['available']??null,'censored'=>(bool)($f['stockout']??false),'quality'=>$sameUnit?$f['quality']:'unit_unverifiable','observation_id'=>$s->id,
                'potentially_censored'=>(bool)($f['potentially_censored']??true)]);
        }unset($day);return $history;
    }
    public function rawHistory(Product $product,string $cutoff,?string $from=null):array {
        $end=Date::parse($cutoff)->startOfDay();
        abort_if($end->gte(today()),422,'Use completed days only; today is not a training target.');
        $start=$end->subDays(729)->max(Date::parse($product->created_at)->startOfDay());
        if($from)$start=$start->max(Date::parse($from)->startOfDay());
        if($start->gt($end))return ['series'=>[],'snapshot_ids'=>[],'unit'=>$product->unit,'product_id'=>$product->id,'cutoff'=>$cutoff];
        $sales=app(AnalyticsSalesLedger::class)->rows($start->toDateString(),$end->toDateString(),true)->where('product_id',$product->id)->groupBy('date');
        $snapshots=AnalyticsSnapshot::where('entity_type','product')->where('entity_id',$product->id)->where('warehouse_id',0)
            ->whereDate('snapshot_date','>=',$start->toDateString())->whereDate('snapshot_date','<=',$end->toDateString())->get()->keyBy(fn($s)=>$s->snapshot_date->toDateString());
        // Notes alone do not prove a day was operated/closed. At least one
        // finalized sale, recognized on that day, is required for a zero.
        $books=DailySale::whereBetween('sale_date',[$start,$end])->where('status','finalized')->get()
            ->filter(fn($s)=>$s->finalized_at && $s->finalized_at->toDateString()<=$s->sale_date->toDateString())
            ->map(fn($s)=>$s->sale_date->toDateString())->flip();
        $unfinished=DailySale::whereBetween('sale_date',[$start,$end])->where('status','!=','finalized')->pluck('sale_date')->map(fn($d)=>substr($d,0,10))->flip();
        $series=[];
        for($day=$start;$day->lte($end);$day=$day->addDay()){
            $key=$day->toDateString();$s=$snapshots->get($key);$lines=$sales->get($key,collect());
            $gross=round($lines->where('return',false)->sum('quantity'),3);$returned=round(abs($lines->where('return',true)->sum('quantity')),3);
            $sameUnit=$s && $s->observed_at->lte($day->endOfDay()) && ($s->facts['unit']??null)===$product->unit;
            $available=$sameUnit?($s->facts['available']??null):null;
            $censored=$available!==null && (float)$available<=0;
            // A blank day is unknown unless a recorded, fully finalized book
            // and an in-stock observation support a true recorded zero.
            $observed=$sameUnit && $available!==null && !$censored && ($gross>0 || ($books->has($key)&&!$unfinished->has($key)));
            $series[]=['date'=>$key,'demand'=>$observed?$gross:null,'gross_recorded'=>$gross,'returned'=>$returned,'net_recorded'=>round($gross-$returned,3),
                'source_references'=>$lines->pluck('sale_key')->unique()->values()->all(),
                'censored'=>$censored,'available'=>$available,'quality'=>$censored?'stockout_censored':($observed?'observed':($s&&!$sameUnit?'unit_unverifiable':'unknown'))];
        }
        return ['series'=>$series,'snapshot_ids'=>$snapshots->pluck('id')->all(),'unit'=>$product->unit,'product_id'=>$product->id,'cutoff'=>$cutoff];
    }
    public function freeze(Product $product,array $history,AnalyticsSnapshot $anchor):AnalyticsDataset {
        return DB::transaction(function()use($product,$history,$anchor){
            $d=AnalyticsDataset::create(['company_id'=>$product->company_id,'version'=>(string)Str::uuid(),'name'=>'demand_forecasting_v1',
                'date_from'=>$history['series'][0]['date']??$history['cutoff'],'date_to'=>$history['cutoff'],
                'feature_definitions'=>['version'=>self::VERSION,'observed_features'=>AnalyticsFeatureRegistry::definitions(),
                    'target'=>'Gross fulfilled inventory-unit demand; returns reported separately. Missing and stockout-censored targets are null.',
                    'temporal_contract'=>'All lag features use earlier dates only. No current stock/supplier data is used as a historical predictor.',
                    'availability_limit'=>'Daily sampled availability is not proof of continuous in-stock time.'],
                'row_count'=>1,'labelled_count'=>1,'quality_status'=>'intelligence_frozen','created_by'=>Auth::id()]);
            AnalyticsDatasetRow::create(['company_id'=>$product->company_id,'analytics_dataset_id'=>$d->id,'analytics_snapshot_id'=>$anchor->id,'values'=>$history]);
            return $d;
        });
    }
}
