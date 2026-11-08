<?php
require __DIR__.'/bootstrap.php';
$file=getenv('DC183_VALIDATION')?'validation':'held-out';$cases=json_decode(file_get_contents(__DIR__.'/'.$file.'.json'),true);$rows=[];$tokens=[];$conversations=[];
foreach($cases as $case){
 $tokens[$case['role']]??=dc17Login($case['role']);
 $r=dc17Request('POST','intelligence-assistant/ask',['question'=>$case['question'],'language'=>$case['language'],'conversation_id'=>$conversations[$case['group']]??null],$tokens[$case['role']]);
 $conversations[$case['group']]=$r['body']['conversation_id']??null;
 $rows[]=['case'=>$case]+$r;dc17Evidence($file,['frozen_questions'=>hash_file('sha256',__DIR__.'/'.$file.'.json'),'results'=>$rows]);
}
echo 'Held-out set executed; source and usefulness review required.'.PHP_EOL;
