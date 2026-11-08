<?php
require __DIR__.'/bootstrap.php';
$token=dc17Login('primary');$results=[];$conversation=null;
foreach(['Sa metra stof milano 04 na kan met?','How much did we sell in September 2026?','And October?','Which customers bought the most?'] as $question){
    $r=dc17Request('POST','intelligence-assistant/ask',['question'=>$question,'language'=>'sq','conversation_id'=>$conversation],$token);
    $conversation=$r['body']['conversation_id']??$conversation;$results[]=['question'=>$question]+$r;
    dc17Evidence('real-focused',['results'=>$results]);
}
echo json_encode(array_map(fn($r)=>['question'=>$r['question'],'http'=>$r['status'],'provider'=>$r['body']['provider']??null,'tools'=>$r['body']['model_calls']??null,'ms'=>$r['ms']],$results));
