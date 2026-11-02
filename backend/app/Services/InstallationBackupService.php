<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\Release;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/** Installation disaster recovery, separate from the selective company export.
 * Bounded encrypted archive; restore ONLY into a separate empty installation.
 * APP_KEY and all host credentials are escrowed outside the archive. */
final class InstallationBackupService
{
    private const LIMIT = 536870912; // 512 MiB raw database + attachment bytes.
    private const AAD = 'AIMS-INSTALLATION-BACKUP-1';

    public function create(string $passphrase, bool $scheduled = false): array
    {
        $this->requireMaintenance();
        if (Schema::hasTable('installation_state') && !app(\App\Services\Commercial\InstallationIdentity::class)->verify()) throw new \RuntimeException('The original application encryption key is required before certifying an installation backup.');
        $root = config('production.backup_root');
        if (is_link($root)) throw new \RuntimeException('Backup directory must not be a symbolic link.');
        if (! is_dir($root) && ! mkdir($root, 0700, true)) throw new \RuntimeException('Backup directory cannot be created.');
        $lock = fopen(storage_path('framework/aims-installation-backup.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Another installation backup is running. Retry after it completes.');
        $temporary = tempnam($root, '.snapshot-');
        $stages = ['application_key' => 'passed'];
        try {
            $engine = DB::connection()->getDriverName();
            if ($engine === 'sqlite') {
                unlink($temporary);
                DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($temporary));
                $copy = new \PDO('sqlite:'.$temporary);
                if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $copy->query('PRAGMA foreign_key_check')->fetch()) throw new \RuntimeException('SQLite backup integrity validation failed.');
                $copy = null;
            } elseif ($engine === 'mysql') {
                // single-transaction is consistent only for transactional tables.
                foreach (DB::select('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']) as $table) if (strtolower($table->ENGINE) !== 'innodb') throw new \RuntimeException('Backup requires all business tables to use InnoDB.');
                $this->databaseProcess(config('production.mysqldump'), ['--single-transaction', '--quick', '--hex-blob', '--skip-lock-tables', '--routines', '--events', '--triggers', '--result-file='.$temporary])->mustRun();
            } else throw new \RuntimeException('Unsupported database engine.');
            $stages['database_export'] = 'passed';
            $size = filesize($temporary);
            if ($size === false || $size > self::LIMIT || disk_free_space($root) < $size * 5 + config('production.min_free_bytes')) throw new \RuntimeException('Insufficient disk reserve or installation exceeds the 512 MiB encrypted archive limit. Use the documented large-installation backup procedure.');
            $files = [];
            foreach ($this->roots() as $label => $directory) {
                if (! is_dir($directory)) continue;
                if (is_link($directory)) throw new \RuntimeException('Attachment roots must not be symbolic links.');
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isLink()) throw new \RuntimeException('Attachment backup refuses symbolic links.');
                    if (! $file->isFile() || $file->getFilename() === '.gitignore') continue;
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($directory, '/\\')) + 1));
                    $this->safePath($label.'/'.$relative);
                    $size += $file->getSize();
                    if ($size > self::LIMIT) throw new \RuntimeException('Installation exceeds the 512 MiB archive limit; no attachments were omitted.');
                    $bytes = file_get_contents($file->getPathname());
                    if ($bytes === false) throw new \RuntimeException('An attachment cannot be read.');
                    $files[$label.'/'.$relative] = ['bytes' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
                }
            }
            $database = file_get_contents($temporary);
            $stages['files_snapshot'] = 'passed';
            $reconciliation = $this->reconcile();
            $stages['business_reconciliation'] = $reconciliation['healthy'] ? 'passed' : 'blocked';
            if (! $reconciliation['healthy']) throw new \RuntimeException('Installation integrity/reconciliation failed. Resolve inventory, finance, tenant or document issues before certifying a backup.');
            $payload = ['format' => 'aims-installation', 'version' => 1, 'app_version' => Release::version(), 'created_at' => now()->toIso8601String(), 'engine' => $engine, 'companies' => DB::table('companies')->orderBy('id')->get(['id', 'name'])->toArray(), 'app_key_fingerprint' => hash('sha256', (string) config('app.key')), 'database' => ['encoding' => 'gzip', 'bytes' => base64_encode(gzencode($database, 6)), 'sha256' => hash('sha256', $database)], 'files' => $files, 'reconciliation' => $reconciliation];
            unset($database);
            $archive = $this->encrypt(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $passphrase);
            $name = ($scheduled ? 'scheduled-' : 'manual-').gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.aimsinstall';
            $target = $root.DIRECTORY_SEPARATOR.$name;
            $stage = $target.'.partial';
            try {
                if (file_put_contents($stage, $archive, LOCK_EX) !== strlen($archive)) throw new \RuntimeException('Encrypted backup write failed. Check free disk space.');
                chmod($stage, 0600);
                // Reopen, authenticate, parse and verify every byte from disk.
                $verified = $this->verify($stage, $passphrase);
                if (! rename($stage, $target)) throw new \RuntimeException('Verified backup cannot be finalized.');
            } finally { if (is_file($stage)) unlink($stage); }
            $result = ['file' => $name, 'created_at' => $payload['created_at'], 'version' => Release::version(), 'engine' => $engine, 'verification' => 'authenticated_and_checksums_verified', 'sha256' => hash_file('sha256', $target), 'companies' => count($payload['companies'])];
            $metadata=json_encode($result, JSON_PRETTY_PRINT);
            if(file_put_contents($target.'.verified.json', $metadata, LOCK_EX)!==strlen($metadata))throw new \RuntimeException('Verified backup metadata could not be written. Free disk space and verify the archive again; no successful backup is recorded.');
            $stages['archive_integrity'] = $stages['archive_decryption'] = 'passed';
            $this->health(['last_success_at' => $result['created_at'], 'last_verified_at' => $result['created_at'], 'last_failure_at' => null, 'last_create_review' => $this->stageEvidence($stages)]);
            if ($scheduled) $this->retain($root);
            return $result;
        } catch (\Throwable $error) {
            $this->health(['last_failure_at' => now()->toIso8601String(), 'error_code' => 'installation_backup_failed', 'last_create_review' => $this->stageEvidence($stages)]);
            throw $error;
        } finally {
            if (is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function verify(string $file, string $passphrase): array
    {
        if (! is_file($file) || filesize($file) > self::LIMIT * 3) throw new \RuntimeException('Backup is missing or exceeds the safe size limit.');
        try {
            $envelope = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (($envelope['format'] ?? '') !== 'aims-installation-encrypted' || ($envelope['version'] ?? 0) !== 1) throw new \RuntimeException();
            $salt = base64_decode($envelope['salt'] ?? '', true); $iv = base64_decode($envelope['iv'] ?? '', true); $tag = base64_decode($envelope['tag'] ?? '', true); $cipher = base64_decode($envelope['ciphertext'] ?? '', true);
            if (strlen($salt ?: '') !== 16 || strlen($iv ?: '') !== 12 || strlen($tag ?: '') !== 16 || $cipher === false) throw new \RuntimeException();
            $key = hash_pbkdf2('sha256', $passphrase, $salt, 310000, 32, true);
            $json = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
            if ($json === false) throw new \RuntimeException();
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (($payload['format'] ?? '') !== 'aims-installation' || ($payload['version'] ?? 0) !== 1 || ! is_array($payload['files'] ?? null) || ! is_array($payload['reconciliation'] ?? null)) throw new \RuntimeException();
            Release::assertBackupCompatible($payload['app_version'] ?? '');
            $size = 0;
            foreach (['database' => $payload['database']] + $payload['files'] as $path => $entry) {
                if ($path !== 'database') $this->safePath($path);
                $bytes = base64_decode($entry['bytes'] ?? '', true);
                if (($entry['encoding'] ?? null) === 'gzip') $bytes = $bytes === false ? false : gzdecode($bytes, self::LIMIT + 1);
                if ($bytes === false || ! hash_equals($entry['sha256'] ?? '', hash('sha256', $bytes))) throw new \RuntimeException();
                $size += strlen($bytes);
                if ($size > self::LIMIT) throw new \RuntimeException();
            }
            return $payload;
        } catch (\Throwable) { throw new \RuntimeException('Backup verification failed: wrong passphrase, damaged archive or incompatible AIMS release. No data was restored.'); }
    }

    public function restore(string $file, string $passphrase): array
    {
        $this->requireMaintenance();
        $stages = [];
        try {
        $payload = $this->verify($file, $passphrase);
        $stages['archive_integrity'] = $stages['archive_decryption'] = 'passed';
        if (! hash_equals($payload['app_key_fingerprint'], hash('sha256', (string) config('app.key')))) throw new \RuntimeException('Provide the separately escrowed original APP_KEY in this empty installation before restore. The key is never stored in the backup.');
        $engine = DB::connection()->getDriverName();
        if ($payload['engine'] !== $engine) throw new \RuntimeException('Installation restore requires the same database engine. Use selective portable exports for company transfers.');
        if (Schema::getTables(Schema::getCurrentSchemaListing()) !== []) throw new \RuntimeException('Restore requires a separate empty installation database. The current database was not changed.');
        foreach ($this->roots() as $root) if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $entry) if ($entry->isLink() || ($entry->isFile() && $entry->getFilename() !== '.gitignore')) throw new \RuntimeException('Restore requires empty attachment directories on Installation B.');
        }
        $marker = storage_path('framework/aims-restore-incomplete');
        if (file_put_contents($marker, 'Restore validation pending.') === false) throw new \RuntimeException('Restore state directory is not writable.');
        $database = base64_decode($payload['database']['bytes'], true);
        if (($payload['database']['encoding'] ?? null) === 'gzip') $database = gzdecode($database, self::LIMIT + 1);
        if ($engine === 'sqlite') {
            $target = config('database.connections.sqlite.database');
            if ($target === ':memory:' || is_link($target)) throw new \RuntimeException('Restore requires a real empty SQLite file.');
            $stage = $target.'.restore-'.bin2hex(random_bytes(8));
            file_put_contents($stage, $database, LOCK_EX);
            try {
                $copy = new \PDO('sqlite:'.$stage);
                if ($copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $copy->query('PRAGMA foreign_key_check')->fetch()) throw new \RuntimeException('Restored SQLite database failed integrity validation.');
                $copy = null; DB::purge();
                if (is_file($target)) unlink($target);
                if (! rename($stage, $target)) throw new \RuntimeException('Restored database cannot be activated.');
            } finally { if (is_file($stage)) unlink($stage); }
        } else {
            $process = $this->databaseProcess(config('production.mysql'), ['--binary-mode=1'], true);
            $process->setInput($database); $process->mustRun(); DB::purge();
        }
        // Restore is intentionally offline. A failure leaves the marker in place
        // and never presents partial SQL/file restoration as an operable system.
        $this->migrateRestoredDatabase();
        $stages['database_restoration'] = 'passed';
        if (Schema::hasTable('installation_state') && !app(\App\Services\Commercial\InstallationIdentity::class)->verify()) throw new \RuntimeException('Restored installation key evidence does not match.');
        $stages['application_key_restoration'] = 'passed';
        foreach ($payload['files'] as $path => $entry) {
            [$label, $relative] = explode('/', $path, 2);
            $target = $this->roots()[$label].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            $bytes = base64_decode($entry['bytes'], true);
            if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes) || ! hash_equals($entry['sha256'], hash_file('sha256', $target))) throw new \RuntimeException('Attachment restore failed. Keep this installation offline and retry into a clean target.');
        }
        $stages['files_preservation'] = 'passed';
        $actual = $this->reconcile();
        $stages['business_reconciliation'] = $actual['healthy'] && $actual['totals'] === $payload['reconciliation']['totals'] ? 'passed' : 'blocked';
        if (! $actual['healthy'] || $actual['totals'] !== $payload['reconciliation']['totals']) throw new \RuntimeException('Post-restore critical totals or reconciliation failed. This installation remains blocked.');
        // No pre-loss login tokens, queued jobs or external delivery retries may
        // become live merely because a recovery database has been activated.
        DB::transaction(function () {
            foreach (['auth_challenges','auth_sessions','refresh_tokens', 'password_reset_tokens', 'sessions', 'jobs', 'job_batches'] as $table) if (Schema::hasTable($table)) DB::table($table)->delete();
            DB::table('users')->update(['api_token' => null, 'remember_token' => null]);
            DB::table('users')->increment('token_version');
            if (Schema::hasTable('automations')) DB::table('automations')->update(['enabled' => false]);
            if (Schema::hasTable('integration_providers')) DB::table('integration_providers')->update(['enabled' => false]);
            if (Schema::hasTable('integration_operation_logs')) DB::table('integration_operation_logs')->whereNotNull('next_retry_at')->update(['next_retry_at' => null]);
            if (Schema::hasTable('webhook_endpoints')) DB::table('webhook_endpoints')->update(['enabled' => false]);
            if (Schema::hasTable('webhook_deliveries')) DB::table('webhook_deliveries')->whereNotNull('next_retry_at')->update(['next_retry_at' => null]);
            if (Schema::hasTable('service_accounts')) DB::table('service_accounts')->update(['status'=>'revoked']);
            if (Schema::hasColumn('companies','platform_settings')) DB::table('companies')->update(['platform_settings'=>json_encode(['api_enabled'=>false,'mcp_enabled'=>false,'observability_enabled'=>false])]);
            if (Schema::hasTable('order_channels')) DB::table('order_channels')->update(['enabled' => false]);
            if (Schema::hasTable('order_channel_keys')) DB::table('order_channel_keys')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            if (Schema::hasTable('pilot_profiles')) DB::table('pilot_profiles')->where('status','active')->update(['status'=>'paused']);
            foreach (['pilot_evidence','pilot_reports'] as $table) if (Schema::hasTable($table)) DB::table($table)->update(['is_historical'=>true]);
        });
        unlink($marker);
        $proof = $this->stageEvidence($stages);
        $proof['archive_sha256'] = hash_file('sha256', $file);
        $this->health(['last_restore_verified_at' => now()->toIso8601String(), 'last_verified_at' => now()->toIso8601String(), 'restore_verification' => $proof, 'last_restore_review' => $proof]);
        return ['status' => 'restored_and_reconciled', 'version' => Release::version(), 'inventory' => true, 'finance' => true, 'attachments' => count($payload['files']), 'tenant_isolation' => true, 'totals' => $actual['totals']];
        } catch (\Throwable $error) {
            $this->health(['last_failure_at' => now()->toIso8601String(), 'error_code' => 'installation_restore_failed', 'last_restore_review' => $this->stageEvidence($stages)]);
            throw $error;
        }
    }

    private function stageEvidence(array $stages): array
    {
        $c = config('database.connections.'.config('database.default'));
        return ['scope' => hash('sha256', DB::getDriverName().':'.($c['host'] ?? '').':'.($c['port'] ?? '').':'.($c['database'] ?? '')),
            'key_fingerprint' => hash('sha256', (string) config('app.key')), 'at' => now()->toIso8601String(), 'stages' => $stages];
    }

    /** Local proof is bound to this database and key; a fixture receipt cannot certify another installation. */
    public function recoveryStatus(): array
    {
        $file = storage_path('framework/aims-backup-health.json');
        $health = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        $scope = $this->stageEvidence([]);
        $matches = fn ($p) => is_array($p) && ($p['scope'] ?? '') === $scope['scope'] && hash_equals($p['key_fingerprint'] ?? '', $scope['key_fingerprint']);
        $proof = $health['restore_verification'] ?? [];
        $names = ['archive_integrity', 'archive_decryption', 'database_restoration', 'application_key_restoration', 'files_preservation', 'business_reconciliation'];
        $verified = $matches($proof) && !is_file(storage_path('framework/aims-restore-incomplete'));
        try { $verified = $verified && \Carbon\Carbon::parse($proof['at'] ?? '1970-01-01')->gte(now()->subDays(30)); } catch (\Throwable) { $verified = false; }
        foreach ($names as $name) $verified = $verified && ($proof['stages'][$name] ?? '') === 'passed';
        $attempt = $matches($health['last_restore_review'] ?? null) ? $health['last_restore_review'] : [];
        $create = $matches($health['last_create_review'] ?? null) ? $health['last_create_review'] : [];
        $safe = fn ($p) => array_intersect_key($p, array_flip(['at', 'stages']));
        return ['status' => $verified ? 'VERIFIED' : 'NOT_VERIFIED', 'verified_at' => $verified ? $proof['at'] : null,
            'restore_stages' => array_replace(array_fill_keys($names, 'not_tested'), array_intersect_key(($verified ? $proof : $attempt)['stages'] ?? [], array_flip($names))), 'last_restore_attempt' => $safe($attempt), 'last_backup_attempt' => $safe($create),
            'incomplete_restore' => is_file(storage_path('framework/aims-restore-incomplete')), 'verified_stages' => $verified ? $proof['stages'] : []];
    }

    public function reconcile(): array
    {
        $prior = Auth::user(); $healthy = true; $totals = [];
        $checks = array_fill_keys(['company_actor', 'inventory', 'finance', 'documents', 'payroll', 'tenant_links', 'relational_links'], true);
        try {
            foreach (Company::orderBy('id')->get() as $company) {
                $actor = User::withoutGlobalScopes()->where('company_id', $company->id)->where('role', 'admin')->where('is_active', true)->first();
                if (! $actor) { if (DB::table('products')->where('company_id', $company->id)->exists()) {$healthy = false;$checks['company_actor']=false;} continue; }
                Auth::setUser($actor);
                foreach (Product::query()->get() as $product) if (app(InventoryIntegrityService::class)->productSnapshot($product)['issues']) {$healthy = false;$checks['inventory']=false;}
                $finance = app(AccountingService::class)->reconciliation(false);
                if (! $finance['all_reconciled']) {$healthy = false;$checks['finance']=false;}
                if (app(DocumentMaintenanceService::class)->verifyCompany($company->id)) {$healthy = false;$checks['documents']=false;}
                $totals[(string) $company->id] = ['inventory_quantity' => number_format((float) DB::table('products')->where('company_id', $company->id)->sum('quantity'), 3, '.', ''), 'finance' => array_map(fn ($row) => array_intersect_key($row, array_flip(['operational_amount', 'gl_amount', 'difference', 'reconciled'])), $finance['rows']), 'users' => DB::table('users')->where('company_id', $company->id)->count(), 'documents' => DB::table('documents')->where('company_id', $company->id)->count()];
                if(Schema::hasTable('employees') && \App\Models\Employee::query()->exists()) {
                    $payroll=app(\App\Services\Workforce\PayrollReadService::class)->reconcile('9999-12-31');
                    if(!$payroll['reconciled']) {$healthy=false;$checks['payroll']=false;}
                    $totals[(string)$company->id]['workforce']=['employees'=>\App\Models\Employee::count(),'runs'=>\App\Models\PayrollRun::count(),'payroll_lines'=>\App\Models\PayrollLine::count(),'advances'=>\App\Models\EmployeeAdvance::count(),'payments'=>\App\Models\PayrollPayment::count(),'controls'=>$payroll['rows']];
                }
            }
            if (! $this->tenantLinksAreValid()) {$healthy = false;$checks['tenant_links']=false;}
            if (! $this->databaseReferencesAreValid()) {$healthy = false;$checks['relational_links']=false;}
        } finally { if ($prior) Auth::setUser($prior); else Auth::forgetUser(); }
        return compact('healthy', 'totals', 'checks');
    }

    /** The DC11 declared-relationship check, with bounded operator evidence when requested. */
    public function referenceAudit(bool $withRecords = false, bool $stopOnFailure = false): array
    {
        return DB::transaction(fn() => $this->collectReferenceAudit($withRecords,$stopOnFailure));
    }

    private function collectReferenceAudit(bool $withRecords, bool $stopOnFailure): array
    {
        $relations=[];$owners=[];$columns=[];$keys=[];
        if(DB::getDriverName()==='mysql') {
            $database=DB::connection()->getDatabaseName();
            foreach(DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA',$database)->get(['TABLE_NAME','COLUMN_NAME','IS_NULLABLE']) as $c)$columns[$c->TABLE_NAME][$c->COLUMN_NAME]=$c->IS_NULLABLE==='YES';
            foreach(DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA',$database)->where('INDEX_NAME','PRIMARY')->orderBy('SEQ_IN_INDEX')->get(['TABLE_NAME','COLUMN_NAME']) as $k)$keys[$k->TABLE_NAME][]=$k->COLUMN_NAME;
            $rules=DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA',$database)->get()->keyBy(fn($r)=>$r->TABLE_NAME.':'.$r->CONSTRAINT_NAME);
            $groups=DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA',$database)->whereNotNull('REFERENCED_TABLE_NAME')->orderBy('ORDINAL_POSITION')->get()->groupBy(fn($r)=>$r->TABLE_NAME.':'.$r->CONSTRAINT_NAME);
            foreach($groups as $name=>$group){$f=$group->first();$rule=$rules->get($name);$relations[]=['table'=>$f->TABLE_NAME,'constraint'=>$f->CONSTRAINT_NAME,'columns'=>$group->pluck('COLUMN_NAME')->all(),'parent'=>$f->REFERENCED_TABLE_NAME,'parent_columns'=>$group->pluck('REFERENCED_COLUMN_NAME')->all(),'same_database'=>$f->REFERENCED_TABLE_SCHEMA===$database,'on_delete'=>$rule?->DELETE_RULE,'on_update'=>$rule?->UPDATE_RULE];}
        } else {
            foreach(Schema::getTables(Schema::getCurrentSchemaListing()) as $table){$name=$table['name'];foreach(Schema::getColumns($name) as $c)$columns[$name][$c['name']]=$c['nullable'];foreach(Schema::getIndexes($name) as $i)if($i['primary'])$keys[$name]=$i['columns'];foreach(Schema::getForeignKeys($name) as $fk)$relations[]=['table'=>$name,'constraint'=>$fk['name'],'columns'=>$fk['columns'],'parent'=>$fk['foreign_table'],'parent_columns'=>$fk['foreign_columns'],'same_database'=>true,'on_delete'=>$fk['on_delete'],'on_update'=>$fk['on_update']];}
        }
        $violations=[];$ownership=[];$checked=0;
        $safe=['company_id','user_id','role_id','permission_id','product_id','supplier_id','customer_id','purchase_order_id','invoice_id','daily_sale_id','document_id','entity','entity_id','action','source','status','reference_number','document_number','payment_date','transaction_date','created_at','updated_at','amount','currency'];
        foreach($relations as $r) {
            $checked++;$table=$r['table'];$parent=$r['parent'];$cols=$r['columns'];$refs=$r['parent_columns'];
            $query=DB::table($table.' as child');foreach($cols as $col)$query->whereNotNull('child.'.$col); // MATCH SIMPLE: any NULL exempts the row.
            if($r['same_database'])$query->whereNotExists(function($q)use($parent,$cols,$refs){$q->selectRaw('1')->from($parent.' as parent');foreach($cols as $i=>$col)$q->whereColumn('parent.'.$refs[$i],'child.'.$col);});
            $count=$r['same_database']?(clone $query)->count():null;
            if(!$r['same_database'] || $count) {
                $record=$r+['affected_count'=>$count,'nullable'=>array_map(fn($c)=>$columns[$table][$c]??false,$cols),'primary_columns'=>$keys[$table]??[],'status'=>'EVIDENCE_REQUIRED'];
                if($withRecords){$select=array_values(array_intersect(array_keys($columns[$table]),array_unique([...($keys[$table]??[]),...$cols,...$safe])));$record['records']=(clone $query)->select(array_map(fn($c)=>'child.'.$c,$select))->limit(1001)->get()->map(fn($row)=>(array)$row)->all();$record['records_truncated']=count($record['records'])>1000;if($record['records_truncated'])$record['records']=array_slice($record['records'],0,1000);}
                $violations[]=$record;if($stopOnFailure)break;
            }
            if(count($cols)===1 && array_key_exists('company_id',$columns[$parent]??[]))$owners[$table][]=[$cols[0],$parent,$refs[0]];
        }
        if(!$stopOnFailure || !$violations)foreach($owners as $table=>$links) {
            if(array_key_exists('company_id',$columns[$table])) {
                foreach($links as [$col,$parent,$ref]){$count=DB::table($table.' as c')->join($parent.' as p','p.'.$ref,'=','c.'.$col)->whereColumn('c.company_id','!=','p.company_id')->count();if($count)$ownership[]=['table'=>$table,'relationship'=>$col,'count'=>$count];}
            } else {
                foreach($links as $i=>[$col,$parent,$ref])foreach(array_slice($links,$i+1) as [$other,$otherParent,$otherRef]){$count=DB::table($table.' as c')->join($parent.' as p','p.'.$ref,'=','c.'.$col)->join($otherParent.' as q','q.'.$otherRef,'=','c.'.$other)->whereColumn('p.company_id','!=','q.company_id')->count();if($count)$ownership[]=['table'=>$table,'relationships'=>[$col,$other],'count'=>$count];}
            }
        }
        return ['checked_at'=>now()->toIso8601String(),'constraints_total'=>count($relations),'constraints_checked'=>$checked,'healthy'=>!$violations&&!$ownership,'violated_constraints'=>count($violations),'violations'=>$violations,'ownership_violations'=>$ownership,'exceptions_are_valid_relationships'=>false];
    }

    private function databaseReferencesAreValid(): bool
    {
        return $this->referenceAudit(false,true)['healthy'];
    }

    private function tenantLinksAreValid(): bool
    {
        $links = [];
        if (DB::getDriverName() === 'mysql') {
            $database = DB::connection()->getDatabaseName();
            $tenantTables = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA',$database)->where('COLUMN_NAME','company_id')->pluck('TABLE_NAME')->flip();
            $groups = DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA',$database)->whereNotNull('REFERENCED_TABLE_NAME')
                ->get(['TABLE_NAME','CONSTRAINT_NAME','COLUMN_NAME','REFERENCED_TABLE_SCHEMA','REFERENCED_TABLE_NAME','REFERENCED_COLUMN_NAME'])
                ->groupBy(fn($row)=>$row->TABLE_NAME.':'.$row->CONSTRAINT_NAME);
            foreach ($groups as $group) {
                $key=$group->first();
                if (! $tenantTables->has($key->TABLE_NAME)) continue;
                if ($key->REFERENCED_TABLE_SCHEMA !== $database) return false;
                if ($group->count() === 1 && $tenantTables->has($key->REFERENCED_TABLE_NAME)) {
                    $links[]=[$key->TABLE_NAME,$key->COLUMN_NAME,$key->REFERENCED_TABLE_NAME,$key->REFERENCED_COLUMN_NAME];
                }
            }
        } else {
            foreach (Schema::getTables(Schema::getCurrentSchemaListing()) as $table) {
                $name=$table['name'];
                if (! Schema::hasColumn($name,'company_id')) continue;
                foreach (Schema::getForeignKeys($name) as $foreign) {
                    $parent=$foreign['foreign_table'];
                    if (count($foreign['columns']) === 1 && Schema::hasColumn($parent,'company_id')) $links[]=[$name,$foreign['columns'][0],$parent,$foreign['foreign_columns'][0]];
                }
            }
        }
        foreach ($links as [$table,$column,$parent,$parentColumn]) {
            if (DB::table($table.' as c')->join($parent.' as p','p.'.$parentColumn,'=','c.'.$column)->whereColumn('c.company_id','!=','p.company_id')->exists()) return false;
        }
        return true;
    }

    private function encrypt(string $json, string $passphrase): string
    {
        if (strlen($passphrase) < 16 || strlen($passphrase) > 200) throw new \RuntimeException('Use a separate backup passphrase of 16 to 200 characters.');
        $salt = random_bytes(16); $iv = random_bytes(12); $tag = '';
        $key = hash_pbkdf2('sha256', $passphrase, $salt, 310000, 32, true);
        $cipher = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
        if ($cipher === false) throw new \RuntimeException('Backup encryption failed.');
        return json_encode(['format' => 'aims-installation-encrypted', 'version' => 1, 'salt' => base64_encode($salt), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'ciphertext' => base64_encode($cipher)], JSON_THROW_ON_ERROR);
    }

    private function roots(): array
    {
        return ['documents' => config('synthetic.document_root') ?: storage_path('app/documents-private'), 'private' => storage_path('app/private'), 'public' => storage_path('app/public')];
    }

    private function safePath(string $path): void
    {
        if (! preg_match('~^(documents|private|public)/[A-Za-z0-9_./ -]+$~D', $path) || preg_match('~(^|/)(\.\.?)(/|$)~', $path) || str_contains($path, '//') || str_ends_with($path, '/') || preg_match('~(?:^|/)(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|/|$)~i', $path)) throw new \RuntimeException('Unsafe attachment path in installation backup.');
    }

    private function databaseProcess(string $executable, array $options, bool $restore = false): Process
    {
        $db = config('database.connections.mysql');
        $prefix = $restore ? 'restore' : 'backup';
        if ($username = config('production.'.$prefix.'_db_username')) {
            $file = config('production.'.$prefix.'_db_password_file');
            if (!is_string($file) || !is_readable($file) || !strlen(trim(file_get_contents($file)))) throw new \RuntimeException('The protected '.$prefix.' database credential file is required.');
            $db['username'] = $username;
            $db['password'] = trim(file_get_contents($file));
        }
        return new Process([$executable, '--no-defaults', '--host='.$db['host'], '--port='.$db['port'], '--user='.$db['username'], ...$options, $db['database']], base_path(), ['MYSQL_PWD' => $db['password']], null, 300);
    }

    private function requireMaintenance(): void
    {
        if (app()->environment('production') && ! app()->isDownForMaintenance()) throw new \RuntimeException('Put this installation into maintenance mode and stop all writers/workers before installation backup or restore.');
    }

    private function migrateRestoredDatabase(): void
    {
        if (\Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]) !== 0) throw new \RuntimeException('Restored database upgrade failed. Keep Installation B offline.');
    }

    private function health(array $values): void
    {
        $file = storage_path('framework/aims-backup-health.json');
        $previous = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        file_put_contents($file, json_encode(array_replace($previous ?: [], $values)), LOCK_EX);
    }

    private function retain(string $root): void
    {
        $known = [];
        foreach (glob($root.'/scheduled-*.aimsinstall.verified.json') as $manifest) {
            $file = substr($manifest, 0, -strlen('.verified.json'));
            $metadata = json_decode(file_get_contents($manifest), true);
            if (is_file($file) && ! is_link($file) && $metadata && hash_equals($metadata['sha256'], hash_file('sha256', $file))) $known[$file] = $manifest;
        }
        krsort($known);
        foreach (array_slice($known, config('production.backup_keep'), null, true) as $file => $manifest) { unlink($file); unlink($manifest); }
    }
}
