<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\{Auth,DB};
$checkpoint=DB::table('_dc16_checkpoints')->where('name','history')->first();
if(!$checkpoint||$checkpoint->completed_day!==364)throw new RuntimeException('Complete 365 days before final held-out evaluation');
$accounts=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);$company=$accounts['primary']['company_id'];Auth::setUser(App\Models\User::withoutGlobalScopes()->findOrFail($accounts['primary']['user_id']));
$state=json_decode($checkpoint->state,true,512,JSON_THROW_ON_ERROR);$evaluations=[];
foreach($state['frozen_forecasts']??[] as $origin=>$frame){
    foreach($frame['training'] as $sku=>$trial){
        $history=$trial['history'];$validHash=hash_equals($trial['history_sha256'],App\Services\InventoryIntelligenceService::artifactHash($history));
        $dates=array_column($history['series'],'date');
        $cutoffValid=!$dates||max($dates)<=$frame['cutoff'];
        $recent=array_values(array_filter($history['series'],fn($r)=>$r['date']>=Carbon\CarbonImmutable::parse($frame['cutoff'])->subDays(27)->toDateString()&&$r['demand']!==null));
        $baseline=$recent?array_sum(array_column($recent,'demand'))/count($recent):null;
        if(!$validHash||!$cutoffValid)throw new RuntimeException('Frozen input integrity or temporal boundary failed');
        foreach($trial['forecast']['results']??[] as $forecast){
            $points=[];$absolute=0;$baselineAbsolute=0;$actualTotal=0;$error=0;
            foreach($forecast['daily'] as $prediction){
                $date=$prediction['date'];if($date<=$frame['cutoff'])throw new RuntimeException('Forecast is not held out');
                if($date>'2026-10-31')continue;
                // Independent outcome calculation from recognized dispatch/sale lines.
                // A blank day is not silently treated as an observed zero.
                $book=DB::table('daily_sales')->where('company_id',$company)->whereDate('sale_date',$date)->where('status','finalized')->exists();
                $snapshot=DB::table('analytics_snapshots')->where('company_id',$company)->where('entity_type','product')->where('entity_id',$trial['product_id'])->where('warehouse_id',0)->whereDate('snapshot_date',$date)->first();
                $facts=$snapshot?json_decode($snapshot->facts,true):[];
                if(!$book||!isset($facts['available'])||(float)$facts['available']<=0)continue;
                $actual=(float)DB::table('daily_sale_items as i')->join('daily_sales as s','s.id','=','i.daily_sale_id')->where('s.company_id',$company)->where('i.product_id',$trial['product_id'])->where('s.status','finalized')->whereDate('s.sale_date',$date)->whereDate('s.inventory_applied_at','<=',$date)->sum('i.base_quantity');
                $predicted=(float)$prediction['quantity'];$e=$predicted-$actual;$absolute+=abs($e);$error+=$e;$actualTotal+=$actual;
                if($baseline!==null)$baselineAbsolute+=abs($baseline-$actual);
                $points[]=['date'=>$date,'actual_gross_fulfilled'=>$actual,'forecast'=>$predicted,'trailing_28_observed_day_mean'=>$baseline];
            }
            $n=count($points);
            $evaluations[]=['origin'=>$origin,'cutoff'=>$frame['cutoff'],'sku'=>$sku,'unit'=>$trial['unit'],'horizon'=>$forecast['horizon'],'mode'=>'shadow candidate; never auto-promoted or executed','status'=>$n?'EVALUATED':'BLOCKED — no qualified held-out observations','input_hash_valid'=>$validHash,'pre_cutoff_input_only'=>$cutoffValid,'qualified_days'=>$n,'mae'=>$n?round($absolute/$n,4):null,'baseline_mae'=>$n&&$baseline!==null?round($baselineAbsolute/$n,4):null,'wape_percent'=>$actualTotal>0?round(100*$absolute/$actualTotal,2):null,'bias'=>$n?round($error/$n,4):null,'outcomes'=>$points,'qualification'=>'Recorded fulfilled demand only; closed-book and sampled in-stock days. Not unconstrained demand, continuous availability proof or production calibration certification.'];
        }
        if(empty($trial['forecast']['results']))$evaluations[]=['origin'=>$origin,'sku'=>$sku,'status'=>'BLOCKED — existing evidence gate produced no candidate forecast','training'=>$trial['training']];
    }
}
$relationships=[];
foreach($state['frozen_relationships']??[] as $origin=>$frame){
    $snapshot=$frame['customer_snapshot_id']?App\Models\CustomerSalesSnapshot::find($frame['customer_snapshot_id']):null;
    $hashValid=$snapshot&&hash_equals($frame['customer_evidence_sha256'],App\Services\InventoryIntelligenceService::artifactHash($snapshot->evidence));
    $customers=[];
    if($snapshot&&$hashValid)foreach($snapshot->evidence['profiles']??[] as $profile){
        $id=$profile['id']??$profile['customer_id']??null;if(!$id)continue;
        $later=DB::table('daily_sales')->where('company_id',$company)->where('customer_id',$id)->where('status','finalized')->where('sale_date','>=',$origin)->where('sale_date','<',Carbon\CarbonImmutable::parse($origin)->addDays(30)->toDateString())->count();
        $customers[]=['customer_id'=>$id,'trigger_evidence'=>$profile,'subsequent_30_day_finalized_sales'=>$later];
    }
    $suppliers=[];
    foreach($frame['supplier_risks'] as $risk){
        $promise=$risk['evidence']['original_promise']??null;
        if(!$promise||$promise<$origin||in_array($risk['risk'],['closed','insufficient_data'],true))continue;
        $po=DB::table('purchase_orders')->where('company_id',$company)->where('id',$risk['purchase_order_id'])->first();
        $completion=DB::table('goods_receipts')->where('company_id',$company)->where('purchase_order_id',$risk['purchase_order_id'])->where('status','posted')->max('received_at');
        $suppliers[]=['purchase_order_id'=>$risk['purchase_order_id'],'trigger_risk'=>$risk['risk'],'promise'=>$promise,'observed_last_receipt'=>$completion,'receipt_complete'=>in_array($po->status,['received','completed'],true),'late_if_complete'=>$completion&&in_array($po->status,['received','completed'],true)?substr($completion,0,10)>$promise:null,'qualification'=>'Receipt completion observation; not supplier-fault attribution or a calibrated risk probability.'];
    }
    $relationships[]=['origin'=>$origin,'customer_snapshot_integrity'=>$hashValid,'customer_followthrough'=>$customers,'supplier_followthrough'=>$suppliers,'qualification'=>'Descriptive future follow-through of frozen evidence. No causal conclusion or invented probability score.'];
}
dc16Evidence('temporal-backtests',['inventory'=>$evaluations,'relationships'=>$relationships,'future_data_used_in_training'=>false,'recommendation_policy_backtest'=>'BLOCKED unless a genuine pre-outcome recommendation exists; final current-stock recommendations are never reconstructed as past recommendations','limits'=>['Two temporal origins and six inventory products; not all possible models or business distributions','Shadow candidates were not promoted to production','Insufficient-evidence models remain blocked','No live tracking provider validation']]);
echo count($evaluations)." bounded inventory trials and ".count($relationships)." relationship origins evaluated; inspect qualifications.\n";
