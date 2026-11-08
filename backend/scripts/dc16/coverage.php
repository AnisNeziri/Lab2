<?php
require __DIR__.'/bootstrap.php';
$routes=json_decode(file_get_contents($dc16Root.'/storage/evidence/route-coverage-inventory.json'),true);
$routes=$routes['routes']??$routes;
$requests=[];$log=$dc16Root.'/storage/evidence/requests.jsonl';
if(is_file($log))foreach(file($log,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line)$requests[]=json_decode($line,true);
$pathways=[];
$proofs=[];
if(is_file($dc16Root.'/storage/evidence/opening-final.json')){
    $opening=json_decode(file_get_contents($dc16Root.'/storage/evidence/opening-final.json'),true);
    if(($opening['status']??'')==='locked')foreach([
        'POST api/onboarding','PUT api/onboarding/{id}','POST api/onboarding/{id}/sources','POST api/onboarding/{id}/batches/{batch}/exclude','PATCH api/onboarding/{id}/rows','POST api/onboarding/{id}/validate','POST api/onboarding/{id}/review','POST api/onboarding/{id}/approve','POST api/onboarding/{id}/post','POST api/onboarding/{id}/verify','POST api/onboarding/{id}/backups','POST api/onboarding/{id}/restore-verify','POST api/onboarding/{id}/lock'
    ] as $key)$proofs[$key]=['status'=>'PASS','evidence'=>['opening-final.json','opening-validation.json','opening-verified.json','opening-restore.json','requests.jsonl'],'behavior'=>'Source staging, explicit resolution, separate review/approval, bounded posting, source/control reconciliation, encrypted portable restore and locked opening exercised. Attachment recovery is not covered by this opening fixture.'];
}
if(is_file($dc16Root.'/storage/evidence/roles.json'))foreach(['POST api/register','POST api/verify-email','POST api/login','POST api/users','POST api/change-password'] as $key)$proofs[$key]=['status'=>'PASS','evidence'=>['accounts.json','roles.json','registration-requests.json','requests.jsonl'],'behavior'=>'New independent tenants and seven business roles created and authenticated through registration, captured email verification and mandatory temporary-password change. Passkeys/recovery are separate capabilities.'];
foreach($routes as $route){
    $uri=$route['uri']??$route['path']??$route['feature']??'';
    $pattern='~^'.preg_replace('/\\\{[^}]+\\\}/','[^/]+',preg_quote($uri,'~')).'$~';
    $observed=array_values(array_filter($requests,fn($r)=>in_array($r['method'],$route['methods']??[],true)&&preg_match($pattern,'api/'.$r['path'])));
    $proof=null;foreach($route['methods'] as $method)if(isset($proofs[$method.' '.$uri]))$proof=$proofs[$method.' '.$uri];
    $pathways[]=array_replace($route,['request_observations'=>$observed,'pathway_observed'=>count($observed)>0,'status'=>$proof['status']??'NOT TESTED','expected_behavior'=>$proof['behavior']??$route['expected_behavior'],'observed_result'=>$proof?'Workflow evidence satisfied for this fixture, subject to the stated scope.':null,'evidence'=>$proof['evidence']??null]);
}
$frontend=file_get_contents(dirname(__DIR__,3).'/frontend/src/config/navigation.js');
preg_match_all('/item\(\s*\x27([^\x27]+)\x27/',$frontend,$matches);
$pages=[];foreach(array_unique($matches[1]) as $page)$pages[]=['feature'=>$page,'pathway'=>'UI','role_required'=>'Canonical pageAccess + server permission/entitlement checks','preconditions'=>'Enabled capability, authenticated permitted role, populated isolated company','expected_behavior'=>'Complete a goal-based task; inspect rendered state and source records','status'=>'NOT TESTED','observed'=>null,'evidence'=>null];
$desktop=[];foreach(['startup','offline/local operation','reconnect and sync','conflict handling','native print','signing','hardware scanner','worker restart','scheduler initialization','database reconnect','installation backup and encrypted restoration','application-key compatibility'] as $feature)$desktop[]=['feature'=>$feature,'pathway'=>'desktop/runtime','role_required'=>'Authorized operator','preconditions'=>'Isolated installation and suitable native environment','expected_behavior'=>'Exercise the capability without changing live installation','status'=>'NOT TESTED','evidence'=>null];
$passed=count(array_filter($pathways,fn($r)=>$r['status']==='PASS'));
$total=count($pathways)+count($pages)+count($desktop);
dc16Evidence('coverage',['schema'=>1,'head'=>'89e0c29c295166a4610b61b6af45a0cfc6c12634','denominators'=>['api_pathways'=>count($pathways),'navigation_entries'=>count($pages),'desktop_runtime'=>count($desktop),'total'=>$total],'verified_pass'=>$passed,'verified_percentage'=>round(100*$passed/$total,2),'api'=>$pathways,'ui'=>$pages,'desktop'=>$desktop,'note'=>'Pathway inventory, not a claim of complete business-feature coverage. A successful HTTP response alone is not a semantic PASS. Existing contract tests remain separate from end-to-end pathway results.']);
echo count($pathways).' API pathways, '.count($pages).' navigation entries, '.count($desktop)." desktop/runtime capabilities inventoried.\n";
