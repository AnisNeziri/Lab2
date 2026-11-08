<?php
namespace App\Services\Commercial;

use App\Models\Company;
use App\Services\InstallationBackupService;
use App\Services\ProductionReadinessService;
use App\Support\Release;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CommercialReadiness
{
    private function checkpoint(): ?array
    {
        try {
            $value=DB::table('installation_state')->where('key','backup_checkpoint')->value('value');
            return $value?json_decode(\Illuminate\Support\Facades\Crypt::decryptString($value),true):null;
        } catch (\Throwable) { return null; }
    }
    public function health(?Company $target=null): array
    {
        $company=$target?->id ?? auth()->user()->company_id; $checks=[];
        $updateFile=storage_path('framework/aims-update-state.json');
        $updateState=is_file($updateFile)?json_decode(file_get_contents($updateFile),true):null;
        if(is_array($updateState)) $updateState=array_intersect_key($updateState,array_flip(['stage','migration_started','migration_completed','code_changed','failed']));
        $add=function($key,$status,$detail)use(&$checks){$checks[]=['key'=>$key,'status'=>$status,'detail'=>$detail];};
        try { $identity=app(InstallationIdentity::class)->id(); $add('encryption_key','healthy','Installation key evidence verified.'); }
        catch (\Throwable) { $identity=null; $add('encryption_key','blocked','The original encryption key or installation evidence is missing. Do not generate a new key.'); }
        try { DB::select('select 1');$add('database','healthy','Database connected.'); }catch(\Throwable){$add('database','blocked','Database connection failed.');}
        $pending=array_diff(array_keys(app('migrator')->getMigrationFiles(database_path('migrations'))),DB::table('migrations')->pluck('migration')->all());
        $add('migrations',$pending?'attention':'healthy',$pending?count($pending).' migrations require the approved upgrade workflow.':'Schema is current.');
        foreach (['default','supply-optimizer','strategic-simulation','scheduler'] as $name) {
            $file=storage_path('framework/aims-heartbeat-'.$name.'.json');$heartbeat=is_file($file)?json_decode(file_get_contents($file),true):null;
            $fresh=$heartbeat && ($heartbeat['at']??0)>=time()-config('production.worker_max_age') && ($heartbeat['version']??'')===Release::version();
            $add($name,$fresh?'healthy':'attention',$fresh?'Current release heartbeat received.':'Worker/scheduler heartbeat missing or stale; start the supervised process.');
        }
        $add('storage',is_writable(storage_path())?'healthy':'blocked',is_writable(storage_path())?'Storage writable.':'Storage is not writable.');
        if (config('system.redis_enabled')) {
            try { app('redis')->connection()->ping();$add('redis','healthy','Redis reachable.'); }catch(\Throwable){$add('redis','attention','Redis unavailable.');}
        }else $add('redis','not_configured','Optional Redis is disabled for this installation.');
        $add('mail',config('production.mail_enabled')?'not_probed':'not_configured',config('production.mail_enabled')?'Mail is configured; delivery is not probed or sent by this page.':'Email recovery requires the existing mail integration.');
        $add('reverb','not_probed','Existing Reverb transport retained; network delivery is not inferred from configuration.');
        $backup=$this->checkpoint();
        $add('backup',$backup && \Carbon\Carbon::parse($backup['verified_at'])->gt(now()->subDay())?'healthy':'attention',$backup?'A verified archive checkpoint is recorded. Reverify before an update.':'No authenticated archive checkpoint is recorded.');
        $c=Company::findOrFail($company);$platform=$c->platform_settings??[];
        $add('integrations','healthy',($platform['api_enabled']??false)?'Integration API enabled; scoped credentials required.':'Integration API disabled.');
        $add('observability',($platform['observability_enabled']??false)?'healthy':'not_configured',($platform['observability_enabled']??false)?'Local sanitized tracing enabled.':'Local tracing disabled by company policy.');
        $license=app(Entitlements::class)->state($company);
        $add('license',$license['status']==='active'?'healthy':'attention','License/package state: '.$license['status'].'. Manual capability configuration is not an issuer-signed commercial license.');
        if (!$target) {
            $add('onboarding',$c->operational_cutover_date?'healthy':'attention',$c->operational_cutover_date?'Operational cutover configured.':'Complete company opening in the existing onboarding workspace.');
            $add('accounting',$c->accounting_start_date?'healthy':'attention',$c->accounting_start_date?'Accounting start date configured.':'Review the accounting setup and opening balances.');
        }
        $add('fiscalization','review','Review the existing fiscalization test/production boundary if this capability is used.');
        $add('configuration',collect(app(ProductionReadinessService::class)->configuration())->contains('status','critical')?'attention':'healthy','Run aims:production-check before commercial deployment; development configuration is not certification.');
        $commissioning=$target ? null : app(\App\Services\SystemIntegrityService::class)->operationalReadiness()['commissioning'];
        return ['commissioning'=>$commissioning,'version'=>Release::metadata(),'installation_id'=>$identity,'company_id'=>$company,'company_public_id'=>$c->public_id,
            'checked_at'=>now()->toIso8601String(),'status'=>($commissioning['status']??null)==='BLOCKED'?'blocked':(collect($checks)->contains('status','blocked')?'blocked':'attention'),
            'checks'=>$checks,'license'=>$license,'capability_catalog'=>Entitlements::CAPABILITIES,'packages'=>Entitlements::PACKAGES,
            'backup'=>$backup?array_intersect_key($backup,array_flip(['id','verified_at','release','engine','sha256'])):null,
            'queues'=>['pending'=>DB::table('jobs')->where('company_id',$company)->whereNull('reserved_at')->count(),'processing'=>DB::table('jobs')->where('company_id',$company)->whereNotNull('reserved_at')->count(),
                'oldest'=>DB::table('jobs')->where('company_id',$company)->min('created_at')],
            'installation_update_state'=>$updateState,
            'recent_update_checks'=>DB::table('update_checks')->where('company_id',$company)->latest('id')->limit(10)->get(['id','target_version','stage','migration_started','code_changed','created_at'])];
    }
    public function verifyBackup(string $name,string $passphrase): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.aimsinstall$/D',$name)) abort(422,'Select an existing installation archive filename.');
        $root=realpath(config('production.backup_root')); $file=$root?realpath($root.DIRECTORY_SEPARATOR.$name):false;
        if (!$file || is_link($root.DIRECTORY_SEPARATOR.$name) || dirname($file)!==$root) abort(422,'Archive is outside the protected backup directory.');
        $payload=app(InstallationBackupService::class)->verify($file,$passphrase);
        $identity=app(InstallationIdentity::class); $identity->id();
        abort_unless(hash_equals($payload['app_key_fingerprint']??'',hash('sha256',(string)config('app.key'))),422,'This backup requires a different application key.');
        abort_unless($payload['engine']===DB::getDriverName(),422,'Backup database engine differs from this installation.');
        $checkpoint=['id'=>(string)\Illuminate\Support\Str::uuid(),'verified_at'=>now()->toIso8601String(),'release'=>$payload['app_version'],'engine'=>$payload['engine'],
            'database_fingerprint'=>hash('sha256',DB::connection()->getDatabaseName()),'key_fingerprint'=>$payload['app_key_fingerprint'],
            'sha256'=>hash_file('sha256',$file),'file'=>$name,'snapshot_at'=>$payload['created_at']];
        $identity->write('backup_checkpoint',json_encode($checkpoint));
        return array_intersect_key($checkpoint,array_flip(['id','verified_at','release','engine','sha256']));
    }
    public function preflight(array $envelope,bool $workersStopped,?Company $target=null): array
    {
        $companyId=$target?->id ?? auth()->user()->company_id;
        $target=app(UpdateManifest::class)->read($envelope);
        $checks=[];$add=function($key,$ready,$detail)use(&$checks){$checks[]=['key'=>$key,'status'=>$ready?'ready':'blocked','detail'=>$detail];};
        $add('signature',true,'Trusted issuer signature verified. No artifact was downloaded or executed.');
        $add('version',version_compare($target['version'],Release::version(),'>') && version_compare(Release::version(),$target['minimum_version'],'>='),'Current and target versions must follow the signed supported upgrade path.');
        $add('runtime',version_compare(PHP_VERSION,$target['minimum_php'],'>='),'PHP '.PHP_VERSION.'; signed minimum '.$target['minimum_php'].'.');
        try { DB::select('select 1');$db=true; }catch(\Throwable){$db=false;}$add('database',$db,'Database connectivity checked.');
        try { $key=app(InstallationIdentity::class)->verify(); }catch(\Throwable){$key=false;}$add('encryption_key',$key,'The original key must decrypt installation evidence.');
        $free=disk_free_space(storage_path());$add('free_space',$free!==false && $free>=config('security.minimum_free_bytes'),'At least 512 MiB must remain available; artifact-specific space must also be checked by the deployment operator.');
        $backup=$this->checkpoint(); $file=$backup?config('production.backup_root').DIRECTORY_SEPARATOR.$backup['file']:null;
        $backupReady=$backup && $file && !is_link($file) && is_file($file) && hash_equals($backup['sha256'],hash_file('sha256',$file))
            && hash_equals($backup['key_fingerprint'],hash('sha256',(string)config('app.key')))
            && hash_equals($backup['database_fingerprint'],hash('sha256',DB::connection()->getDatabaseName()))
            && \Carbon\Carbon::parse($backup['verified_at'])->gt(now()->subDay()) && \Carbon\Carbon::parse($backup['snapshot_at'])->gt(now()->subDay());
        $add('backup',$backupReady,'An authenticated archive, recent snapshot, current database/key and unchanged archive checksum are required.');
        $inflight=DB::table('jobs')->whereNotNull('reserved_at')->count();
        $add('workers',$workersStopped && $inflight===0,'The operator must stop supervised workers and confirm quiescence; no reserved jobs may remain.');
        $pending=array_values(array_diff(array_keys(app('migrator')->getMigrationFiles(database_path('migrations'))),DB::table('migrations')->pluck('migration')->all()));
        $ready=!collect($checks)->contains('status','blocked');
        $id=DB::table('update_checks')->insertGetId(['company_id'=>$companyId,'user_id'=>auth()->id(),'target_version'=>$target['version'],
            'manifest_hash'=>hash('sha256',json_encode($envelope)),'stage'=>$ready?'preflight_ready':'preflight_blocked','checks'=>json_encode($checks),'created_at'=>now(),'updated_at'=>now()]);
        return ['id'=>$id,'status'=>$ready?'ready':'blocked','checks'=>$checks,'pending_migrations'=>$pending,'target_migration_level'=>$target['migration_level'],
            'migration_started'=>false,'code_changed'=>false,'rollback'=>'No update has run. Database rollback is not promised; use the verified backup into a separate installation if an applied migration cannot safely reverse.'];
    }
    public function support(?Company $target=null): array
    {
        $health=$this->health($target);$company=$target?->id ?? auth()->user()->company_id;
        return ['format'=>'aims-support-v1','generated_at'=>now()->toIso8601String(),'version'=>Release::metadata(),
            'runtime'=>['php'=>PHP_VERSION,'laravel'=>app()->version(),'database_driver'=>DB::getDriverName(),'cache_driver'=>config('cache.default'),'queue_driver'=>config('queue.default')],
            'migration_state'=>['applied'=>DB::table('migrations')->count(),'latest'=>DB::table('migrations')->orderByDesc('id')->value('migration')],
            'health'=>['status'=>$health['status'],'checks'=>$health['checks'],'queues'=>$health['queues']],
            'integrations'=>['service_accounts'=>DB::table('service_accounts')->where('company_id',$company)->where('status','active')->count(),
                'failed_webhooks'=>DB::table('webhook_deliveries')->where('company_id',$company)->whereIn('status',['failed','retrying'])->count()],
            'recent_errors'=>$target ? [] : DB::table('platform_spans')->where('company_id',$company)->where('status','error')->latest('id')->limit(30)->get(['trace_id','span_id','name','duration_ms','created_at']),
            'presence'=>['app_key'=>filled(config('app.key')),'jwt_key'=>filled(config('jwt.secret')),'license_issuer'=>is_readable(config('security.license_public_key')??''),'update_issuer'=>is_readable(config('security.update_public_key')??''),
                'smtp_password'=>filled(config('mail.mailers.smtp.password'))],
            'policy'=>'Allowlisted technical metadata only; no logs, environment dumps, business rows, secrets or uploaded files.'];
    }
}
