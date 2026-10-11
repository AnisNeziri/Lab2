<?php
// Requires explicitly named, isolated certification databases. Never creates,
// drops, truncates or seeds a database selected by the ordinary application.
$root=dirname(__DIR__);
if(getenv('AIMS_CERTIFICATION_MYSQL')!=='1'||getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1')throw new RuntimeException('Explicit isolated MariaDB certification environment required.');
$a=getenv('DB_DATABASE'); $b=getenv('AIMS_CERTIFICATION_RESTORE_DATABASE');
foreach([$a,$b] as $name)if(!preg_match('/^aims_(pr1|ci)_[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Certification database prefix required.');
if($a===$b)throw new RuntimeException('Two distinct empty databases required.');
$source=$root.'/backend/storage/app/synthetic/aims-pm3-20261006.sqlite';
$sqlite=new PDO('sqlite:'.$source);
$marker=$sqlite->query('select marker,seed,status from _aims_synthetic_manifest')->fetch(PDO::FETCH_ASSOC);
if(($marker['marker']??'')!=='AIMS_PM3_SYNTHETIC_ONLY'||(int)$marker['seed']!==20261006||$marker['status']!=='complete')throw new RuntimeException('Owned completed PM3 source required.');
$run=$root.'/output/pr1-maria-dr-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
foreach(['A','B'] as $install)foreach(['storage/framework','documents','backups'] as $directory)mkdir($run.'/'.$install.'/'.$directory,0700,true);
foreach(['APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','BROADCAST_CONNECTION'=>'log','APP_CONFIG_CACHE'=>$run.'/config.php','AIMS_DOCUMENT_ROOT'=>$run.'/A/documents'] as $key=>$value) {putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
require $root.'/backend/vendor/autoload.php'; require __DIR__.'/certification-common.php';
certificationAssertBaseline($sqlite);
$app=require $root.'/backend/bootstrap/app.php';$app->useStoragePath($run.'/A/storage');$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['production.backup_root'=>$run.'/A/backups','logging.default'=>'stderr','production.mysql'=>getenv('AIMS_MYSQL_CLIENT')?:'mysql','production.mysqldump'=>getenv('AIMS_MYSQL_DUMP')?:'mysqldump']);
$report=['source'=>'owned PM3 representative company','engine'=>'MariaDB','upgrade_baseline'=>'5db86866 / AIMS desktop 1.1.0'];
try {
    if(config('database.default')!=='mysql'||config('database.connections.mysql.database')!==$a||config('database.connections.mysql.host')!=='127.0.0.1')throw new RuntimeException('Resolved database differs from explicit certification target.');
    if(Illuminate\Support\Facades\Schema::getTables(Illuminate\Support\Facades\Schema::getCurrentSchemaListing())!==[])throw new RuntimeException('Installation A certification database must be empty.');
    $paths=array_map(fn($path)=>'database/migrations/'.basename($path),array_filter(glob($root.'/backend/database/migrations/*.php'),fn($path)=>!str_contains(basename($path),'2026_10_11_')));
    if(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true,'--path'=>$paths])!==0)throw new RuntimeException('Previous baseline schema failed.');
    $mysql=Illuminate\Support\Facades\DB::getPdo();$mysql->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $mysql->beginTransaction();
        foreach(Illuminate\Support\Facades\Schema::getTables(Illuminate\Support\Facades\Schema::getCurrentSchemaListing()) as $table) {
            $name=$table['name'];if($name==='migrations')continue;
            // Only this freshly created, guard-verified scratch installation:
            // replace migration-provisioned reference rows with the old data.
            Illuminate\Support\Facades\DB::table($name)->delete();
            if(!$sqlite->query("select 1 from sqlite_master where type='table' and name=".$sqlite->quote($name))->fetchColumn())continue;
            $rows=$sqlite->query('select * from "'.$name.'"'); $statement=null;
            while($row=$rows->fetch(PDO::FETCH_ASSOC)) {
                if(!$statement)$statement=$mysql->prepare('INSERT INTO `'.$name.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
                $statement->execute(array_values($row));
            }
        }
        $mysql->commit();
    } catch(Throwable $error) {if($mysql->inTransaction())$mysql->rollBack();throw $error;}
    finally {$mysql->exec('SET FOREIGN_KEY_CHECKS=1');}
    $sourceDocuments=dirname($source).'/documents-20261006';
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDocuments,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()) {
        if($file->isLink())throw new RuntimeException('Document symlinks rejected.');$relative=substr($file->getPathname(),strlen($sourceDocuments)+1);$target=$run.'/A/documents/'.$relative;if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);copy($file->getPathname(),$target);
    }
    $service=app(App\Services\InstallationBackupService::class); $key=bin2hex(random_bytes(24));
    $report['baseline_reconciliation']=$service->reconcile();
    $backup=$service->create($key); $archive=$run.'/A/backups/'.$backup['file'];$report['backup']=$backup;
    if(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true])!==0)throw new RuntimeException('Populated forward migration failed.');
    $report['populated_forward_migration']=$service->reconcile()['healthy'];
    $critical=certificationCriticalRecords();
    Illuminate\Support\Facades\DB::purge();$app->useStoragePath($run.'/B/storage');config(['database.connections.mysql.database'=>$b,'synthetic.document_root'=>$run.'/B/documents','production.backup_root'=>$run.'/B/backups']);
    $report['restore']=$service->restore($archive,$key);
    $report['critical_records_preserved']=certificationCriticalRecords()===$critical;
    if(!$report['critical_records_preserved'])throw new RuntimeException('Restored business/settings/intelligence records mismatch.');
    $owner=App\Models\User::where('email','owner@aims-demo.test')->firstOrFail();
    $login=certificationRequest('POST','/api/login',['email'=>$owner->email,'password'=>config('synthetic.password')]);
    if($login['status']!==200||!isset($login['body']['access_token']))throw new RuntimeException('Restored MariaDB login failed.');
    $report['restored_http_login_verified']=true;
    $report['populated_business_workflows']=certificationBusinessWorkflows($owner);
    $report['tenant_route_matrix']=certificationTenantMatrix($owner->company_id);
    $report['status']='PASS';
} catch(Throwable $error) {$report['status']='FAIL';$report['failure']=$error->getMessage();}
file_put_contents($root.'/output/pr1-mariadb-populated-restore.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['status'=>$report['status'],'failure'=>$report['failure']??null]).PHP_EOL;exit($report['status']==='PASS'?0:1);
