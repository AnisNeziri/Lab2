<?php
require __DIR__.'/bootstrap.php';
$name=getenv('DC183_LABEL')?:'before';$token=dc17Login('primary');$rows=[];
$questions=['stock'=>'How much Milano 04 is available?','ar'=>'How much customers owe us?','ranking'=>'Which customers bought the most last month?'];
foreach($questions as $case=>$question){
 \Illuminate\Support\Facades\Http::post('http://127.0.0.1:11438/api/generate',['model'=>config('assistant.local_model'),'keep_alive'=>0]);
 for($n=0;$n<3;$n++){
  $r=dc17Request('POST','intelligence-assistant/ask',['question'=>$question,'language'=>'en'],$token);
  $rows[]=['case'=>$case,'temperature'=>$n===0?'cold':'warm','iteration'=>$n,'question'=>$question]+$r;
  dc17Evidence('latency-'.$name,['model'=>config('assistant.local_model'),'model_tools'=>true,'questions'=>$questions,'results'=>$rows]);
 }
}
echo 'Comparable latency probe saved: '.$name.PHP_EOL;
