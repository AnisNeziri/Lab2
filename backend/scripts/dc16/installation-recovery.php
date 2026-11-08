<?php
// Full installation rehearsal. No active application configuration is changed.
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{DB,Auth};
use App\Services\InstallationBackupService;
if((int)DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')!==364)throw new RuntimeException('Year incomplete');
if(!DB::selectOne("SELECT GET_LOCK('aims_dc16_replay',0) acquired")->acquired)throw new RuntimeException('Another verification/replay writer is active');
$service=app(InstallationBackupService::class);
$binary='C:/Users/anisi/.codex/recovery/dc10-20261101/mariadb-11.4.13-winx64/bin/';
$passwordFile=$dc16Root.'/backup-db-password';
if(!is_file($passwordFile))file_put_contents($passwordFile,$dc16['password']);
$passFile=$dc16Root.'/installation-passphrase';
if(!is_file($passFile))file_put_contents($passFile,bin2hex(random_bytes(32)));
$pass=trim(file_get_contents($passFile));
config(['production.mysqldump'=>$binary.'mariadb-dump.exe','production.mysql'=>$binary.'mariadb.exe','production.backup_db_username'=>$dc16['migration'],'production.backup_db_password_file'=>$passwordFile]);
$stage=$argv[1]??'';
if($stage==='backup'){
    $result=$service->create($pass);
    $payload=$service->verify(config('production.backup_root').'/'.$result['file'],$pass);
    $tables=['products','warehouse_stock','stock_movements','customers','customer_debt_transactions','journal_entries','journal_lines','invoices','invoice_items','payroll_runs','payroll_lines','employees','documents'];
    $checks=[];foreach($tables as $table){$hash=hash_init('sha256');$count=0;foreach(DB::table($table)->orderBy('id')->cursor() as $row){hash_update($hash,json_encode($row));$count++;}$checks[$table]=['rows'=>$count,'sha256'=>hash_final($hash)];}
    dc16Evidence('installation-backup',['result'=>$result,'archive'=>config('production.backup_root').'/'.$result['file'],'files'=>count($payload['files']),'checks'=>$checks,'identity_sha256'=>hash('sha256',app(App\Services\Commercial\InstallationIdentity::class)->id()),'source_database'=>DB::getDatabaseName()]);
    echo "Encrypted full installation archive authenticated and checksummed.\n";exit;
}
if($stage!=='restore')throw new RuntimeException('Choose backup or restore');
$e=json_decode(file_get_contents($dc16Root.'/storage/evidence/installation-backup.json'),true);
$target='aims_dc16_restore_20261103';$targetRoot=$dc16Root.'/installation-b';
$root=json_decode(file_get_contents('C:/Users/anisi/.codex/recovery/dc10-20261101/credentials.json'),true);
$admin=new PDO('mysql:host=127.0.0.1;port=3320','root',$root['root'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!str_starts_with($admin->query('SELECT VERSION()')->fetchColumn(),'11.4.13'))throw new RuntimeException('Wrong server');
if($admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$target'")->fetchColumn())throw new RuntimeException('Restore target already exists; never overwrite a prior attempt');
$admin->exec("CREATE DATABASE $target CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$user='aims_dc16_restore';$quoted=$admin->quote($dc16['password']);
$admin->exec("CREATE USER IF NOT EXISTS '$user'@'127.0.0.1' IDENTIFIED BY $quoted");
$admin->exec("GRANT ALL PRIVILEGES ON $target.* TO '$user'@'127.0.0.1'");$admin=null;
foreach(['framework/cache/data','framework/views','framework/sessions','logs','app/documents-private','app/private','app/public'] as $dir)if(!is_dir($targetRoot.'/'.$dir))mkdir($targetRoot.'/'.$dir,0700,true);
DB::select("SELECT RELEASE_LOCK('aims_dc16_replay')");Auth::forgetGuards();
$app->useStoragePath($targetRoot);
config(['database.connections.mysql.database'=>$target,'database.connections.mysql.username'=>$user,'database.connections.mysql.password'=>$dc16['password'],'synthetic.document_root'=>$targetRoot.'/app/documents-private','production.restore_db_username'=>$user,'production.restore_db_password_file'=>$passwordFile]);DB::purge();
try{
    $result=$service->restore($e['archive'],$pass);$comparison=[];
    foreach($e['checks'] as $table=>$expected){$hash=hash_init('sha256');$count=0;foreach(DB::table($table)->orderBy('id')->cursor() as $row){hash_update($hash,json_encode($row));$count++;}$actual=['rows'=>$count,'sha256'=>hash_final($hash)];$comparison[$table]=['matches'=>$actual===$expected,'actual'=>$actual];}
    $identity=hash_equals($e['identity_sha256'],hash('sha256',app(App\Services\Commercial\InstallationIdentity::class)->id()));
    $proof=['status'=>'PASS','result'=>$result,'source_database'=>$e['source_database'],'destination_database'=>$target,'table_comparison'=>$comparison,'installation_identity_preserved'=>$identity,'recovery_status'=>$service->recoveryStatus(),'active_installation_changed'=>false];
    foreach($comparison as $c)if(!$c['matches'])$proof['status']='FAIL';if(!$identity)$proof['status']='FAIL';
    dc16Evidence('installation-restore',$proof);echo 'Full encrypted restore '.$proof['status']."; see private evidence.\n";
}catch(Throwable $error){dc16Evidence('installation-restore',['status'=>'BLOCKED','message'=>$error->getMessage(),'target'=>$target,'recovery_status'=>$service->recoveryStatus()]);throw $error;}
