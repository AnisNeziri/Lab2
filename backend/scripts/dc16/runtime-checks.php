<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/ProbeJob.php';
use Illuminate\Support\Facades\{DB,Artisan,Queue};
use Symfony\Component\Process\Process;
if(($argv[1]??'')==='worker'){
 exit(Artisan::call('queue:work',['--queue'=>'dc16-probe','--once'=>true,'--tries'=>1,'--timeout'=>30]));
}
if(is_file($dc16Root.'/storage/evidence/runtime-checks.json')){echo "Runtime evidence retained; no repeated fault injection.\n";exit;}
if((int)DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')!==364)throw new RuntimeException('Year incomplete');
if(DB::table('jobs')->where('queue','dc16-probe')->exists())throw new RuntimeException('Inspect pending probe before resuming');
$before=DB::table('journal_entries')->count();$pids=[];
foreach(['first','restart'] as $id){
 Queue::connection('database')->pushOn('dc16-probe',new Dc16\ProbeJob($id));
 $p=new Process([PHP_BINARY,__FILE__,'worker'],base_path(),null,null,45);$p->mustRun();
}
$entries=array_map(fn($line)=>json_decode($line,true),file($dc16Root.'/storage/evidence/worker-probe.jsonl',FILE_IGNORE_NEW_LINES));
if(count($entries)!==2||$entries[0]['pid']===$entries[1]['pid']||DB::table('jobs')->where('queue','dc16-probe')->exists())throw new RuntimeException('Worker restart verification failed');
$scheduler=Artisan::call('schedule:list');file_put_contents($dc16Root.'/storage/evidence/scheduler-list.txt',Artisan::output());
$port=config('database.connections.mysql.port');$failed=false;
try{config(['database.connections.mysql.port'=>65530]);DB::purge();DB::select('SELECT 1');}
catch(Throwable $e){$failed=true;}
finally{config(['database.connections.mysql.port'=>$port]);DB::purge();}
$recovered=(int)DB::selectOne('SELECT 1 AS ok')->ok===1;
$cipher=DB::table('installation_state')->where('key','key_check')->value('value');$wrongKeyRejected=false;
try{(new Illuminate\Encryption\Encrypter(random_bytes(32),'AES-256-CBC'))->decryptString($cipher);}catch(Illuminate\Contracts\Encryption\DecryptException){$wrongKeyRejected=true;}
$identity=app(App\Services\Commercial\InstallationIdentity::class)->verify();
dc16Evidence('runtime-checks',['workers'=>['status'=>'PASS','observations'=>$entries,'separate_process_restart'=>true,'queue'=>'dc16-probe','business_jobs_consumed'=>false],'scheduler'=>['status'=>$scheduler===0?'PASS':'FAIL','scope'=>'Schedule initialization/listing; not every scheduled business job executed'],'database_reconnect'=>['status'=>$failed&&$recovered?'PASS':'FAIL','scope'=>'Only this test client temporarily targets unavailable port; server and other installations uninterrupted'],'key_compatibility'=>['original_key_works'=>$identity,'wrong_key_rejected'=>$wrongKeyRejected],'journals_unchanged'=>$before===DB::table('journal_entries')->count(),'desktop_offline'=>'NOT TESTED — no isolated Electron profile launched','unknown_external_outcomes'=>'BLOCKED — no provider sandbox contacted']);
echo "Worker restart, scheduler initialization, client connection recovery and encryption boundary checked.\n";
