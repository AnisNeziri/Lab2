<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
$cp=DB::table('_dc16_checkpoints')->where('name','history')->first();if($cp->completed_day!==364)throw new RuntimeException('Wrong historical fixture');
$state=json_decode($cp->state,true);$trials=[];
foreach($state['frozen_forecasts'] as $origin=>$frame)foreach($frame['training'] as $sku=>$trial){
 $trials[]=['origin'=>$origin,'sku'=>$sku,'history_hash_valid'=>hash_equals($trial['history_sha256'],App\Services\InventoryIntelligenceService::artifactHash($trial['history'])),'cutoff'=>$frame['cutoff'],'no_post_cutoff_history'=>!array_filter($trial['history']['series'],fn($day)=>$day['date']>$frame['cutoff']),'original_status'=>$trial['result']['status']??$trial['status']??'see original frozen trial'];
}
dc17Evidence('frozen-evaluation',['trials'=>$trials,'historical_finalized_sales_without_provenance'=>DB::table('daily_sales')->where('company_id',2)->where('status','finalized')->whereNull('finalized_at')->count(),'historical_day_closures_added'=>DB::table('daily_sales_days')->where('company_id',2)->whereNotNull('closed_at')->count(),'historical_numeric_backtests'=>'BLOCKED — original insufficient evidence retained; no timestamp/history backfill','prior_evaluation'=>'C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence/temporal-backtests.json']);
echo count($trials)." frozen trials checked; historical evidence remains insufficient.\n";
