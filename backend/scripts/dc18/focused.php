<?php
require dirname(__DIR__).'/dc17/bootstrap.php';
$dc17Root='C:/Users/anisi/.codex/recovery/dc18-20261104';
$token=dc17Login('primary');$results=[];
foreach(['Sa metra stof milano 04 na kan met?','How much stof Milano 04 kemi available?','Cilat produkte jon tu u shit ma shum?'] as $question){
 $r=dc17Request('POST','intelligence-assistant/ask',['question'=>$question,'language'=>'sq'],$token);
 $results[]=['question'=>$question]+$r;
}
$r=dc17Request('POST','intelligence-assistant/ask',['question'=>'How much did we sell last month?'],$token);
$f=dc17Request('POST','intelligence-assistant/ask',['question'=>'What about the month before?','conversation_id'=>$r['body']['conversation_id']],$token);
$results[]=['question'=>'relative previous month','original'=>$r,'followup'=>$f,'pass'=>$f['status']===200&&str_contains($f['body']['text']??'',date('Y-m',strtotime('first day of -2 months')))];
$hr=dc17Login('hr');$r=dc17Request('POST','intelligence-assistant/ask',['question'=>'Stock Milano 04'],$hr);
$results[]=['question'=>'HR stock denied','pass'=>$r['status']===403||empty($r['body']['cards']),'result'=>$r];
$images=Illuminate\Support\Facades\DB::table('products')->where('company_id',2)->whereNotNull('image_data')->count();
$feedback=Illuminate\Support\Facades\DB::table('pilot_feedback')->where('company_id',2)->where('description','like','DC18 synthetic support check%')->get(['id','status','description']);
dc17Evidence('focused',['results'=>$results,'catalogue_images'=>$images,'internal_support_receipts'=>$feedback->map(fn($r)=>['id'=>$r->id,'status'=>$r->status,'redacted'=>!str_contains($r->description,'CANARY-DO-NOT-RETAIN')])->all()]);
echo 'Focused language/context checks saved; source catalogue images: '.$images."\n";
