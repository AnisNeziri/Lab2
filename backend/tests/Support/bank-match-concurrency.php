<?php

// Two independent database connections exercise the real reconciliation authority.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (\Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
});

use App\Models\{BankStatementRow, Company, FinancialAccountTransaction, User};
use App\Services\{BankReconciliationService, FinancialAccountService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Artisan, Auth, DB};
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

$database = config('database.connections.mysql.database');
if (! app()->environment('testing') || config('database.default') !== 'mysql'
    || ! preg_match('/^aims_dc[12]_[a-z0-9_]+_test$/', $database)
    || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Requires a named disposable aims_dc1_*_test or aims_dc2_*_test loopback MariaDB database.');
}

if (($argv[1] ?? '') === 'worker') {
    Auth::login(User::findOrFail((int) $argv[2]));
    $row = BankStatementRow::findOrFail((int) $argv[3]);
    $transaction = FinancialAccountTransaction::findOrFail((int) $argv[4]);
    $barrier = $argv[5];
    DB::listen(function ($query) use ($barrier, $row) {
        // Pause after the ownership read: old code lets both workers pass it.
        if (str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'bank_statement_rows')
            && str_contains($query->sql, 'matched_transaction_id')
            && str_contains($query->sql, 'status')) {
            file_put_contents($barrier.'-'.$row->id, 'ready');
            $until = microtime(true) + 1.5;
            while (count(glob($barrier.'-*')) < 2 && microtime(true) < $until) usleep(1000);
        }
    });
    try {
        app(BankReconciliationService::class)->reconcile($row, $transaction);
        echo "matched\n";
    } catch (ValidationException) {
        echo "rejected\n";
    }
    exit(0);
}

Artisan::call('migrate', ['--force' => true]);
(new Database\Seeders\RolePermissionSeeder)->run();
$company = Company::factory()->create(['base_currency' => 'EUR']);
$user = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
Auth::login($user);
$accounts = app(FinancialAccountService::class);
$bank = $accounts->createAccount(['type' => 'bank', 'name' => 'DC1 race fixture', 'currency' => 'EUR', 'opening_balance' => 0, 'opening_date' => '2026-10-16']);
$transaction = $accounts->post($bank, ['type' => 'inflow', 'amount' => '75.00', 'transaction_date' => '2026-10-16', 'description' => 'Race fixture', 'counter_accounting_account_id'=>\App\Models\AccountingAccount::where('code','3000')->firstOrFail()->id, 'idempotency_key' => 'race-'.$company->id]);
$statement = app(BankReconciliationService::class)->import($bank, UploadedFile::fake()->createWithContent('race.csv', "Date,Amount\n2026-10-16,75.00\n2026-10-16,75.00\n"), ['date' => 'Date', 'amount' => 'Amount']);
if ($statement->rows->where('status', 'suggested')->count() !== 2) throw new RuntimeException('Both rows must retain legitimate suggestions.');
$barrier = storage_path('framework/testing/dc1-bank-'.bin2hex(random_bytes(8)));
if (! is_dir(dirname($barrier))) mkdir(dirname($barrier), 0700, true);
$workers = [];
foreach ($statement->rows as $row) {
    $worker = new Process([PHP_BINARY, __FILE__, 'worker', (string) $user->id, (string) $row->id, (string) $transaction->id, $barrier], base_path(), null, null, 30);
    $worker->start();
    $workers[] = $worker;
}
foreach ($workers as $worker) {
    $worker->wait();
    if (! $worker->isSuccessful()) throw new RuntimeException($worker->getErrorOutput().$worker->getOutput());
}
$outcomes = array_map(fn ($w) => trim($w->getOutput()), $workers);
sort($outcomes);
$owners = $statement->rows()->where('status', 'reconciled')->count();
echo json_encode(['workers' => $outcomes, 'active_matches' => $owners, 'engine' => DB::selectOne('select version() as v')->v]).PHP_EOL;
foreach ($statement->rows as $row) if (is_file($barrier.'-'.$row->id)) unlink($barrier.'-'.$row->id);
if ($owners !== 1 || $outcomes !== ['matched', 'rejected']) throw new RuntimeException('Concurrent statement rows obtained duplicate authoritative ownership.');
$winner = $statement->rows()->where('status', 'reconciled')->firstOrFail();
$loser = $statement->rows()->where('status', '!=', 'reconciled')->firstOrFail();
$service = app(BankReconciliationService::class);
$events = $winner->events()->count();
$service->reconcile($winner, $transaction);
if ($winner->events()->count() !== $events) throw new RuntimeException('Match replay duplicated audit effects.');
$service->unmatch($winner, 'Fixture reviewer corrected the row selection.');
$service->reconcile($loser, $transaction);
try { $accounts->reverse($transaction, 'Fixture matched transaction reversal.'); throw new RuntimeException('A matched source was reversed without releasing its bank owner.'); }
catch (ValidationException) {}
$service->unmatch($loser, 'Release the final match before reversing its source.');
$accounts->reverse($transaction, 'Fixture transaction reversal.');
try { $service->reconcile($winner, $transaction); throw new RuntimeException('Reversed transaction was accepted.'); }
catch (ValidationException) {}
if ($winner->events()->where('action', 'unmatched')->count() !== 1) throw new RuntimeException('Unmatch audit was lost.');
echo "PASS: one concurrent owner; suggestions, replay, unmatch/rematch, reversal and history preserved.\n";
