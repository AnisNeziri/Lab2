<?php
require __DIR__.'/bootstrap.php';
$token=dc17Login('primary');$out=[];
foreach(['closed-period-test.png','injection-test.png'] as $file){
    Illuminate\Support\Facades\Auth::forgetGuards();
    $request=Illuminate\Http\Request::create('/api/intelligence-assistant/screenshot','POST',[],[],['image'=>new Illuminate\Http\UploadedFile($dc17Root.'/evidence/'.$file,$file,null,null,true)],['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token]);
    $k=app(Illuminate\Contracts\Http\Kernel::class);$t=microtime(true);$response=$k->handle($request);$k->terminate($request,$response);
    $body=json_decode($response->getContent(),true);$out[]=['file'=>$file,'http'=>$response->getStatusCode(),'seconds'=>microtime(true)-$t,'body'=>$body,'canary_redacted'=>!str_contains(json_encode($body),'DC181-CANARY-PRIVATE')];
    dc17Evidence('real-screenshots',['cases'=>$out,'real_inference'=>true]);
}
$r=dc17Request('GET','pilot/feedback',[],$token);$received=str_contains(json_encode($r['body']),'DC18 synthetic support check');
dc17Evidence('support-admin-receipt',['http'=>$r['status'],'authorized_admin_can_open'=>$r['status']===200&&$received,'delivery'=>'INTERNAL','external_delivery'=>false]);
echo json_encode(['screenshots'=>array_map(fn($r)=>['file'=>$r['file'],'http'=>$r['http'],'state'=>$r['body']['state']??null,'redacted'=>$r['canary_redacted']],$out),'support_admin_can_open'=>$received]);
