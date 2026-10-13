<?php

use App\Contracts\DocumentStorageProvider;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Services\JwtService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';
$type=DocumentType::firstOrCreate(['name'=>'PR2 performance attachment']);
$storage=app(DocumentStorageProvider::class);
for($i=DB::table('documents')->where('company_id',$owner->company_id)->where('reference','like','PERF-ATTACH-%')->count();$i<1000;$i++){
    $bytes="AIMS PERFORMANCE / TEST DATA attachment ".$i."\n";
    $hash=hash('sha256',$bytes);$key=$owner->company_id.'/'.substr($hash,0,2).'/'.Str::uuid();
    $stream=fopen('php://temp','w+');fwrite($stream,$bytes);rewind($stream);
    try{$storage->put($key,$stream);}finally{fclose($stream);}
    DB::transaction(function()use($i,$bytes,$hash,$key,$type){
        $document=Document::create(['uuid'=>(string)Str::uuid(),'reference'=>'PERF-ATTACH-'.$i,'title'=>'Performance attachment '.$i,'document_type_id'=>$type->id,'status'=>'active','confidentiality'=>'internal','current_version'=>1,'created_by'=>Auth::id()]);
        DocumentVersion::create(['document_id'=>$document->id,'version'=>1,'filename'=>'performance-'.$i.'.txt','mime_type'=>'text/plain','size'=>strlen($bytes),'checksum'=>$hash,'provider'=>'local','storage_key'=>$key,'uploaded_by'=>Auth::id()]);
    });
}
$token=app(JwtService::class)->createAccessToken($owner);$queries=0;
DB::listen(function()use(&$queries){$queries++;});
$kernel=app(Kernel::class);
$call=function($route)use($kernel,$token){
    $request=Request::create('/api'.$route,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token]);
    $response=$kernel->handle($request);$kernel->terminate($request,$response);return $response;
};
$report=['status'=>'PASS','engine'=>$engine,'qualification'=>'1,000 real tiny text files and valid version metadata in the marked disposable fixture; bulk setup skips upload/audit lifecycle. Preview bytes load on demand.','samples'=>[]];
for($n=0;$n<4;$n++){
    $queries=0;$start=hrtime(true);$response=$call('/documents?q=Performance%20attachment');$body=$response->getContent();$json=json_decode($body,true);
    $report['samples'][]=['ms'=>round((hrtime(true)-$start)/1e6,2),'queries'=>$queries,'bytes'=>strlen($body),'status'=>$response->getStatusCode(),'rows'=>count($json['data']??[])];
}
$ids=[];
for($page=1;$page<=40;$page++){
    $response=$call('/documents?q=Performance%20attachment&page='.$page);$json=json_decode($response->getContent(),true);
    if($response->getStatusCode()!==200||count($json['data']??[])!==25)$report['status']='FAIL';
    foreach($json['data']??[] as $row)$ids[]=$row['id'];
}
$report['unique_rows']=count(array_unique($ids));if($report['unique_rows']!==1000)$report['status']='FAIL';
$id=$ids[0]??0;$response=$call('/documents/'.$id.'/versions/1/file?preview=1');
ob_start();$response->sendContent();$bytes=ob_get_clean();
$version=DocumentVersion::where('document_id',$id)->firstOrFail();
$report['on_demand_preview']=['status'=>$response->getStatusCode(),'bytes'=>strlen($bytes),'checksum_matches'=>hash('sha256',$bytes)===$version->checksum];
if($response->getStatusCode()!==200||!$report['on_demand_preview']['checksum_matches'])$report['status']='FAIL';
$report['peak_memory_bytes']=memory_get_peak_usage(true);$report['recorded_at']=gmdate('c');
file_put_contents($root.'/output/pr2-documents-'.$engine.'.json',json_encode($report,JSON_PRETTY_PRINT));
echo json_encode($report);exit($report['status']==='PASS'?0:1);

