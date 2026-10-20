<?php

use App\Integrations\Sef\AtkTestClient;
use App\Integrations\Sef\FiscalizationService;
use App\Models\FiscalConfiguration;
use App\Models\FiscalReceipt;
use App\Models\Invoice;
use App\Models\SefMapping;
use App\Models\User;
use App\Services\VatReportingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
});
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.host') !== '127.0.0.1' || (int) config('database.connections.mysql.port') !== 3312 || ! preg_match('/^aims_dc3_[a-z0-9_]+_test$/', config('database.connections.mysql.database'))) {
    throw new RuntimeException('Requires owned loopback MariaDB 3312 and disposable aims_dc3_*_test database.');
}
if (($argv[1] ?? '') === 'worker') {
    Auth::login(User::findOrFail((int) $argv[3]));
    $id = (int) $argv[4];
    $barrier = $argv[5];
    $slot = $argv[6];
    $sent = $argv[7];
    file_put_contents($barrier.'-'.$slot, 'ready');
    $deadline = microtime(true) + 15;
    while (count(glob($barrier.'-*')) < 2) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Race peer missing');
        }usleep(10000);
    }
    Http::preventStrayRequests();
    Http::fake([AtkTestClient::ENDPOINT => function () use ($sent) {
        file_put_contents($sent, "local mock egress\n", FILE_APPEND | LOCK_EX);
        usleep(400000);

        return Http::response(['transaction_id' => '99887766', 'message' => 'local test double'], 200);
    }]);
    try {
        $r = $argv[2] === 'prepare' ? app(FiscalizationService::class)->prepare(Invoice::findOrFail($id)) : app(FiscalizationService::class)->submit(FiscalReceipt::findOrFail($id));
        echo json_encode(['state' => $r->status, 'id' => $r->id]).PHP_EOL;
    } catch (ValidationException $e) {
        echo json_encode(['state' => 'rejected_pending', 'message' => $e->getMessage()]).PHP_EOL;
    }
    exit;
}
Artisan::call('migrate', ['--force' => true]);
require __DIR__.'/dc3-local-fiscal-fixture.php';
$user = dc3LocalFiscalCompany('dc3-race-'.bin2hex(random_bytes(4)).'@example.test');
$invoice = dc3LocalInvoice();
SefMapping::create(['kind' => 'unit', 'local_key' => 'pcs', 'official_id' => 1]);
$config = FiscalConfiguration::firstOrFail();
$config->update(['enabled' => true]);
$dir = storage_path('framework/testing');
if (! is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$sent = $dir.'/dc3-sent-'.bin2hex(random_bytes(6));
$race = function ($operation, $id) use ($user, $dir, $sent) {
    $barrier = $dir.'/dc3-barrier-'.bin2hex(random_bytes(6));
    $workers = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $p = new Process([PHP_BINARY, __FILE__, 'worker', $operation, (string) $user->id, (string) $id, $barrier, (string) $i, $sent], base_path(), null, null, 45);
            $p->start();
            $workers[] = $p;
        }
        $rows = [];
        foreach ($workers as $p) {
            $p->wait();
            if (! $p->isSuccessful()) {
                throw new RuntimeException($p->getErrorOutput().$p->getOutput());
            }$rows[] = json_decode(trim($p->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        }

return $rows;
    } finally {
        foreach ($workers as $p) {
            if ($p->isRunning()) {
                $p->stop();
            }
        }foreach (glob($barrier.'-*') as $file) {
            unlink($file);
        }
    }
};
try {
    $prepare = $race('prepare', $invoice->id);
    $receipt = FiscalReceipt::firstOrFail();
    if (count(array_unique(array_column($prepare, 'id'))) !== 1 || FiscalReceipt::count() !== 1 || $config->fresh()->next_coupon_no !== '2') {
        throw new RuntimeException('Concurrent preparation duplicated source/counter ownership.');
    }
    $submit = $race('submit', $receipt->id);
    $sends = file_exists($sent) ? count(file($sent, FILE_IGNORE_NEW_LINES)) : 0;
    if ($sends !== 1 || $receipt->attempts()->count() !== 1 || $receipt->fresh()->status !== 'fiscalized') {
        throw new RuntimeException('Concurrent submissions duplicated delivery ownership.');
    }
    if (! app(VatReportingService::class)->reconciliation()['reconciled']) {
        throw new RuntimeException('VAT controls changed during fiscalization.');
    }
    echo json_encode(['engine' => DB::selectOne('SELECT VERSION() AS v')->v, 'prepare_workers' => $prepare, 'submit_workers' => $submit, 'receipts' => 1, 'attempts' => 1, 'local_mock_sends' => $sends, 'vat_gl' => 'PASS', 'official_atk_test' => 'NOT TESTED'], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if (file_exists($sent)) {
        unlink($sent);
    }
}
