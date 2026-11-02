<?php

namespace App\Services;

use App\Models\AccountingException;
use App\Models\BackupRun;
use App\Models\IntegrationOperationLog;
use App\Models\IntegrationProvider;
use App\Models\OperationalException;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemIntegrityService
{
    public function recordReferenceAudit(array $audit): void
    {
        if(!app()->runningInConsole())throw new \LogicException('Reference capture is an operator console operation.');
        $c=config('database.connections.'.config('database.default'));$scope=hash('sha256',DB::getDriverName().':'.($c['host']??'').':'.($c['port']??'').':'.($c['database']??''));
        $file=storage_path('framework/aims-reference-review.json');$report=is_file($file)?json_decode(file_get_contents($file),true):[];
        $key=hash('sha256',(string)config('app.key'));
        if(($report['app_key_fingerprint']??'')!==$key || !in_array($scope,[$report['source_scope']??null,$report['rehearsal_scope']??null],true))$report=['schema'=>1,'source_scope'=>$scope,'app_key_fingerprint'=>$key,'baseline'=>$audit,'findings'=>[],'active_changes_applied'=>false];
        $report[$scope===($report['rehearsal_scope']??null)?'current_rehearsal':'current_source']=$audit;
        $report['migration_ready']=false; // An audit never grants migration approval or waives financial/recovery controls.
        $temporary=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
        if(file_put_contents($temporary,json_encode($report,JSON_PRETTY_PRINT),LOCK_EX)===false || !rename($temporary,$file))throw new \RuntimeException('Reference evidence could not be recorded.');
    }

    /** Read the last operator audit; viewing the page never scans or repairs the database. */
    public function referenceReview(): array
    {
        abort_unless(Auth::user()?->role==='admin' && app(PermissionService::class)->roleHasPermission('admin','deployment.view'),403);
        $c=config('database.connections.'.config('database.default'));
        $scope=hash('sha256',DB::getDriverName().':'.($c['host']??'').':'.($c['port']??'').':'.($c['database']??''));
        $file=storage_path('framework/aims-reference-review.json');$report=is_file($file)?json_decode(file_get_contents($file),true):[];
        $matches=hash_equals($report['app_key_fingerprint']??'',hash('sha256',(string)config('app.key'))) && in_array($scope,[$report['source_scope']??null,$report['rehearsal_scope']??null],true);
        if(!$matches)return ['status'=>'NOT_VERIFIED','rows'=>[],'migration_ready'=>false,'message'=>'No matching operator reference audit.'];
        $isolated=$scope===($report['rehearsal_scope']??null);$audit=$isolated?($report['current_rehearsal']??$report['rehearsal']??[]):($report['current_source']??$report['baseline']??[]);$current=collect($audit['violations']??[])->keyBy(fn($v)=>$v['table'].':'.$v['constraint']);
        $rows=[];$company=(int)Auth::user()->company_id;
        $known=collect($report['baseline']['violations']??[])->keyBy(fn($v)=>$v['table'].':'.$v['constraint']);
        foreach($current as $key=>$v)if(!$known->has($key))$known->put($key,$v);
        foreach($known as $v){
            $key=$v['table'].':'.$v['constraint'];$finding=collect($report['findings']??[])->firstWhere('key',$key)??[];$actual=$current->get($key);$records=[];$restricted=0;
            foreach(($actual??$v)['records']??[] as $r){
                $owner=$r['company_id']??null;
                if($owner===null && $v['table']==='purchase_order_items')$owner=DB::table('products')->where('id',$r['product_id']??0)->value('company_id');
                if($owner===null && $v['table']==='user_roles')$owner=DB::table('users')->where('id',$r['user_id']??0)->value('company_id');
                // Global catalog keys contain no tenant record values. Unknown identities stay in the operator report.
                if($v['table']!=='role_permissions' && (int)$owner!==$company){$restricted++;continue;}
                $visible=array_unique([...$v['primary_columns'],...$v['columns'],'company_id','product_id','supplier_id','customer_id','invoice_id','purchase_order_id','entity','entity_id','action','source','status','created_at','updated_at']);
                $records[]=array_intersect_key($r,array_flip($visible));
            }
            $rows[]=['key'=>$key,'area'=>$finding['area']??'integrity','severity'=>$finding['severity']??'attention','constraint'=>$v['constraint'],'child_table'=>$v['table'],'columns'=>$v['columns'],'parent_table'=>$v['parent'],'parent_columns'=>$v['parent_columns'],'nullable'=>$v['nullable'],'source_count'=>$v['affected_count'],'current_count'=>$actual['affected_count']??0,'status'=>$actual?($finding[$isolated?'rehearsal_status':'source_status']??'EVIDENCE_REQUIRED'):'RESOLVED','records'=>array_slice($records,0,25),'visible_record_count'=>count($records),'restricted_record_count'=>$restricted,'primary_columns'=>$v['primary_columns'],'cause'=>$finding['cause']??'The original loss is not established.','proposal'=>$finding['proposal']??'Obtain genuine parent/source evidence; do not invent a parent or erase history.','reviewer'=>$finding['reviewer']??'Database operator / data owner','business_url'=>$finding['business_url']??'/system-integrity','assigned_reviewer'=>null];
        }
        try{$fresh=\Carbon\Carbon::parse($audit['checked_at']??'1970-01-01')->gte(now()->subDay());}catch(\Throwable){$fresh=false;}
        return ['status'=>$fresh?'REVIEW_REQUIRED':'STALE_EVIDENCE','checked_at'=>$audit['checked_at']??null,'isolated'=>$isolated,'source_constraints'=>$report['baseline']['violated_constraints']??null,'rehearsal_constraints'=>$report['rehearsal']['violated_constraints']??null,'current_constraints'=>$audit['violated_constraints']??null,'constraints_checked'=>$audit['constraints_checked']??null,'rows'=>$rows,'migration_ready'=>false,'approved_exception_is_valid_relationship'=>false,'financial_opening_separate'=>true];
    }

    public function snapshot(): array
    {
        $companyId = (int) Auth::user()->company_id;
        $databaseOnline = $this->databaseOnline();
        $checks = [
            $this->databaseReliabilityCheck(),
            $this->check('database', 'Database', $databaseOnline ? 'healthy' : 'critical', $databaseOnline ? 0 : 1, '/dashboard', 'Connection is reachable. This does not verify system/grant integrity, independent recovery or reconciled opening balances.'),
            $this->countCheck('failed_jobs', 'Failed background jobs', Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0, '/activity-logs'),
            $this->countCheck('failed_webhooks', 'Failed webhook deliveries', Schema::hasTable('webhook_deliveries') ? WebhookDelivery::query()->where('status', 'failed')->count() : 0, '/control-tower'),
            $this->countCheck('integration_failures', 'Integration failures', IntegrationProvider::query()->where('enabled', true)->whereIn('health_state', ['error', 'degraded'])->count(), '/control-tower'),
            $this->countCheck('integration_operations', 'Failed integration operations', IntegrationOperationLog::query()->where('status', 'failed')->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))->count(), '/control-tower'),
            $this->countCheck('operational_exceptions', 'Open operational exceptions', OperationalException::query()->whereIn('status', ['open', 'active'])->count(), '/control-tower'),
            $this->countCheck('accounting_exceptions', 'Open accounting exceptions', Schema::hasTable('accounting_exceptions') ? AccountingException::query()->where('status', 'open')->count() : 0, '/accounting?tab=integrity'),
            $this->inventoryBalanceCheck($companyId),
            $this->backupRecencyCheck($companyId),
            $this->backupFailureCheck($companyId),
            $this->restoreDrillCheck(),
            $this->analyticsHealthCheck($companyId),
            $this->relationshipIntegrityCheck($companyId),
            $this->countCheck('outbound_quantities','Outbound quantity integrity',\App\Models\OutboundAllocation::query()->where(fn($q)=>$q->whereColumn('picked_quantity','>','quantity')->orWhereColumn('packed_quantity','>','picked_quantity')->orWhereColumn('dispatched_quantity','>','packed_quantity')->orWhereColumn('delivered_quantity','>','dispatched_quantity')->orWhereColumn('returned_quantity','>','delivered_quantity'))->count(),'/fulfillment'),
        ];
        if (Schema::hasTable('bank_statement_matches') && app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'accounting.integrity.view')) {
            foreach(app(\App\Services\Workforce\PayrollReadService::class)->closeChecks(\App\Support\CompanyClock::today()->startOfMonth()->toDateString(),\App\Support\CompanyClock::today()->toDateString()) as $payrollCheck) $checks[]=$this->countCheck($payrollCheck['code'],$payrollCheck['label'],$payrollCheck['count'],'/payroll',$payrollCheck['code']==='payroll_controls');
            foreach (app(FinancialIntegrityChecks::class)->checks() as $financialCheck) {
                $checks[] = $this->countCheck('finance_'.$financialCheck['code'], $financialCheck['label'], $financialCheck['count'], $financialCheck['url'], $financialCheck['severity']==='critical');
            }
            foreach (app(AccountingService::class)->reconciliation(false)['rows'] as $control) {
                $checks[] = $this->countCheck('finance_control_'.$control['control'], ucwords(str_replace('_', ' ', $control['control'])).' / General Ledger', $control['reconciled'] ? 0 : 1, '/accounting?tab=reconciliation', true);
            }
        }
        if (Schema::hasTable('automation_executions')) {
            $checks[]=$this->countCheck('automation_failures','Failed or blocked automations',\App\Models\AutomationExecution::whereIn('status',['failed','blocked'])->count(),'/automation-studio');
            $checks[]=$this->countCheck('automation_stuck','Stuck automation executions',\App\Models\AutomationExecution::whereIn('status',['queued','running'])->where('updated_at','<',now()->subMinutes(15))->count(),'/automation-studio');
            $invalid=0;
            $registry=app(AutomationRegistry::class);
            foreach(\App\Models\Automation::where('enabled',true)->get() as $rule) {
                $trigger=$registry->triggers()[$rule->trigger] ?? null;
                $invalidActions=collect($rule->actions)->contains(fn($a)=>!isset($registry->actions()[$a['type'] ?? '']));
                $creator=\App\Models\User::where('company_id',$rule->company_id)->find($rule->created_by);
                if(!$trigger || $invalidActions || !$creator?->is_active) $invalid++;
            }
            $checks[]=$this->countCheck('automation_configuration','Invalid automation configuration',$invalid,'/automation-studio');
        }
        if(Schema::hasTable('document_versions')) $checks[]=$this->countCheck('document_integrity','Document integrity (last verification)',\App\Models\DocumentVersion::query()->whereNotNull('integrity_status')->where('integrity_status','!=','valid')->count(),'/documents');
        if(Schema::hasTable('order_intakes')){
            $checks[]=$this->countCheck('order_intake_review','Order intake requires review',\App\Models\OrderIntake::where('state','attention')->count(),'/order-hub?view=attention');
            $checks[]=$this->countCheck('order_intake_orphan','Intakes missing their linked sales order',\App\Models\OrderIntake::whereNotNull('sales_order_id')->whereDoesntHave('order')->count(),'/order-hub');
            $checks[]=$this->countCheck('order_intake_stuck','Order intake has not progressed for a day',\App\Models\OrderIntake::where('state','received')->where('updated_at','<',now()->subDay())->count(),'/order-hub?view=attention');
        }
        if(Schema::hasTable('documents')){
            $missing=DB::table('documents as d')->where('d.company_id',$companyId)->whereNotExists(fn($q)=>$q->selectRaw('1')->from('document_versions as v')->whereColumn('v.document_id','d.id')->whereColumn('v.company_id','d.company_id')->whereColumn('v.version','d.current_version'))->count();
            $checks[]=$this->countCheck('document_current_version','Documents missing their current version',$missing,'/documents');
            $broken=DB::table('document_links')->where('company_id',$companyId)->whereNotIn('entity_type',array_keys(DocumentEntityRegistry::TYPES))->count();
            foreach(DocumentEntityRegistry::TYPES as $type=>[$model,$table]){
                if(!Schema::hasTable($table))continue;
                $broken+=DB::table('document_links as l')->where('l.company_id',$companyId)->where('l.entity_type',$type)->whereNotExists(fn($q)=>$q->selectRaw('1')->from($table.' as e')->whereColumn('e.id','l.entity_id')->whereColumn('e.company_id','l.company_id'))->count();
            }
            $checks[]=$this->countCheck('document_relationships','Broken document relationships',$broken,'/documents');
        }
        $checks[]=$this->countCheck('outbound_missing_sale','Dispatches missing their authoritative sale',\App\Models\OutboundDispatch::query()->whereNull('daily_sale_id')->count(),'/fulfillment');
        if (app()->environment('production')) {
            foreach (app(ProductionReadinessService::class)->inspect()['checks'] as $row) {
                $checks[] = $this->check('production_'.$row['key'], ucwords(str_replace('_', ' ', $row['key'])), $row['status'], $row['status'] === 'critical' ? 1 : 0, '/system-integrity', $row['detail']);
            }
        }
        $critical = collect($checks)->where('status', 'critical')->count();
        $attention = collect($checks)->where('status', 'attention')->count();

        return [
            'status' => $critical > 0 ? 'critical' : ($attention > 0 ? 'attention' : 'healthy'),
            'checked_at' => now()->toIso8601String(),
            'request_id' => request()->attributes->get('request_id'),
            'summary' => ['healthy' => collect($checks)->where('status', 'healthy')->count(), 'attention' => $attention, 'critical' => $critical],
            'checks' => $checks,
            'operational_readiness' => $this->operationalReadiness(),
            'backup_history' => Schema::hasTable('backup_runs')
                ? BackupRun::query()->where('company_id', $companyId)
                    ->orderByDesc('started_at')->orderByDesc('id')->limit(20)->get()->values()->all()
                : [],
        ];
    }

    public function operationalReadiness(): array
    {
        $connection=config('database.connections.'.config('database.default'));
        $scope=hash('sha256',($connection['host'] ?? '').':'.($connection['port'] ?? '').':'.($connection['database'] ?? ''));
        $file=storage_path('framework/aims-recovery-rehearsal.json');$evidence=is_file($file)?json_decode(file_get_contents($file),true):[];
        $matched=in_array($scope,[$evidence['source_scope'] ?? null,$evidence['target_scope'] ?? null],true) && hash_equals($evidence['app_key_fingerprint'] ?? '',hash('sha256',(string)config('app.key')));
        $recovered=$matched && ($evidence['logical_restore_verified'] ?? false) && ($evidence['schema_objects_validated'] ?? false) && ($evidence['key_compatible'] ?? false);
        $opening=\App\Models\OnboardingSession::where('status','locked')->whereNotNull('approved_at')->whereNotNull('verified_at')->latest('id')->first();
        $openingApproved=$opening && !empty($opening->verification['report']['ready_for_review']);
        $restore=app(InstallationBackupService::class)->recoveryStatus();
        $last=$restore['verified_at'];$encrypted=$restore['status']==='VERIFIED';
        $cutover=$matched && ($evidence['active_cutover_performed'] ?? false) && $scope===($evidence['target_scope'] ?? null);
        $controls=app(AccountingService::class)->reconciliation(false)['all_reconciled'];
        $runtime=DB::connection()->getDriverName()!=='mysql' || !in_array($connection['username'] ?? '',['root',''],true);
        $readiness = ['database_recovery'=>['status'=>$recovered?'VERIFIED_ISOLATED':'NOT_VERIFIED','verified_at'=>$recovered?($evidence['checked_at'] ?? null):null,'target_version'=>$recovered?($evidence['target_version'] ?? null):null,'tables'=>$recovered?($evidence['tables'] ?? null):null],
            'financial_opening'=>['status'=>$openingApproved?'APPROVED':'BLOCKED_SOURCE_REQUIRED','session_id'=>$opening?->id],
            'encrypted_installation_restore'=>['status'=>$encrypted?'VERIFIED':'NOT_VERIFIED','verified_at'=>$last],
            'live_company_operation'=>['status'=>$recovered && $openingApproved && $controls && $runtime && $encrypted && $cutover?'READY':'BLOCKED','active_cutover_performed'=>$cutover]];
        $readiness['commissioning']=$this->commissioning($readiness,$evidence,$matched,$scope,$controls,$openingApproved,$restore);
        $readiness['live_company_operation']['status']=$readiness['commissioning']['ready_for_pilot']?'READY':'BLOCKED';
        return $readiness;
    }

    /** Commissioning is a projection of existing recovery, pilot and accounting evidence, never an activation action. */
    private function commissioning(array $readiness,array $evidence,bool $matched,string $scope,bool $controls,bool $openingApproved,array $restore): array
    {
        $checks=[];
        $add=function($key,$status,$owner,$url,$problem,$required)use(&$checks){$checks[] = compact('key','status','owner','url','problem','required');};
        $target=$matched && $scope===($evidence['target_scope'] ?? '') && ($evidence['logical_restore_verified'] ?? false);
        $dbProof=$matched && ($evidence['runtime_scope'] ?? '')===$scope && ($evidence['runtime_privileges_verified'] ?? false);
        $connection=config('database.connections.'.config('database.default'));
        $least=false;$version=null;
        try {
            $version=DB::getDriverName()==='mysql'?DB::selectOne('SELECT VERSION() v')->v:DB::selectOne('SELECT sqlite_version() v')->v;
            if(DB::getDriverName()==='mysql' && $dbProof){
                $grants=array_map(fn($row)=>preg_replace("/ IDENTIFIED BY PASSWORD '[^']*'/",'',array_values((array)$row)[0]),DB::select('SHOW GRANTS'));
                sort($grants);$least=!in_array($connection['username'] ?? '',['root',''],true) && hash_equals($evidence['runtime_grants_sha256'] ?? '',hash('sha256',json_encode($grants)));
            }
        } catch (\Throwable) { /* Unknown is not a pass. */ }
        $runtime=$version && $target && $least && ($evidence['relational_integrity_verified'] ?? false);
        $referenceNote=$matched && isset($evidence['source_fk_violations'])?' Recorded source audit: '.(int)$evidence['source_fk_violations'].' foreign-key constraints have unresolved references.':'';
        $add('database_runtime',$runtime?'VERIFIED_ISOLATED':'BLOCKED','Database operator','/system-integrity?view=recovery','A reachable connection does not prove administration integrity, valid legacy references or an active migration.'.$referenceNote,'Use the DC10 target, investigate orphan/cross-company references, compare the final snapshot and obtain explicit cutover approval. Preserve the original instance.');
        $add('least_privilege',$least?'VERIFIED':'NOT_VERIFIED','Database operator','/system-integrity?view=recovery','The active runtime must have independently reviewed effective privileges; a username alone is insufficient.','Verify schema-only runtime grants and forbidden access on the actual connection; use separate migration and backup identities.');
        try {$key=app(\App\Services\Commercial\InstallationIdentity::class)->verify();}catch(\Throwable){$key=false;}
        $add('application_key',$key?'VERIFIED':'BLOCKED','Installation operator','/system-health','The original key must unlock this installation identity.','Recover the separately escrowed original APP_KEY. Do not generate a replacement key.');
        $add('encrypted_recovery',$restore['status'],'Installation operator','/system-integrity?view=recovery','SQL restoration, archive verification and complete encrypted installation recovery are different controls.','Complete the existing DC8 restore into an empty isolated installation, including files, key and business reconciliation.');
        $add('opening_reconciliation',!$openingApproved?'BLOCKED_SOURCE_REQUIRED':($controls?'VERIFIED':'BLOCKED'),'Accountant','/accounting?tab=reconciliation','Recorded operational balances are not approved opening statements; a zero journal balance proves nothing.','Obtain independent cutoff statements, match source records and reconcile all six controls through the existing accounting authority.');
        $approval=$openingApproved && app(PilotService::class)->confirmed('opening_finance');
        $add('accountant_approval',$approval?'VERIFIED':'PENDING','Independent accountant','/pilot-evidence','Review notes and an opening date are not approval.','Complete source review, explicit approval, authoritative posting and verification/lock; record independently reviewed opening-finance evidence in the pilot workspace.');
        $missing=[];
        foreach(['default','supply-optimizer','strategic-simulation','scheduler'] as $name){
            $file=storage_path('framework/aims-heartbeat-'.$name.'.json');$h=is_file($file)?json_decode(file_get_contents($file),true):[];
            if(($h['version'] ?? '')!==\App\Support\Release::version() || ($h['at'] ?? 0)<time()-config('production.worker_max_age'))$missing[]=$name;
        }
        $workers=!$missing && app(PilotService::class)->confirmed('server_drill');
        $add('workers_scheduler',$workers?'VERIFIED':($missing?'NOT_VERIFIED':'REVIEW_REQUIRED'),'Installation operator','/system-health','Worker initialization or an empty queue is not supervised operation. Missing/stale: '.implode(', ',$missing).'.','Commission the existing supervisors and scheduler, observe release heartbeats, test outage/recovery and record a reviewed server drill.');
        $review=app(PilotService::class)->commissioningScope();
        $security=$review['runtime_security'] && !collect(app(ProductionReadinessService::class)->configuration())->contains('status','critical');
        $add('runtime_security',$security?'SCOPE_REVIEWED':'BLOCKED','Release/security operator','/system-health','DC8 Electron/build advisories, release signing and target-host commissioning remain commercial blockers until reverified.','Resolve and re-audit dependencies, verify signed runtime/update artifacts, HTTPS/passkey origin, recovery escrow and host configuration. A documented web-only pilot scope cannot certify desktop distribution.');
        $fiscal=\App\Models\FiscalConfiguration::first();
        $add('fiscalization',$review['fiscalization']?'SCOPE_REVIEWED':'REVIEW_REQUIRED','Fiscal operator / accountant','/fiscalization','Current fiscalization mode: '.($fiscal?->environment ?? 'not configured').'. Test-mode output is not production authorization.','Record Fiscalization scope in independently reviewed commissioning-scope evidence, with provider authorization or a documented pilot exclusion. Unknown outcomes require verification before retry.');
        $add('payroll',$review['payroll']?'SCOPE_REVIEWED':'REVIEW_REQUIRED','Payroll accountant / HR','/payroll','Payroll configuration, liability reconciliation and payment approval require a scoped human review.','Record Payroll scope in independently reviewed commissioning-scope evidence. Review contracts, rates, approvals and payroll controls, or explicitly document exclusion. Do not post or pay during commissioning.');
        $profile=\App\Models\PilotProfile::first();$source=app(PilotService::class)->source();
        $authorized=$profile?->status==='active' && $source['real_data_attested'] && (int)config('pilot.company_id')===(int)Auth::user()->company_id && app()->environment('production');
        $add('pilot_authorization',$authorized?'AUTHORIZED':'PENDING','Company owner + installation operator','/pilot','No database drill or system check authorizes real company operation.','Complete the existing pilot plan, named participants, scope, source attestation and activation review. Real operations remain pending.');
        $prerequisites=$runtime && $least && $key && $restore['status']==='VERIFIED' && $controls && $approval && $workers && $security && $readiness['live_company_operation']['active_cutover_performed'];
        // Fiscal/payroll and security scope decisions still require reviewed pilot evidence, not configuration inference.
        $scopeReviewed=$review['fiscalization'] && $review['payroll'] && app(PilotService::class)->confirmed('permission_review');
        $setup=[];foreach(['users'=>'/users','warehouses'=>'/warehouses','products'=>'/products','customers'=>'/customer-debts','suppliers'=>'/suppliers','financial_accounts'=>'/money-accounts'] as $table=>$url)$setup[]=['key'=>$table,'count'=>DB::table($table)->where('company_id',Auth::user()->company_id)->count(),'url'=>$url];
        return ['checked_at'=>now()->toIso8601String(),'status'=>$prerequisites && $scopeReviewed && $authorized?'READY':'BLOCKED','ready_for_pilot'=>(bool)($prerequisites && $scopeReviewed && $authorized),'prerequisites_ready'=>(bool)($prerequisites && $scopeReviewed),'checks'=>$checks,'recovery'=>$restore,'current_database_version'=>$version,'source_real_attested'=>$source['real_data_attested'],'setup'=>$setup,'commercial_ready'=>false];
    }

    private function databaseReliabilityCheck(): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') return $this->check('database_identity', 'Database administration', 'healthy', 0, '/system-integrity?view=recovery', 'SQLite installation: server grant tables do not apply. Backup and opening reconciliation are checked separately.');
        $connection=config('database.connections.'.config('database.default'));
        $scope=hash('sha256',($connection['host'] ?? '').':'.($connection['port'] ?? '').':'.($connection['database'] ?? ''));
        $file=storage_path('framework/aims-database-health.json');
        $evidence=is_file($file)?json_decode(file_get_contents($file),true):[];
        $knownFailure=($evidence['scope'] ?? '')===$scope && ($evidence['status'] ?? '')==='requires_administration';
        $root=in_array($connection['username'] ?? '', ['root',''], true);
        return $this->check('database_identity', 'Database application identity', $knownFailure || $root ? 'critical':'attention', 1, '/system-integrity?view=recovery', $knownFailure ? 'Grant-table administration failed in the last recorded check. Preserve backups and use a clean isolated database for recovery; live grant integrity is not certified.' : ($root ? 'The application uses a database administrator account. A verified least-privilege runtime account is required.' : 'A dedicated account is configured. Verify its effective grants and independent recovery separately.')) + ['last_observation_at'=>$knownFailure?($evidence['checked_at'] ?? null):null];
    }

    private function backupRecencyCheck(int $companyId): array
    {
        if (! Schema::hasTable('backup_runs')) {
            return $this->check('backup_recency', 'Recent verified backup', 'attention', 1, '/system-integrity?view=recovery', 'Backup history is not initialized.');
        }
        $recent = BackupRun::query()->where('company_id', $companyId)->where('status', 'completed')->where('operation', 'export')->where('backup_type', 'full')
            ->where('verification_result', 'checksum_verified')
            ->where('completed_at', '>=', now()->subDays(30))->exists();

        return $this->check('backup_recency', 'Recent verified full backup', $recent ? 'healthy' : 'attention', $recent ? 0 : 1, '/system-integrity?view=recovery', $recent ? 'A full backup was reopened and verified within the last 30 days.' : 'No reopened and verified full backup completed within the last 30 days.');
    }

    private function restoreDrillCheck(): array
    {
        $restore=app(InstallationBackupService::class)->recoveryStatus();$last=$restore['verified_at'];$fresh=$restore['status']==='VERIFIED';$health=[];
        return $this->check('restore_drill', 'Independent installation restore', $fresh ? 'healthy' : 'attention', $fresh ? 0 : 1, '/system-integrity?view=recovery', $fresh ? 'This installation passed restoration, attachment verification and inventory/finance reconciliation.' : 'No successful independent installation restore recorded within 30 days. Test an encrypted backup on an empty Installation B.')
            + ['last_success_at' => $last, 'last_verified_backup_at' => $health['last_verified_at'] ?? null, 'last_backup_failure_at' => $health['last_failure_at'] ?? null];
    }

    private function analyticsHealthCheck(int $companyId): array
    {
        $run = Schema::hasTable('maintenance_health')
            ? DB::table('maintenance_health')->where('company_id', $companyId)->where('task', 'analytics_capture')->first() : null;
        $lastObservation = Schema::hasTable('analytics_snapshots')
            ? DB::table('analytics_snapshots')->where('company_id', $companyId)->where('entity_type', 'backlog')->max('observed_at') : null;
        $fresh = $lastObservation && \Carbon\Carbon::parse($lastObservation)->gte(now()->subHours(36));
        $failed = $run?->status === 'failed';
        $detail = $failed ? 'The latest scheduled analytics capture failed. Review permissions and the backend log.'
            : ($fresh ? 'Analytics observations are current.' : 'No completed analytics observation in the last 36 hours. Check the scheduler.');
        return $this->check('analytics_capture', 'Analytics capture health', $failed || !$fresh ? 'attention' : 'healthy', $failed || !$fresh ? 1 : 0, '/analytics', $detail)
            + ['last_success_at' => $run?->last_success_at, 'last_attempt_at' => $run?->last_attempt_at, 'last_observation_at' => $lastObservation, 'error_code' => $run?->error_code];
    }

    private function backupFailureCheck(int $companyId): array
    {
        if (! Schema::hasTable('backup_runs')) {
            return $this->countCheck('backup_failures', 'Recent backup failures', 0, '/system-integrity?view=recovery');
        }
        $failures = BackupRun::query()->where('company_id', $companyId)->where('status', 'failed')
            ->where('started_at', '>=', now()->subDays(30))->count();

        return $this->countCheck('backup_failures', 'Recent backup failures', $failures, '/system-integrity?view=recovery', $failures >= 3);
    }

    private function relationshipIntegrityCheck(int $companyId): array
    {
        $issues = 0;
        $relations = [
            ['goods_receipts', 'purchase_order_id', 'purchase_orders'],
            ['quality_inspections', 'goods_receipt_id', 'goods_receipts'],
            ['supplier_claims', 'goods_receipt_id', 'goods_receipts'],
            ['shipment_containers', 'shipment_id', 'shipments'],
            ['landed_cost_allocations', 'landed_cost_id', 'landed_costs'],
            ['landed_cost_allocations', 'goods_receipt_item_id', 'goods_receipt_items'],
            ['landed_cost_allocations', 'product_id', 'products'],
        ];
        foreach ($relations as [$child, $foreignKey, $parent]) {
            if (! Schema::hasTable($child) || ! Schema::hasTable($parent) || ! Schema::hasColumn($child, $foreignKey)) {
                continue;
            }
            $issues += DB::table($child.' as child')->leftJoin($parent.' as parent', 'parent.id', '=', 'child.'.$foreignKey)
                ->where('child.company_id', $companyId)->whereNotNull('child.'.$foreignKey)->whereNull('parent.id')->count();
        }

        return $this->countCheck('relationship_integrity', 'Critical relationship integrity', $issues, '/system-integrity', true);
    }

    private function inventoryBalanceCheck(int $companyId): array
    {
        if (! Schema::hasTable('warehouse_stock')) {
            return $this->check('inventory_reconciliation', 'Inventory reconciliation', 'attention', 1, '/operations-center', 'Warehouse balances are not initialized.');
        }
        $mismatches = DB::table('warehouse_stock')->where('company_id', $companyId)
            ->where(fn ($q) => $q->whereRaw('ABS(quantity - (available_quantity + reserved_quantity + damaged_quantity + quarantine_quantity + blocked_quantity)) >= 0.0005')
                ->orWhereRaw('quantity < 0 OR available_quantity < 0 OR reserved_quantity < 0 OR damaged_quantity < 0 OR quarantine_quantity < 0 OR blocked_quantity < 0'))
            ->count();
        $mismatches += DB::table('warehouse_stock as b')
            ->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')->leftJoin('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('warehouse_locations as l', 'l.id', '=', 'b.location_id')->where('b.company_id', $companyId)
            ->where(fn ($q) => $q->whereNull('w.id')->orWhereNull('p.id')->orWhereColumn('w.company_id', '!=', 'b.company_id')->orWhereColumn('p.company_id', '!=', 'b.company_id')
                ->orWhere(fn ($q) => $q->whereNotNull('b.location_id')->where(fn ($q) => $q->whereNull('l.id')->orWhereColumn('l.warehouse_id', '!=', 'b.warehouse_id')->orWhereColumn('l.company_id', '!=', 'b.company_id'))))->count();

        return $this->countCheck('inventory_reconciliation', 'Inventory state mismatches', $mismatches, '/operations-center', true);
    }

    private function countCheck(string $key, string $label, int $count, string $url, bool $critical = false): array
    {
        return $this->check($key, $label, $count > 0 ? ($critical ? 'critical' : 'attention') : 'healthy', $count, $url, $count > 0 ? 'Review the affected records; AIMS will not silently change business data.' : 'No unresolved issues detected.');
    }

    private function check(string $key, string $label, string $status, int $count, string $url, string $detail): array
    {
        return compact('key', 'label', 'status', 'count', 'url', 'detail');
    }

    private function databaseOnline(): bool
    {
        try { DB::select('select 1'); return true; } catch (\Throwable) { return false; }
    }
}
