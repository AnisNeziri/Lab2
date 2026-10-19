<?php

use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Workforce\PayrollReadService;
use App\Services\Workforce\PayrollService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('e2e') || config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath(base_path('database/dc4-preview.sqlite'))) {
    throw new RuntimeException('Probe requires the isolated DC4 synthetic preview DB.');
}
Auth::login(User::where('email', 'dc4-payroll@e2e.example')->firstOrFail());
$service = app(PayrollService::class);
$read = app(PayrollReadService::class);
$run = PayrollRun::where('status', 'closed')->latest('id')->firstOrFail();
$results = [];
foreach (['summary' => fn () => $read->summary(), 'detail' => fn () => $service->detail($run->id)] as $name => $operation) {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $start = hrtime(true);
    $data = $operation();
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    $results[$name] = ['queries' => count(DB::getQueryLog()), 'bytes' => strlen($json), 'milliseconds' => round((hrtime(true) - $start) / 1e6, 2)];
    DB::disableQueryLog();
}
$results['fixture'] = ['employees' => 1, 'qualification' => 'Small synthetic projection probe only; no scale extrapolation. Summary avoids payroll-line/private snapshot and Financial Intelligence payloads.'];
echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
