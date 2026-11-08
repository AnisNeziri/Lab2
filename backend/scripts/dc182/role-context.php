<?php
require __DIR__.'/bootstrap.php';
$scenarios=[
 'customer_references'=>['primary', ['Which customers bought the most last month?','Do those customers owe us money?']],
 'product_references'=>['primary',['Show the best selling products last month','The second product','Which warehouse has more?','What should we do about this shortage?']],
 'calendar'=>['primary',['How much did we sell in September 2026?','And October?','What about last month?']],
 'warehouse'=>['warehouse',['Qka duhet me bo sot ne depo?']],
 'sales'=>['sales',['Which quotations need follow-up?']],
 'purchasing'=>['purchasing',['What needs my attention?']],
 'finance'=>['accountant',['What is blocking month close?']],
 'hr'=>['hr',['What is blocking payroll?']],
];
$only=array_filter(explode(',',getenv('DC182_SCENARIOS')?:''));
$path=$dc17Root.'/evidence/role-context.json';$out=$only&&is_file($path)?json_decode(file_get_contents($path),true):[];
foreach($scenarios as $key=>[$role,$questions]){
 if($only&&!in_array($key,$only,true))continue;
 $token=dc17Login($role);$conversation=null;$rows=[];
 foreach($questions as $question){
  $r=dc17Request('POST','intelligence-assistant/ask',['question'=>$question,'language'=>$key==='warehouse'?'sq':'en','conversation_id'=>$conversation],$token);
  $conversation=$r['body']['conversation_id']??$conversation;$rows[]=['question'=>$question]+$r;
 }
 $out[$key]=$rows;dc17Evidence('role-context',$out);
}
echo 'Role/context scenarios saved; review correctness, not just HTTP status.'.PHP_EOL;
