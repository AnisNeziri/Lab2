<?php
require __DIR__.'/bootstrap.php';
$token=dc17Login('primary');$all=[];
foreach(getenv('DC181_MODEL_ONLY')?[true]:[false,true] as $enabled){
    config(['assistant.model_tools'=>$enabled,'assistant.provider'=>$enabled?'ollama':'deterministic']);$conversation=null;$rows=[];
    foreach(['How much did we sell in September 2026?','And October?','Which customers bought the most?','Do they owe us money?'] as $question){
        $r=dc17Request('POST','intelligence-assistant/ask',['question'=>$question,'language'=>'en','conversation_id'=>$conversation],$token);
        $conversation=$r['body']['conversation_id']??$conversation;$rows[]=['question'=>$question]+$r;
    }
    $all[$enabled?'model':'deterministic']=$rows;dc17Evidence('conversation-comparison',$all);
}
echo json_encode(array_map(fn($rows)=>array_map(fn($r)=>['http'=>$r['status'],'provider'=>$r['body']['provider']??null,'calls'=>$r['body']['model_calls']??null],$rows),$all));
