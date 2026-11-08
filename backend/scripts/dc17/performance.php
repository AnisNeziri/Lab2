<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
$phase=$argv[1]??'before';if(!in_array($phase,['before','after']))throw new RuntimeException('Invalid phase');
$sql=[];DB::listen(function($q)use(&$sql){$sql[$q->sql]=($sql[$q->sql]??0)+1;});
$results=[];
foreach(['primary','hr','sales','warehouse'] as $role){
 $token=dc17Login($role);
 foreach(['search?q=Milano','automation-health'] as $path){
  $sql=[];$r=dc17Request('GET',$path,[],$token);arsort($sql);
  $results[]=['role'=>$role,'path'=>$path,'http'=>$r['status'],'ms'=>$r['ms'],'telemetry'=>$r['telemetry'],'body'=>$r['body'],'frequent_sql'=>array_slice($sql,0,5,true)];
 }
}
dc17Evidence('performance-'.$phase,['samples'=>$results]);
foreach($results as $r)echo $r['role'].' '.$r['path'].' HTTP'.$r['http'].' queries='.$r['telemetry']['query_count'].' ms='.$r['ms']."\n";
