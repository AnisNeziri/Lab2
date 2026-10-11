<?php

namespace App\Services;

use App\Support\Release;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

final class ProductionReadinessService
{
    public function configuration(): array
    {
        $checks = [];
        $desktop = config('system.operation_mode') === 'offline';
        $add = function (string $key, bool $ok, string $failure) use (&$checks): void {
            $checks[] = ['key' => $key, 'status' => $ok ? 'healthy' : 'critical', 'detail' => $ok ? 'Configured.' : $failure];
        };
        $add('environment', app()->environment('production'), 'Set APP_ENV=production. Synthetic and testing configurations cannot serve production.');
        $add('restore_state', ! is_file(storage_path('framework/aims-restore-incomplete')), 'An installation restore is incomplete. Keep it offline and retry into a clean target; do not remove the marker to bypass validation.');
        $add('debug', ! config('app.debug'), 'Set APP_DEBUG=false to prevent stack traces and private configuration exposure.');
        $key = (string) config('app.key');
        $bytes = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $add('app_key', is_string($bytes) && strlen($bytes) === 32 && $key !== 'base64:32Hr/pYy5GkWR0Lcm3HnL0fHw2cbCtmHtravymO8GA0=', 'Generate a unique APP_KEY and escrow it separately from backups.');
        $add('jwt_key', strlen((string) config('jwt.secret')) >= 32, 'Configure a strong JWT_SECRET or unique APP_KEY.');
        $add('database_mode', $desktop ? config('database.default') === 'sqlite' : config('database.default') === 'mysql', 'Supported production database: SQLite for offline desktop; MariaDB/MySQL for online server.');
        $add('database_url', ! config('database.connections.'.config('database.default').'.url'), 'Use explicit DB_HOST/DB_PORT/DB_DATABASE credentials; remove DB_URL overrides.');
        $add('queue', config('queue.default') === 'database', 'Set QUEUE_CONNECTION=database and supervise default, supply-optimizer and strategic-simulation workers.');
        $add('queue_retry', (int) config('queue.connections.database.retry_after') > 240, 'Set DB_QUEUE_RETRY_AFTER above the longest 240-second worker timeout (300 recommended).');
        $add('cache', in_array(config('cache.default'), $desktop ? ['file'] : ['file', 'redis'], true), 'Use persistent file or Redis cache; array cache cannot coordinate production jobs.');
        $add('session', in_array(config('session.driver'), $desktop ? ['file'] : ['file', 'database', 'redis'], true), 'Configure persistent session storage.');
        $add('logs', config('logging.default') === 'daily' || (config('logging.default') === 'stack' && in_array('daily', config('logging.channels.stack.channels', []), true)), 'Use LOG_CHANNEL=daily (or a stack containing daily) and LOG_DAILY_DAYS for bounded local logs.');
        $add('timezone', in_array(config('app.timezone'), timezone_identifiers_list(), true) && in_array(config('production.timezone'), timezone_identifiers_list(), true), 'Set APP_TIMEZONE=UTC and an explicit AIMS_COMPANY_TIMEZONE IANA identifier.');
        $add('locale', in_array(config('app.locale'), ['en', 'sq'], true), 'Set APP_LOCALE to a supported locale: en or sq.');
        $origins = config('cors.allowed_origins', []);
        $url = (string) config('app.url');
        $validUrl = $desktop ? (bool) preg_match('~^http://127\.0\.0\.1:\d+$~D', $url) : $this->productionUrl($url);
        $add('url', $validUrl, 'Set APP_URL to your HTTPS production URL (desktop uses its explicit loopback port).');
        $add('cors', $origins !== [] && ! config('cors.allowed_origins_patterns') && collect($origins)->every(fn ($origin) => $desktop ? (bool) preg_match('~^http://127\.0\.0\.1:\d+$~D', $origin) : $this->productionUrl($origin)), 'Set CORS_ALLOWED_ORIGINS to explicit HTTPS frontend origins; wildcard and development origins are rejected.');
        if (! $desktop) {
            $db = config('database.connections.mysql');
            $add('db_credentials', filled($db['database'] ?? null) && filled($db['username'] ?? null) && ! in_array(strtolower($db['username'] ?? ''), ['root', 'aims'], true) && strlen($db['password'] ?? '') >= 16 && ! in_array(strtolower($db['password'] ?? ''), ['password', 'changeme'], true), 'Configure a dedicated least-privilege DB_USERNAME and a unique DB_PASSWORD of at least 16 characters.');
            $add('redis_config', (bool) config('system.redis_enabled'), 'Server mode requires REDIS_ENABLED=true. Offline desktop deliberately disables Redis.');
            $add('frontend_url', $this->productionUrl((string) config('app.frontend_url')), 'Set FRONTEND_URL to your HTTPS application origin.');
        }
        if (config('production.mail_enabled')) {
            $add('mail', config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')) && filled(config('mail.mailers.smtp.username')) && filled(config('mail.mailers.smtp.password')) && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) && ! preg_match('/@(example\.|.*\.test$)/i', (string) config('mail.from.address')), 'Email is enabled: configure SMTP host, credentials and a real MAIL_FROM_ADDRESS.');
        }
        $add('backup_schedule', in_array(config('production.backup_schedule'), ['manual', 'daily', 'weekly'], true), 'AIMS_BACKUP_SCHEDULE must be manual, daily or weekly.');
        if (config('production.backup_schedule') !== 'manual') {
            $file = config('production.backup_passphrase_file');
            $root = str_replace('\\', '/', config('production.backup_root'));
            $add('backup_key', is_string($file) && is_readable($file) && strlen(trim(file_get_contents($file))) >= 16 && ! str_starts_with(str_replace('\\', '/', realpath($file) ?: $file), rtrim($root, '/').'/'), 'Scheduled backups require a protected AIMS_BACKUP_PASSPHRASE_FILE outside the backup directory.');
        }
        return $checks;
    }

    public function inspect(bool $workers = true): array
    {
        $checks = $this->configuration();
        $probe = function (string $key, callable $check, string $failure) use (&$checks): void {
            try { $detail = $check(); $checks[] = ['key' => $key, 'status' => 'healthy', 'detail' => $detail]; }
            catch (\Throwable) { $checks[] = ['key' => $key, 'status' => 'critical', 'detail' => $failure]; }
        };
        $probe('php', function () {
            $extensions = ['bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'mbstring', 'openssl', 'pdo', 'xml', 'zlib', config('database.default') === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql'];
            if (PHP_VERSION_ID < 80335 || array_diff($extensions, array_map('strtolower', get_loaded_extensions()))) throw new \RuntimeException();
            return 'PHP '.PHP_VERSION.'; required extensions loaded.';
        }, 'Install the pinned PHP 8.3.35+ runtime with bcmath, ctype, curl, dom, fileinfo, mbstring, openssl, PDO, xml, zlib and the selected PDO driver.');
        $probe('database', function () {
            $version = DB::connection()->getDriverName() === 'sqlite' ? DB::selectOne('select sqlite_version() as version')->version : DB::selectOne('select version() as version')->version;
            $minimum = config('database.default') === 'sqlite' ? '3.27' : (str_contains($version, 'MariaDB') ? '10.4' : '8.0');
            if (version_compare($version, $minimum, '<')) throw new \RuntimeException();
            return config('database.default').' '.$version;
        }, 'Database connection/version check failed. Verify DB_HOST, DB_PORT, database, credentials and the supported engine version.');
        $probe('production_data', function () {
            if (\Illuminate\Support\Facades\Schema::hasTable('_aims_synthetic_manifest')) throw new \RuntimeException();
            if (DB::table('users')->where('is_active', true)->where('role', '!=', 'superadmin')->where(function ($query) {
                $query->where('email', 'like', '%.test')->orWhere('email', 'like', '%@example.%')->orWhereIn('email', ['admin@enterprise.com', 'admin@aims.local', 'owner@aims-demo.test']);
            })->exists()) throw new \RuntimeException();
            return 'No synthetic database marker or known demo business accounts.';
        }, 'Production database contains synthetic/demo accounts or is not initialized. Use a fresh installation; retire test accounts before startup.');
        if (config('system.operation_mode') !== 'offline' || in_array('redis', [config('cache.default'), config('session.driver')], true)) {
            $probe('redis', fn () => (string) DB::getFacadeApplication()->make('redis')->connection()->ping() ? 'Redis reachable.' : throw new \RuntimeException(), 'Redis connection failed. Verify REDIS_HOST, REDIS_PORT, credentials and start Redis.');
        } else $checks[] = ['key' => 'redis', 'status' => 'not_configured', 'detail' => 'Optional in offline desktop mode.'];
        foreach (['storage' => storage_path(), 'cache_directory' => storage_path('framework/cache/data'), 'sessions_directory' => storage_path('framework/sessions'), 'views_directory' => storage_path('framework/views'), 'logs_directory' => storage_path('logs'), 'bootstrap_cache' => base_path('bootstrap/cache'), 'documents_directory' => config('synthetic.document_root') ?: storage_path('app/documents-private'), 'backups_directory' => config('production.backup_root')] as $key => $directory) {
            $probe($key, function () use ($directory) {
                if (! is_dir($directory) || ! is_writable($directory)) throw new \RuntimeException();
                $file = tempnam($directory, '.aims-check-');
                if ($file === false) throw new \RuntimeException();
                try { if (file_put_contents($file, 'check') !== 5) throw new \RuntimeException(); }
                finally { if (is_file($file)) unlink($file); }
                $free = disk_free_space($directory);
                if ($free === false || $free < config('production.min_free_bytes')) throw new \RuntimeException();
                return 'Writable; free disk reserve available.';
            }, 'Create the required '.$key.' directory, grant the AIMS service account write access and free at least '.config('production.min_free_bytes').' bytes.');
        }
        $probe('python', function () {
            $vendor = base_path('ml/optimizer_vendor');
            $code = 'import sys,json,importlib.metadata as m; sys.path.insert(0,sys.argv[1]); from scipy.optimize import milp; print(json.dumps({"version":list(sys.version_info[:2]),"numpy":m.version("numpy"),"scipy":m.version("scipy")}))';
            $process = new Process([config('inventory_intelligence.python'), '-I', '-c', $code, $vendor], base_path(), null, null, 15);
            $process->mustRun(); $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if ($data['version'] !== [3, 12] || $data['numpy'] !== '2.3.5' || $data['scipy'] !== '1.16.2') throw new \RuntimeException();
            return 'Python 3.12; NumPy 2.3.5; SciPy 1.16.2 (local MILP available).';
        }, 'Set AIMS_ML_PYTHON to Python 3.12 and install the pinned ml/supply_optimizer_requirements.txt packages into ml/optimizer_vendor (or bundled Python site-packages).');
        if ($workers) foreach (['default', 'supply-optimizer', 'strategic-simulation', 'scheduler'] as $queue) {
            $file = storage_path('framework/aims-heartbeat-'.$queue.'.json');
            $heartbeat = is_file($file) ? json_decode(file_get_contents($file), true) : null;
            $fresh = $heartbeat && ($heartbeat['version'] ?? '') === Release::version() && ($heartbeat['at'] ?? 0) >= time() - config('production.worker_max_age');
            $checks[] = ['key' => 'worker_'.$queue, 'status' => $fresh ? 'healthy' : 'critical', 'detail' => $fresh ? 'Current release heartbeat received.' : 'Start or restart the supervised '.$queue.' process for this release; heartbeat is missing or stale.'];
        }
        return ['version' => Release::version(), 'release_status' => Release::metadata()['status'], 'mode' => config('system.operation_mode'), 'checked_at' => now()->toIso8601String(), 'status' => collect($checks)->contains('status', 'critical') ? 'critical' : 'healthy', 'checks' => $checks];
    }

    public function heartbeat(string $queue): void
    {
        if (! in_array($queue, ['default', 'supply-optimizer', 'strategic-simulation', 'scheduler'], true)) throw new \InvalidArgumentException('Unknown worker queue.');
        $file = storage_path('framework/aims-heartbeat-'.$queue.'.json');
        $temporary = $file.'.'.bin2hex(random_bytes(8));
        if (file_put_contents($temporary, json_encode(['at' => time(), 'version' => Release::version()]), LOCK_EX) === false || ! rename($temporary, $file)) throw new \RuntimeException('Worker heartbeat directory is not writable.');
    }

    private function productionUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' && $host && ! preg_match('/(^localhost$|^127\.|^0\.|\.test$|\.invalid$|example\.)/i', $host) && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS);
    }
}
