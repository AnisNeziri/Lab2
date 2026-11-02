<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{Artisan,Auth,Crypt,DB,Schema};
use App\Services\Commercial\{CommercialReadiness,InstallationIdentity,ReleaseTree};

if(!app()->environment('testing') || config('database.default')!=='sqlite' || basename(config('database.connections.sqlite.database'))!=='dc8-upgrade.sqlite' || Schema::hasColumn('companies','security_policy')) throw new RuntimeException('Requires an isolated populated pre-DC8 clone.');
$rows=fn($table,$columns)=>DB::table($table)->select($columns)->get()->map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR))->all();$before=[];
foreach(Schema::getTableListing() as $table){
    $name=last(explode('.',$table));if($name==='migrations' || str_starts_with($name,'sqlite_'))continue;
    $columns=Schema::getColumnListing($table);$before[$table]=['columns'=>$columns,'rows'=>$rows($table,$columns)];
}
if(Artisan::call('migrate',['--force'=>true])!==0)throw new RuntimeException(Artisan::output());
foreach($before as $table=>$record) if(array_diff($record['rows'],$rows($table,$record['columns'])))throw new RuntimeException('Pre-existing rows changed in '.$table);
$upgrade=['result'=>'PASS','tables_preserved'=>count($before),'existing_rows_preserved'=>array_sum(array_map(fn($r)=>count($r['rows']),$before)),'migrate_fresh_used'=>false];

// A separate small, healthy installation proves a real authenticated archive checkpoint.
$root=realpath(base_path('../output')).DIRECTORY_SEPARATOR.'dc8-preflight';if(!is_dir($root))mkdir($root,0700,true);
$clean=$root.'/installation.sqlite';if(file_exists($clean))throw new RuntimeException('Preflight fixture already exists; preserve it for review.');touch($clean);
foreach(['framework','app/private','app/public','documents','backups','release/artifacts','release/backend'] as $dir)if(!is_dir($root.'/'.$dir))mkdir($root.'/'.$dir,0700,true);
app()->useStoragePath($root);config(['database.connections.sqlite.database'=>$clean,'production.backup_root'=>$root.'/backups','synthetic.document_root'=>$root.'/documents']);DB::purge('sqlite');
if(Artisan::call('migrate',['--force'=>true])!==0)throw new RuntimeException(Artisan::output());
$company=App\Models\Company::factory()->create(['name'=>'DC8 Recovery Synthetic']);$owner=App\Models\User::factory()->create(['company_id'=>$company->id,'role'=>'admin','email_verified_at'=>now()]);Auth::setUser($owner);
app(App\Services\AccountingService::class)->initialize();
$key=bin2hex(random_bytes(24));$archive=app(App\Services\InstallationBackupService::class)->create($key);
$checkpoint=app(CommercialReadiness::class)->verifyBackup($archive['file'],$key);
$private=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);if(!$private)throw new RuntimeException('OpenSSL signing fixture requires a configured OpenSSL environment.');
$pub=$root.'/issuer-public.pem';file_put_contents($pub,openssl_pkey_get_details($private)['key']);config(['security.update_public_key'=>$pub]);
file_put_contents($root.'/release/artifacts/AIMS-synthetic.zip','Synthetic staged release bytes; never executed.');file_put_contents($root.'/release/backend/artisan','Synthetic immutable release entry point; never executed.');
$payload=['schema'=>2,'product'=>'AIMS','kind'=>'update','version'=>'1.1.3','build_id'=>'dc8-verification-fixture','commit'=>str_repeat('b',40),'migration_level'=>'2026_10_31_000004',
    'minimum_php'=>'8.3.35','minimum_version'=>'1.1.2-rc.1','released_at'=>now()->toIso8601String(),'compatibility'=>['api'=>1],
    'tree_sha256'=>app(ReleaseTree::class)->digest($root.'/release'),'artifacts'=>[['filename'=>'AIMS-synthetic.zip','sha256'=>hash_file('sha256',$root.'/release/artifacts/AIMS-synthetic.zip')]]];
$raw=json_encode($payload);openssl_sign($raw,$signature,$private,OPENSSL_ALGO_SHA256);$envelope=['payload'=>rtrim(strtr(base64_encode($raw),'+/','-_'),'='),'signature'=>base64_encode($signature)];
$preflight=app(CommercialReadiness::class)->preflight($envelope,true);if($preflight['status']!=='ready')throw new RuntimeException(json_encode($preflight));
$manifest=$root.'/release/UPDATE.signed.json';file_put_contents($manifest,json_encode($envelope));
$options=['--file'=>$manifest,'--artifacts-root'=>$root.'/release/artifacts','--release-root'=>$root.'/release'];
if(Artisan::call('aims:verify-release',$options)!==0)throw new RuntimeException(Artisan::output());
file_put_contents($root.'/release/backend/unlisted.php','<?php // Harmless synthetic proof: unlisted executable additions must fail verification.');
if(Artisan::call('aims:verify-release',$options)===0)throw new RuntimeException('Unlisted staged code was accepted.');
$original=Crypt::getFacadeRoot();$keyRejected=false;
try{Crypt::swap(new Illuminate\Encryption\Encrypter(random_bytes(32),'AES-256-CBC'));try{app(InstallationIdentity::class)->verify();}catch(RuntimeException){$keyRejected=true;}}
finally{Crypt::swap($original);}
if(!$keyRejected)throw new RuntimeException('Wrong restoration key was accepted.');
echo json_encode(['upgrade'=>$upgrade,'authenticated_archive_checkpoint'=>'PASS','signed_preflight'=>$preflight['status'],'staged_tree_and_artifact_verification'=>'PASS','unlisted_code_tampering_rejected'=>true,'wrong_encryption_key_rejected'=>true,'migration_started_by_preflight'=>$preflight['migration_started'],'code_changed_by_preflight'=>$preflight['code_changed']],JSON_PRETTY_PRINT)."\n";
