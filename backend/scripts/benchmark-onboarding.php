<?php

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Onboarding\OnboardingSchema;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\OnboardingValidation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'Synthetic onboarding benchmark failed: '.get_class($e)."\n");
    exit(1);
});
$database = config('database.connections.'.config('database.default').'.database');
$driver = config('database.default');
$ownedSqlite = $driver === 'sqlite' && preg_match('/^aims_dc1_perf_[a-z0-9_]+\.sqlite$/', basename($database));
$ownedMaria = $driver === 'mysql' && in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true) && preg_match('/^aims_dc1_perf_[a-z0-9_]+_test$/', $database);
if (! app()->environment('testing') || (! $ownedSqlite && ! $ownedMaria)) {
    throw new RuntimeException('Owned synthetic benchmark database required.');
}
Artisan::call('migrate', ['--force' => true]);
(new RolePermissionSeeder)->run();
$measurements = [];
$queries = 0;
DB::listen(function () use (&$queries) {
    $queries++;
});
foreach ([500, 2000, 10000] as $size) {
    $company = Company::create(['name' => 'Synthetic DC1 '.$size, 'address' => 'Synthetic benchmark only', 'base_currency' => 'EUR']);
    $user = User::create(['company_id' => $company->id, 'name' => 'Synthetic benchmark owner', 'email' => 'dc1-'.$company->id.'@benchmark.test', 'password' => Hash::make(bin2hex(random_bytes(12))), 'role' => 'admin', 'email_verified_at' => now()]);
    Auth::setUser($user);
    $service = app(OnboardingService::class);
    $s = $service->create(['name' => 'Synthetic '.$size, 'source_period' => 'Synthetic benchmark source', 'cutover_date' => '2026-01-01', 'currency' => 'EUR', 'timezone' => 'Europe/Tirane', 'expected_totals' => array_fill_keys(OnboardingSchema::TOTALS, '0.00') + ['inventory_units' => []]]);
    $source = "external_ref,sku,name,unit,purchase_price,selling_price\n";
    for ($i = 1; $i <= $size; $i++) {
        $source .= "P{$i},DC1-{$size}-{$i},Synthetic product {$i},m,2.50,5.00\n";
    }
    $temp = tmpfile();
    fwrite($temp, $source);
    $path = stream_get_meta_data($temp)['uri'];
    $file = new UploadedFile($path, 'synthetic.csv', null, null, true);
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $queries = 0;
    $start = hrtime(true);
    $service->upload($s->id, $file, ['domain' => 'products', 'revision' => $s->revision]);
    $upload = (hrtime(true) - $start) / 1e6;
    $start = hrtime(true);
    $result = app(OnboardingValidation::class)->validate($s->id, $s->fresh()->revision);
    $validation = (hrtime(true) - $start) / 1e6;
    $start = hrtime(true);
    $page = $service->rows($s->id, ['domain' => 'products']);
    $read = (hrtime(true) - $start) / 1e6;
    if (($result['reconciliation']['counts']['valid'] ?? 0) !== $size || ! $result['reconciliation']['ready_for_review'] || Product::count() !== 0 || StockMovement::count() !== 0 || JournalEntry::count() !== 0) {
        throw new RuntimeException('Benchmark violated dry-run controls.');
    }
    $measurements[] = ['source_rows' => $size, 'upload_ms' => round($upload, 2), 'dry_validation_ms' => round($validation, 2), 'page_50_ms' => round($read, 2), 'page_rows' => $page->count(), 'queries' => $queries, 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2), 'operational_effects' => 0, 'all_controls_passed' => true];
    fclose($temp);
}
echo json_encode(['synthetic' => true, 'database_engine' => $driver, 'sizes' => $measurements], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
