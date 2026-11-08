<?php
require __DIR__.'/bootstrap.php';
$token=dc17Login('primary');Illuminate\Support\Facades\Auth::forgetGuards();
$path='C:/Users/anisi/.codex/recovery/dc18-20261104/evidence/synthetic-fabric.png';
$request=Illuminate\Http\Request::create('/api/intelligence-assistant/visual-search','POST',[],[],['image'=>new Illuminate\Http\UploadedFile($path,'sample.png',null,null,true)],['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token]);
$k=app(Illuminate\Contracts\Http\Kernel::class);$t=microtime(true);$response=$k->handle($request);$k->terminate($request,$response);
$body=json_decode($response->getContent(),true);dc17Evidence('image-api',['http'=>$response->getStatusCode(),'seconds'=>microtime(true)-$t,'body'=>$body,'qualification'=>'Real encoder/API execution; preserved company has zero catalogue photos. Two-company candidate ranking tested separately on ephemeral SQLite.']);
echo json_encode(['http'=>$response->getStatusCode(),'seconds'=>round(microtime(true)-$t,3),'method'=>$body['method']??null,'indexed'=>$body['indexed']??null]);
