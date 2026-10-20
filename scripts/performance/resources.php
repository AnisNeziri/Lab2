<?php

use Illuminate\Support\Facades\DB;
$started=hrtime(true);
require __DIR__.'/bootstrap.php';
$report=['engine'=>$engine,'bootstrap_ms'=>round((hrtime(true)-$started)/1e6,2),'status'=>'PASS','qualification'=>'Marked local fixture only. Physical size is current allocation, not a prediction of annual growth.'];
if($engine==='mysql'){
 $report['variables']=DB::select("SHOW VARIABLES WHERE Variable_name IN ('version','max_connections','max_allowed_packet','innodb_buffer_pool_size','innodb_lock_wait_timeout')");
 $report['status_variables']=DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Max_used_connections','Innodb_row_lock_waits','Innodb_row_lock_time')");
 $report['tables']=DB::select('SELECT TABLE_NAME,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY DATA_LENGTH+INDEX_LENGTH DESC',[$database]);
 $raw=DB::select('SHOW ENGINE INNODB STATUS')[0]->Status;
 $start=strpos($raw,'LATEST DETECTED DEADLOCK');$end=$start===false?false:strpos($raw,"\nTRANSACTIONS",$start);
 $report['last_deadlock']=$start===false?'No LATEST DETECTED DEADLOCK section':substr($raw,$start,min(2000,($end===false?strlen($raw):$end)-$start));
}else{
 foreach(['page_count','page_size','freelist_count','journal_mode','busy_timeout'] as $pragma)$report['sqlite'][$pragma]=DB::select('PRAGMA '.$pragma);
 $report['database_bytes']=filesize($database);
 $report['integrity_check']=DB::select('PRAGMA integrity_check');
 $report['foreign_keys']=DB::select('PRAGMA foreign_key_check');
}
$token=app(App\Services\JwtService::class)->createAccessToken($owner);
$request=Illuminate\Http\Request::create('/api/stock-movements/export','GET',[],[],[],['HTTP_ACCEPT'=>'text/csv','HTTP_AUTHORIZATION'=>'Bearer '.$token]);
$kernel=app(Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($request);$bytes=0;$lines=0;$start=hrtime(true);
ob_start(function($chunk)use(&$bytes,&$lines){$bytes+=strlen($chunk);$lines+=substr_count($chunk,"\n");return '';},65536);
$response->sendContent();ob_end_clean();
$report['stock_csv']=['status'=>$response->getStatusCode(),'bytes'=>$bytes,'lines'=>$lines,'stream_ms'=>round((hrtime(true)-$start)/1e6,2),'peak_memory_bytes'=>memory_get_peak_usage(true)];
$kernel->terminate($request,$response);
file_put_contents($root.'/output/pr2-database-resources-'.$engine.'.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode(['status'=>$report['status'],'engine'=>$engine,'bootstrap_ms'=>$report['bootstrap_ms'],'export'=>$report['stock_csv']]);
