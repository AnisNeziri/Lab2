<?php
require __DIR__.'/bootstrap.php';
$checkpoint=Illuminate\Support\Facades\DB::table('_dc16_checkpoints')->where('name','history')->first();$state=json_decode($checkpoint->state,true);
$summary=[];foreach($state['frozen_forecasts']??[] as $date=>$frame)foreach($frame['training'] as $sku=>$trial)$summary[]=['origin'=>$date,'cutoff'=>$frame['cutoff'],'sku'=>$sku,'training'=>$trial['training'],'forecast'=>$trial['forecast']['status']??null,'horizons'=>array_column($trial['forecast']['results']??[],'horizon'),'history_days'=>count($trial['history']['series']??[])];
dc16Evidence('forecast-progress',['completed_day'=>$checkpoint->completed_day,'trials'=>$summary,'relationships'=>array_keys($state['frozen_relationships']??[]),'heldout_evaluation'=>'NOT YET EXECUTED']);
echo json_encode($summary)."\n";
