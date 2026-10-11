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
        $root = config('production.backup_root');
        if (is_link($root)) throw new \RuntimeException('Backup directory must not be a symbolic link.');
        if (! is_dir($root) && ! mkdir($root, 0700, true)) throw new \RuntimeException('Backup directory cannot be created.');
        $lock = fopen(storage_path('framework/aims-installation-backup.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Another installation backup is running. Retry after it completes.');
        $temporary = tempnam($root, '.snapshot-');
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
                $this->databaseProcess(config('production.mysqldump'), ['--single-transaction', '--quick', '--hex-blob', '--skip-lock-tables', '--result-file='.$temporary])->mustRun();
            } else throw new \RuntimeException('Unsupported database engine.');
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
            $reconciliation = $this->reconcile();
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
            $this->health(['last_success_at' => $result['created_at'], 'last_verified_at' => $result['created_at'], 'last_failure_at' => null]);
            if ($scheduled) $this->retain($root);
            return $result;
        } catch (\Throwable $error) {
            $this->health(['last_failure_at' => now()->toIso8601String(), 'error_code' => 'installation_backup_failed']);
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
        $payload = $this->verify($file, $passphrase);
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
            $process = $this->databaseProcess(config('production.mysql'), ['--binary-mode=1']);
            $process->setInput($database); $process->mustRun(); DB::purge();
        }
        // Restore is intentionally offline. A failure leaves the marker in place
        // and never presents partial SQL/file restoration as an operable system.
        $this->migrateRestoredDatabase();
        foreach ($payload['files'] as $path => $entry) {
            [$label, $relative] = explode('/', $path, 2);
            $target = $this->roots()[$label].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            $bytes = base64_decode($entry['bytes'], true);
            if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes) || ! hash_equals($entry['sha256'], hash_file('sha256', $target))) throw new \RuntimeException('Attachment restore failed. Keep this installation offline and retry into a clean target.');
        }
        $actual = $this->reconcile();
        if (! $actual['healthy'] || $actual['totals'] !== $payload['reconciliation']['totals']) throw new \RuntimeException('Post-restore critical totals or reconciliation failed. This installation remains blocked.');
        // No pre-loss login tokens, queued jobs or external delivery retries may
        // become live merely because a recovery database has been activated.
        DB::transaction(function () {
            foreach (['refresh_tokens', 'password_reset_tokens', 'sessions', 'jobs', 'job_batches'] as $table) if (Schema::hasTable($table)) DB::table($table)->delete();
            DB::table('users')->update(['api_token' => null, 'remember_token' => null]);
            DB::table('users')->increment('token_version');
            if (Schema::hasTable('automations')) DB::table('automations')->update(['enabled' => false]);
            if (Schema::hasTable('integration_providers')) DB::table('integration_providers')->update(['enabled' => false]);
            if (Schema::hasTable('integration_operation_logs')) DB::table('integration_operation_logs')->whereNotNull('next_retry_at')->update(['next_retry_at' => null]);
            if (Schema::hasTable('webhook_endpoints')) DB::table('webhook_endpoints')->update(['enabled' => false]);
            if (Schema::hasTable('webhook_deliveries')) DB::table('webhook_deliveries')->whereNotNull('next_retry_at')->update(['next_retry_at' => null]);
            if (Schema::hasTable('order_channels')) DB::table('order_channels')->update(['enabled' => false]);
            if (Schema::hasTable('order_channel_keys')) DB::table('order_channel_keys')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });
        unlink($marker);
        $this->health(['last_restore_verified_at' => now()->toIso8601String(), 'last_verified_at' => now()->toIso8601String()]);
        return ['status' => 'restored_and_reconciled', 'version' => Release::version(), 'inventory' => true, 'finance' => true, 'attachments' => count($payload['files']), 'tenant_isolation' => true, 'totals' => $actual['totals']];
    }

    public function reconcile(): array
    {
        $prior = Auth::user(); $healthy = true; $totals = [];
        try {
            foreach (Company::orderBy('id')->get() as $company) {
                $actor = User::withoutGlobalScopes()->where('company_id', $company->id)->where('role', 'admin')->where('is_active', true)->first();
                if (! $actor) { if (DB::table('products')->where('company_id', $company->id)->exists()) $healthy = false; continue; }
                Auth::setUser($actor);
                foreach (Product::query()->get() as $product) if (app(InventoryIntegrityService::class)->productSnapshot($product)['issues']) $healthy = false;
                $finance = app(AccountingService::class)->reconciliation(false);
                if (! $finance['all_reconciled']) $healthy = false;
                if (app(DocumentMaintenanceService::class)->verifyCompany($company->id)) $healthy = false;
                $totals[(string) $company->id] = ['inventory_quantity' => number_format((float) DB::table('products')->where('company_id', $company->id)->sum('quantity'), 3, '.', ''), 'finance' => array_map(fn ($row) => array_intersect_key($row, array_flip(['operational_amount', 'gl_amount', 'difference', 'reconciled'])), $finance['rows']), 'users' => DB::table('users')->where('company_id', $company->id)->count(), 'documents' => DB::table('documents')->where('company_id', $company->id)->count()];
            }
            foreach (Schema::getTables(Schema::getCurrentSchemaListing()) as $table) {
                $name = $table['name'];
                if (! Schema::hasColumn($name, 'company_id')) continue;
                foreach (Schema::getForeignKeys($name) as $foreign) {
                    $parent = $foreign['foreign_table'];
                    if (count($foreign['columns']) !== 1 || ! Schema::hasColumn($parent, 'company_id')) continue;
                    if (DB::table($name.' as c')->join($parent.' as p', 'p.'.$foreign['foreign_columns'][0], '=', 'c.'.$foreign['columns'][0])->whereColumn('c.company_id', '!=', 'p.company_id')->exists()) $healthy = false;
                }
            }
        } finally { if ($prior) Auth::setUser($prior); else Auth::forgetUser(); }
        return compact('healthy', 'totals');
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

    private function databaseProcess(string $executable, array $options): Process
    {
        $db = config('database.connections.mysql');
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
