<?php

use App\Models\Company;
use App\Models\FinancialAccountTransaction;
use App\Models\JournalEntry;
use App\Models\PayrollPayment;
use App\Models\User;
use App\Services\FinancialAccountService;
use App\Services\Workforce\PayrollService;
use App\Services\Workforce\WorkforceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.host') !== '127.0.0.1' || (int) config('database.connections.mysql.port') !== 3312 || ! preg_match('/^aims_dc4_[a-z0-9_]+_test$/', config('database.connections.mysql.database'))) {
    throw new RuntimeException('Requires a dedicated loopback port-3312 aims_dc4_*_test database.');
}
if (($argv[1] ?? '') === 'worker') {
    Auth::login(User::findOrFail((int) $argv[2]));
    $payload = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    $barrier = $argv[4];
    $worker = $argv[5];
    file_put_contents($barrier.'-'.$worker, 'ready');
    $deadline = microtime(true) + 20;
    while (count(glob($barrier.'-*')) < 2) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Second worker did not reach the barrier.');
        } usleep(10000);
    }
    $service = app(PayrollService::class);
    $result = $payload['operation'] === 'post' ? $service->post($payload['run'], $payload['fingerprint']) : $service->pay($payload['run'], $payload['payment']);
    echo json_encode(['operation' => $payload['operation'], 'id' => $result->id, 'journal_entry_id' => $result->journal_entry_id], JSON_THROW_ON_ERROR);
    exit;
}
Artisan::call('migrate', ['--force' => true]);
(new RolePermissionSeeder)->run(true);
$company = Company::factory()->create(['base_currency' => 'EUR', 'timezone' => 'UTC']);
$user = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
Auth::login($user);
$payroll = app(PayrollService::class);
$workforce = app(WorkforceService::class);
$payroll->initialize();
$e = $workforce->saveEmployee(['employee_number' => 'DC4-RACE', 'name' => 'Synthetic race employee', 'start_date' => '2026-01-01', 'status' => 'active', 'payment_method' => 'bank']);
$workforce->terms($e->id, ['effective_from' => '2026-01-01', 'contract_type' => 'permanent', 'position' => 'Synthetic race', 'pay_basis' => 'monthly', 'amount' => '1000', 'currency' => 'EUR', 'proration_policy' => 'calendar_days', 'employer_classification' => 'primary', 'pension_treatment' => 'resident_contributor', 'employee_pension_rate' => 5, 'employer_pension_rate' => 5, 'reason' => 'Synthetic concurrency fixture']);
$bank = app(FinancialAccountService::class)->createAccount(['name' => 'DC4 race bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => '10000', 'opening_date' => '2026-01-01']);
$run = $payroll->createPeriod(['name' => 'October 2026', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'pay_date' => '2026-10-31']);
$run = $payroll->calculate($run->id);
$run = $payroll->approve($run->id, $run->fingerprint, true);
function race(array $payload, int $userId): array
{
    $barrier = sys_get_temp_dir().'/aims-dc4-'.bin2hex(random_bytes(6));
    $workers = [];
    foreach (['a', 'b'] as $name) {
        $p = new Process([PHP_BINARY, __FILE__, 'worker', (string) $userId, json_encode($payload, JSON_THROW_ON_ERROR), $barrier, $name], base_path(), null, null, 40);
        $p->start();
        $workers[] = $p;
    }
    $results = [];
    foreach ($workers as $p) {
        $p->wait();
        if (! $p->isSuccessful()) {
            throw new RuntimeException($p->getErrorOutput().$p->getOutput());
        }$results[] = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
    foreach (glob($barrier.'-*') as $file) {
        unlink($file);
    }

return $results;
}
$posted = race(['operation' => 'post', 'run' => $run->id, 'fingerprint' => $run->fingerprint], $user->id);
if ($posted[0]['journal_entry_id'] !== $posted[1]['journal_entry_id'] || JournalEntry::where('source_module', 'payroll')->where('source_key', 'payroll:'.$run->id)->count() !== 1) {
    throw new RuntimeException('Concurrent payroll posting duplicated a journal.');
}
$line = $run->lines()->firstOrFail();
$paid = race(['operation' => 'pay', 'run' => $run->id, 'payment' => ['kind' => 'salary', 'payroll_line_id' => $line->id, 'amount' => $line->net, 'paid_on' => '2026-10-31', 'financial_account_id' => $bank->id, 'idempotency_key' => 'dc4-concurrent-salary-'.$run->id]], $user->id);
if ($paid[0]['id'] !== $paid[1]['id'] || PayrollPayment::where('payroll_run_id', $run->id)->count() !== 1 || FinancialAccountTransaction::where('source_type', 'payroll_payment')->where('source_id', $run->id)->count() !== 1) {
    throw new RuntimeException('Concurrent salary settlement duplicated a payment.');
}
$result = ['engine' => DB::selectOne('SELECT VERSION() as version')->version, 'parallel_post_workers' => $posted, 'parallel_payment_workers' => $paid, 'one_payroll_journal' => true, 'one_salary_settlement' => true, 'one_bank_movement' => true, 'balanced' => JournalEntry::findOrFail($posted[0]['journal_entry_id'])->total_debit === JournalEntry::findOrFail($posted[0]['journal_entry_id'])->total_credit, 'obligations_reconciled' => $payroll->obligations($run->fresh())['reconciled']];
echo json_encode($result,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
