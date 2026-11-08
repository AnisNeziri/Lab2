<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\DB;
if((int)DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')!==364)throw new RuntimeException('Year incomplete');
$file=$dc16Root.'/storage/evidence/feature-probes.json';$results=is_file($file)?json_decode(file_get_contents($file),true):[];
$token=dc16Login('primary');
$paths=['customer-workspace/customers/7','customer-workspace/customers/7/timeline','customer-workspace/customers/7/relationships','inventory/products/4','inventory/health','quality/dashboard','payroll/summary','analytics/intelligence/products/4','analytics/planning/products/4','analytics/decisions','shipment-intelligence','suppliers/1/intelligence','customer-sales-intelligence?summary_only=1','financial-intelligence?summary_only=1','decision-learning','supply-optimizer/options','strategic-simulation/options','integrations/health','pilot','system-integrity'];
foreach($paths as $path){
 if(isset($results[$path]))continue;
 $body=dc16Request('GET',$path,[],$token,[],[200,403,404,422,500]);
 $logs=file($dc16Root.'/storage/evidence/requests.jsonl',FILE_IGNORE_NEW_LINES);$observed=json_decode(end($logs),true);
 $results[$path]=['observation'=>$observed,'response'=>$body,'status'=>$observed['status']===200?'PARTIAL':'BLOCKED','qualification'=>'Authenticated response captured. Domain semantics and evidence qualification must be reviewed; HTTP success alone is not PASS.'];
 dc16Evidence('feature-probes',$results);
}
echo count($results)." bounded module responses retained.\n";
