<?php
// Test-only bootstrap. Never load this from a production route or worker.
$dc16Root='C:/Users/anisi/.codex/recovery/dc16-20261102';
$dc16=json_decode(file_get_contents($dc16Root.'/credentials.json'),true,512,JSON_THROW_ON_ERROR);
if($dc16['marker']!=='AIMS_DC16_ISOLATED_20261102'||$dc16['database']!=='aims_dc16_20261102')throw new RuntimeException('DC16 identity mismatch');
$mode=$argv[1]??'inspect';
$environment=['APP_ENV'=>'dc16','APP_DEBUG'=>'false','APP_KEY'=>$dc16['app_key'],'JWT_SECRET'=>$dc16['jwt_secret'],'APP_OPERATION_MODE'=>'online','DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3320','DB_DATABASE'=>$dc16['database'],'DB_USERNAME'=>$dc16[$mode==='migrate'?'migration':'runtime'],'DB_PASSWORD'=>$dc16['password'],'DB_URL'=>'','LARAVEL_STORAGE_PATH'=>$dc16Root.'/storage','AIMS_DOCUMENT_ROOT'=>$dc16Root.'/storage/app/documents-private','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'database','BROADCAST_CONNECTION'=>'log','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','REDIS_ENABLED'=>'false','AIMS_OBSERVABILITY_ENABLED'=>'true','APP_URL'=>'http://127.0.0.1:8026','FRONTEND_URL'=>'http://127.0.0.1:4186'];
$environment['AIMS_ML_PYTHON']='C:/Users/anisi/AppData/Local/Programs/Python/Python312/python.exe';
$environment['ASSISTANT_PROVIDER']='deterministic';
$environment['AIMS_MAIL_ENABLED']='false';
foreach($environment as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
foreach(['framework/cache/data','framework/sessions','framework/views','logs','app/documents-private','app/private','app/public','app/installation-backups','evidence','sources'] as $dir){$path=$dc16Root.'/storage/'.$dir;if(!is_dir($path))mkdir($path,0700,true);}
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Laravel's console bootstrap is used outside Artisan here. Ensure an uncaught
// harness exception is a failing OS exit code, never a successful batch result.
set_exception_handler(function(Throwable $error)use($dc16Root){
    file_put_contents($dc16Root.'/storage/evidence/unhandled-harness-failure.json',json_encode(['exception'=>get_class($error),'message'=>$error->getMessage(),'at'=>date(DATE_ATOM)],JSON_PRETTY_PRINT));
    fwrite(STDERR,get_class($error).": DC16 stage failed; inspect private evidence.\n");
    exit(1);
});
if(config('database.connections.mysql.database')!==$dc16['database']||(string)config('database.connections.mysql.port')!=='3320')throw new RuntimeException('Unsafe target');
if(config('app.key')!==$dc16['app_key'] || str_replace('\\','/',storage_path())!==$dc16Root.'/storage' || config('synthetic.document_root')!==$dc16Root.'/storage/app/documents-private' || config('mail.default')!=='array')throw new RuntimeException('Unsafe cached key, storage or mail configuration');
$db=Illuminate\Support\Facades\DB::connection();
if($db->table('_dc16_manifest')->value('marker')!==$dc16['marker'])throw new RuntimeException('Unowned database');
if(!str_starts_with($db->selectOne('SELECT VERSION() AS v')->v,'11.4.13'))throw new RuntimeException('Wrong server');
// External integrations are never part of synthetic replay. Any HTTP call fails closed.
Illuminate\Support\Facades\Http::preventStrayRequests();

function dc16Evidence(string $name,array $data): void {
    global $dc16Root;
    $path=$dc16Root.'/storage/evidence/'.$name.'.json';
    file_put_contents($path.'.tmp',json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    rename($path.'.tmp',$path);
}
