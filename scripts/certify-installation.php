<?php
// Only the owned PM3 synthetic source is accepted. All writes target fresh
// per-run Installation A/B directories under output; never the web database.
$root = dirname(__DIR__);
$source = $root.'/backend/storage/app/synthetic/aims-pm3-20261006.sqlite';
$sourceDocuments = dirname($source).'/documents-20261006';
$pdo = new PDO('sqlite:'.$source);
$marker = $pdo->query('select marker, seed, status from _aims_synthetic_manifest')->fetch(PDO::FETCH_ASSOC);
if (($marker['marker'] ?? '') !== 'AIMS_PM3_SYNTHETIC_ONLY' || (int) $marker['seed'] !== 20261006 || $marker['status'] !== 'complete') throw new RuntimeException('Completed owned PM3 source required.');
$run = $root.'/output/pr1-dr-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
mkdir($run.'/A/storage/framework', 0700, true);
mkdir($run.'/B/storage/framework', 0700, true);
mkdir($run.'/A/documents', 0700, true); mkdir($run.'/B/documents', 0700, true);
$pdo->exec('VACUUM INTO '.$pdo->quote($run.'/A/aims.sqlite')); $pdo = null;
if (is_dir($sourceDocuments)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDocuments, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isLink()) throw new RuntimeException('Source document link rejected.');
    if (!$file->isFile()) continue;
    $relative = substr($file->getPathname(), strlen($sourceDocuments) + 1);
    $target = $run.'/A/documents/'.$relative;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
    copy($file->getPathname(), $target);
}
$key = 'base64:'.base64_encode(random_bytes(32));
foreach (['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>$key,'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$run.'/A/aims.sqlite','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','BROADCAST_CONNECTION'=>'log','AIMS_DOCUMENT_ROOT'=>$run.'/A/documents','APP_CONFIG_CACHE'=>$run.'/unused-config.php'] as $name=>$value) putenv($name.'='.$value);
require $root.'/backend/vendor/autoload.php';
require __DIR__.'/certification-common.php';
certificationAssertBaseline(new PDO('sqlite:'.$source));
$app = require $root.'/backend/bootstrap/app.php';
$app->useStoragePath($run.'/A/storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$service = app(App\Services\InstallationBackupService::class);
config(['production.backup_root'=>$run.'/A/backups','logging.default'=>'stderr']);
$passphrase = bin2hex(random_bytes(24)); // Kept in memory; never written beside archive.
$report = ['source'=>'owned PM3 representative company','source_installation'=>'A','destination_installation'=>'B','installation_b'=>$run.'/B','upgrade_baseline'=>'5db86866 / AIMS desktop 1.1.0'];
try {
    // Existing populated installation: backup precedes forward-only migration.
    $backup = $service->create($passphrase);
    $criticalRecords=certificationCriticalRecords();
    $report['backup'] = $backup;
    $archive = $run.'/A/backups/'.$backup['file'];
    try { $service->verify($archive, 'incorrect-independent-key'); throw new RuntimeException('Wrong key accepted.'); }
    catch (RuntimeException $error) { if (!str_contains($error->getMessage(),'verification failed')) throw $error; $report['wrong_key_rejected']=true; }
    $damaged=$archive.'.damaged'; $bytes=file_get_contents($archive); $bytes[(int)(strlen($bytes)/2)]=$bytes[(int)(strlen($bytes)/2)]==='X'?'Y':'X'; file_put_contents($damaged,$bytes); unset($bytes);
    try { $service->verify($damaged,$passphrase); throw new RuntimeException('Corrupted backup accepted.'); }
    catch(RuntimeException $error) { if(!str_contains($error->getMessage(),'verification failed'))throw $error; $report['corrupted_archive_rejected']=true; } finally { unlink($damaged); }
    if (Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true])!==0) throw new RuntimeException('Populated migration failed.');
    $report['populated_forward_migration'] = $service->reconcile()['healthy'];
    $sourceUser = App\Models\User::withoutGlobalScopes()->where('email','owner@aims-demo.test')->firstOrFail();
    $report['source_login_password_valid'] = Illuminate\Support\Facades\Hash::check(config('synthetic.password'), $sourceUser->password);
    // A is disconnected before B starts. B has separate DB, storage and docs.
    Illuminate\Support\Facades\DB::purge();
    $app->useStoragePath($run.'/B/storage');
    touch($run.'/B/aims.sqlite');
    config(['database.connections.sqlite.database'=>$run.'/B/aims.sqlite','synthetic.document_root'=>$run.'/B/documents','production.backup_root'=>$run.'/B/backups']);
    Illuminate\Support\Facades\Auth::forgetUser();
    $report['restore'] = $service->restore($archive, $passphrase);
    $report['critical_records_preserved']=certificationCriticalRecords()===$criticalRecords;
    if(!$report['critical_records_preserved'])throw new RuntimeException('Settings/preferences/intelligence or business records changed during restore.');
    $owner = App\Models\User::withoutGlobalScopes()->where('email','owner@aims-demo.test')->firstOrFail();
    $report['restored_login_password_valid'] = Illuminate\Support\Facades\Hash::check(config('synthetic.password'), $owner->password);
    $login=certificationRequest('POST','/api/login',['email'=>$owner->email,'password'=>config('synthetic.password')]);
    $report['restored_http_login_verified']=$login['status']===200 && isset($login['body']['access_token']);
    if(!$report['restored_http_login_verified'])throw new RuntimeException('Restored HTTP login failed.');
    Illuminate\Support\Facades\Auth::setUser($owner);
    $report['system_integrity'] = app(App\Services\SystemIntegrityService::class)->snapshot();
    $report['api_service_baseline_ms'] = [];
    $calls = [
        'Dashboard'=>fn()=>app(App\Services\DashboardService::class)->getMetrics($owner->company_id),
        'Order Hub'=>fn()=>app(App\Services\OrderHubService::class)->listing([]),
        'Product detail'=>fn()=>App\Models\Product::query()->with('category')->firstOrFail()->toArray(),
        'Inventory planning'=>fn()=>app(App\Services\InventoryPlanningService::class)->view(App\Models\Product::query()->firstOrFail()->id),
        'Shipment detail'=>fn()=>app(App\Services\ShipmentLogisticsService::class)->details(App\Models\Shipment::query()->firstOrFail()),
        'Financial intelligence'=>fn()=>app(App\Services\FinancialIntelligenceService::class)->latest(),
        'Customer intelligence'=>fn()=>app(App\Services\CustomerSalesIntelligenceService::class)->latest(),
        'Global search'=>fn()=>app(App\Services\SearchService::class)->search('Milano'),
        'Ask AIMS'=>fn()=>app(App\Services\IntelligenceAssistantService::class)->ask(['question'=>'What needs my attention today?','language'=>'en']),
    ];
    foreach($calls as $label=>$call) { $started=microtime(true); $call(); $report['api_service_baseline_ms'][$label]=round((microtime(true)-$started)*1000,2); }
    $report['tenant_route_matrix']=certificationTenantMatrix($owner->company_id);
    $report['status']='PASS';
} catch (Throwable $error) {
    $report['status']='FAIL'; $report['failure']=$error->getMessage();
}
file_put_contents($root.'/output/pr1-independent-restore.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['status'=>$report['status'],'report'=>'output/pr1-independent-restore.json','failure'=>$report['failure']??null],JSON_PRETTY_PRINT).PHP_EOL;
exit($report['status']==='PASS'?0:1);
