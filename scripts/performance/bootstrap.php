<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Only a marked disposable fixture under this checkout's output, or an internally
 * named local MariaDB fixture, may be bootstrapped. Never read the app's .env.
 */
require __DIR__.'/guards.php';
performanceErrors();
if (getenv('APP_ENV') === 'production') {
    throw new RuntimeException('Disposable performance tooling is forbidden in production.');
}
$port = performancePort();
$root = dirname(__DIR__, 2);
$target = $argv[1] ?? '';
$owned = performanceDirectory($root.'/output');
$engine = 'sqlite';
if (str_starts_with($target, 'mysql:')) {
    $seed = substr($target, 6);
    if (! ctype_digit($seed) || (int) $seed < 1 || (int) $seed > 2147483647) {
        throw new RuntimeException('Bounded performance seed required.');
    }
    $engine = 'mysql';
    $database = 'aims_pr2_perf_'.(int) $seed;
    $guard = new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$database, getenv('AIMS_PERF_MYSQL_USER') ?: 'root', getenv('AIMS_PERF_MYSQL_PASSWORD') ?: '');
} else {
    $database = realpath($target);
    if (! $owned || ! $database || ! is_file($database) || ! str_starts_with($database, $owned.DIRECTORY_SEPARATOR) || is_link($target)) {
        throw new RuntimeException('Owned disposable fixture under output required.');
    }
    performanceDirectory(dirname($database));
    $guard = new PDO('sqlite:'.$database);
}
if ($guard->query('select marker from _aims_performance_manifest')->fetchColumn() !== 'AIMS_PR2_PERFORMANCE_ONLY') {
    throw new RuntimeException('Performance ownership marker required.');
}
$guard = null;
$storage = $root.'/output/pr2-'.str_replace([':', '/', '\\'], '-', $engine === 'mysql' ? $database : basename(dirname($database))).'-storage';
$environment = ['APP_ENV' => 'e2e', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION' => $engine, 'DB_DATABASE' => $database, 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port,
    'DB_USERNAME' => getenv('AIMS_PERF_MYSQL_USER') ?: 'root', 'DB_PASSWORD' => getenv('AIMS_PERF_MYSQL_PASSWORD') ?: '',
    'DB_URL' => '', 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'log', 'REDIS_ENABLED' => 'false', 'TRACKING_EXTERNAL_ENABLED' => 'false',
    'APP_CONFIG_CACHE' => $storage.'/unused-config.php', 'LARAVEL_STORAGE_PATH' => $storage,
    'AIMS_DOCUMENT_ROOT' => $storage.'/documents', 'AIMS_BACKUP_ROOT' => $storage.'/backups'];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private', 'documents', 'backups'] as $dir) {
    if (is_link($storage) || is_link($storage.'/'.$dir)) {
        throw new RuntimeException('Symlinked performance storage refused.');
    }
    performanceDirectory($storage.'/'.$dir);
}
require $root.'/backend/vendor/autoload.php';
$app = require $root.'/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
performanceErrors();
if (in_array('--migrate', $argv, true)) {
    Artisan::call('migrate', ['--force' => true]);
}
$owner = User::where('role', 'admin')->where('is_active', true)->firstOrFail();
Auth::setUser($owner);
