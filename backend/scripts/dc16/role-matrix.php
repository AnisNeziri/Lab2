<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\{DB,Auth};
if((int)DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')!==364)throw new RuntimeException('Year incomplete');
$cases=['products'=>'inventory.view','accounting/overview'=>'accounting.reports.view','customer-workspace/customers/7'=>'customers.manage','sales-orders'=>'fulfillment.view','automation-health'=>'automations.health.view','payroll/summary'=>'payroll.view'];
$accounts=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);$results=[];
foreach(['primary','sales','purchasing','warehouse','accountant','hr','read_only'] as $role){
 $token=dc16Login($role);dc16Request('GET','me',[],$token);
 $permissions=app(App\Services\PermissionService::class)->forRole(Auth::user()->role);
 foreach($cases as $path=>$permission){
  $body=dc16Request('GET',$path,[],$token,[],[200,403,404,422]);
  $lines=file($dc16Root.'/storage/evidence/requests.jsonl',FILE_IGNORE_NEW_LINES);$request=json_decode(end($lines),true);
  $results[]=['role'=>$role,'path'=>$path,'permission'=>$permission,'configured_permission'=>in_array($permission,$permissions,true),'http'=>$request['status'],'result_keys'=>array_keys($body),'status'=>'OBSERVED — compare compound entitlements and role policy before grading'];
 }
}
dc16Evidence('role-api-matrix',['results'=>$results,'scope'=>'Actual authenticated HTTP requests; not browser role usability or full permission coverage.']);echo "Seven-role API observations saved.\n";
