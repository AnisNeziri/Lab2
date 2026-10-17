<?php

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\OnboardingSession;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Onboarding\OnboardingBackupService;
use App\Services\Onboarding\OnboardingPosting;
use App\Services\Onboarding\OnboardingSchema;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\OnboardingValidation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'Onboarding concurrency failed: '.get_class($error)."\n");
    exit(1);
});


$database = config('database.connections.mysql.database');
if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! preg_match('/^aims_dc1_[a-z0-9_]+_test$/', $database) || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Named disposable loopback MariaDB test database required.');
}

if (($argv[1] ?? '') === 'worker') {
    Auth::login(User::findOrFail((int) $argv[2]));
    $session = OnboardingSession::findOrFail((int) $argv[3]);
    $directory = $argv[4];
    file_put_contents($directory.'/ready-'.getmypid(), 'ready');
    $until = microtime(true) + 15;
    while (! is_file($directory.'/go') && microtime(true) < $until) {
        usleep(10000);
    }
    if (! is_file($directory.'/go')) {
        throw new RuntimeException('Race barrier timed out.');
    }
    $result = app(OnboardingPosting::class)->post($session->id, ['revision' => $session->revision, 'fingerprint' => $session->approved_fingerprint, 'confirmed' => true, 'limit' => 50]);
    echo json_encode(['processed' => $result['processed'], 'failure' => $result['failure'], 'status' => $result['session']->status])."\n";
    exit($result['failure'] ? 1 : 0);
}
Artisan::call('migrate', ['--force' => true]);
(new RolePermissionSeeder)->run();
$company = Company::factory()->create(['base_currency' => 'EUR']);
$user = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
Auth::login($user);
$service = app(OnboardingService::class);
$totals = array_fill_keys(OnboardingSchema::TOTALS, '0.00');
$totals['inventory_value'] = '31.25';
$totals['equity'] = '31.25';
$totals['inventory_units'] = ['m' => '12.500'];
$s = $service->create(['name' => 'Synthetic concurrent opening', 'source_period' => 'Synthetic concurrency source', 'cutover_date' => '2026-01-01', 'timezone' => 'Europe/Tirane', 'currency' => 'EUR', 'expected_totals' => $totals]);
$files = [];
foreach (['products', 'warehouses', 'inventory'] as $domain) {
    $file = tmpfile();
    $files[] = $file;
    fwrite($file, OnboardingSchema::template($domain));
    $service->upload($s->id, new UploadedFile(stream_get_meta_data($file)['uri'], $domain.'.csv', null, null, true), ['domain' => $domain, 'revision' => $s->fresh()->revision]);
}
app(OnboardingValidation::class)->validate($s->id, $s->fresh()->revision);
app(OnboardingBackupService::class)->create($s->id, ['kind' => 'pre', 'revision' => $s->fresh()->revision, 'passphrase' => 'synthetic-concurrency-backup-passphrase']);
app(OnboardingPosting::class)->review($s->id, ['revision' => $s->fresh()->revision, 'source_totals_confirmed' => true, 'warnings_acknowledged' => true]);
$s->refresh();
app(OnboardingPosting::class)->approve($s->id, ['revision' => $s->revision, 'fingerprint' => $s->review_fingerprint, 'confirmed' => true]);
$directory = storage_path('app/onboarding-races/'.Str::uuid());
mkdir($directory, 0700, true);
$workers = [];
for ($i = 0; $i < 2; $i++) {
    $workers[] = new Process([PHP_BINARY, __FILE__, 'worker', (string) $user->id, (string) $s->id, $directory], base_path(), null, null, 90);
    $workers[$i]->start();
}
$until = microtime(true) + 15;
while (count(glob($directory.'/ready-*')) < 2 && microtime(true) < $until) {
    usleep(10000);
}touch($directory.'/go');
$results = [];
foreach ($workers as $worker) {
    $worker->wait();
    if (! $worker->isSuccessful()) {
        throw new RuntimeException('Concurrent posting worker failed.');
    }$results[] = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
}
$s->refresh();
if ($s->status !== 'posted' || Product::count() !== 1 || StockMovement::count() !== 1 || JournalEntry::count() !== 1 || $s->rows()->whereNotNull('posted_at')->count() !== 3) {
    throw new RuntimeException('Concurrent opening duplicated or omitted authoritative effects.');
}
echo json_encode(['workers' => $results, 'posted_rows' => 3, 'products' => 1, 'stock_movements' => 1, 'journals' => 1, 'engine' => DB::selectOne('SELECT VERSION() as v')->v])."\n";
echo "PASS: two posting connections commit each approved source row and its authoritative effects exactly once.\n";
