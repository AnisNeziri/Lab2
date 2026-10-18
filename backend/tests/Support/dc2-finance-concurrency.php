<?php

use App\Models\AccountingAccount;
use App\Models\AccountingPeriod;
use App\Models\BankStatement;
use App\Models\BankStatementMatch;
use App\Models\BankStatementRow;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\BankReconciliationService;
use App\Services\CustomerDebtService;
use App\Services\CustomerSettlementService;
use App\Services\ExpenseService;
use App\Services\FinancialAccountService;
use App\Services\PurchaseOrderService;
use App\Services\SupplierPaymentAllocationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
});
if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! preg_match('/^aims_dc2_[a-z0-9_]+_test$/', config('database.connections.mysql.database')) || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Requires an explicitly named disposable aims_dc2_*_test loopback MariaDB database.');
}

if (($argv[1] ?? '') === 'worker') {
    Auth::login(User::findOrFail((int) $argv[3]));
    $p = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
    $barrier = $argv[5];
    file_put_contents($barrier.'-'.$p['worker'], 'ready');
    $deadline = microtime(true) + 15;
    while (count(glob($barrier.'-*')) < 2) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Second race worker did not reach the barrier.');
        }usleep(10000);
    }
    try {
        switch ($argv[2]) {
            case 'split':app(BankReconciliationService::class)->reconcileMany(BankStatementRow::findOrFail($p['row']), $p['transactions']);
                break;
            case 'customer':app(CustomerSettlementService::class)->allocate(CustomerDebtTransaction::findOrFail($p['payment']), $p['input']);
                break;
            case 'supplier':app(SupplierPaymentAllocationService::class)->allocate(PurchaseOrderPayment::findOrFail($p['payment']), Expense::findOrFail($p['expense']), 600, 'Concurrent reviewed supplier allocation.', $p['key']);
                break;
            case 'close':app(AccountingService::class)->setPeriodStatus(AccountingPeriod::findOrFail($p['period']), 'closed', 'Close the reviewed concurrency test period.');
                break;
            case 'journal':app(AccountingService::class)->createAndPost($p['journal']);
                break;
            case 'complete':app(BankReconciliationService::class)->complete(BankStatement::findOrFail($p['statement']));
                break;
            case 'bank_post':app(FinancialAccountService::class)->post(FinancialAccount::findOrFail($p['account']), $p['movement']);
                break;
            default:throw new RuntimeException('Unknown race operation.');
        }
        echo json_encode(['operation' => $argv[2], 'result' => 'committed']).PHP_EOL;
    } catch (ValidationException $e) {
        echo json_encode(['operation' => $argv[2], 'result' => 'rejected', 'reason' => $e->getMessage()]).PHP_EOL;
    }
    exit(0);
}

Artisan::call('migrate', ['--force' => true]);
(new RolePermissionSeeder)->run(true);
function dc2Context(): User
{
    Auth::logout();
    $company = Company::factory()->create(['base_currency' => 'EUR', 'timezone' => 'UTC']);
    $user = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
    Auth::login($user);
    app(AccountingService::class)->initialize();

    return $user;
}
function dc2Bank(): FinancialAccount
{
    return app(FinancialAccountService::class)->createAccount(['name' => 'DC2 race bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => 0, 'opening_date' => '2026-09-01']);
}
function dc2Movement(FinancialAccount $bank, int $amount, string $key)
{
    return app(FinancialAccountService::class)->post($bank, ['type' => 'inflow', 'amount' => $amount, 'transaction_date' => '2026-09-15', 'counter_accounting_account_id' => AccountingAccount::where('code', '3000')->firstOrFail()->id, 'idempotency_key' => $key]);
}
function dc2Race(User $user, array $calls): array
{
    $barrier = storage_path('framework/testing/dc2-finance-'.bin2hex(random_bytes(8)));
    if (! is_dir(dirname($barrier))) {
        mkdir(dirname($barrier), 0700, true);
    }$workers = [];
    try {
        foreach ($calls as $i => [$operation,$payload]) {
            $payload['worker'] = $i;
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $operation, (string) $user->id, json_encode($payload, JSON_THROW_ON_ERROR), $barrier], base_path(), null, null, 60);
            $process->start();
            $workers[] = $process;
        }
        $results = [];
        foreach ($workers as $w) {
            $w->wait();
            if (! $w->isSuccessful()) {
                throw new RuntimeException($w->getErrorOutput().$w->getOutput());
            }$results[] = json_decode(trim($w->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($workers as $w) {
            if ($w->isRunning()) {
                $w->stop();
            }
        }foreach (glob($barrier.'-*') as $path) {
            unlink($path);
        }
    }
}
function dc2OneWinner(array $results): void
{
    $states = array_column($results, 'result');
    sort($states);
    if ($states !== ['committed', 'rejected']) {
        throw new RuntimeException('Expected exactly one committed contender: '.json_encode($results));
    }
}

$evidence = ['engine' => DB::selectOne('select version() v')->v, 'cases' => []];
$user = dc2Context();
$bank = dc2Bank();
$tx = [];
foreach ([2000, 2500, 500, 500] as $i => $amount) {
    $tx[] = dc2Movement($bank, $amount, 'split-'.$user->id.'-'.$i);
}
$statement = app(BankReconciliationService::class)->import($bank, UploadedFile::fake()->createWithContent('split.csv', "Date,Amount\n2026-09-15,5000.00\n2026-09-15,5000.00\n"), ['date' => 'Date', 'amount' => 'Amount']);
$results = dc2Race($user, [['split', ['row' => $statement->rows[0]->id, 'transactions' => [$tx[0]->id, $tx[1]->id, $tx[2]->id]]], ['split', ['row' => $statement->rows[1]->id, 'transactions' => [$tx[0]->id, $tx[1]->id, $tx[3]->id]]]]);
dc2OneWinner($results);
if (BankStatementMatch::whereNotNull('active_transaction_id')->count() !== 3 || $statement->rows()->where('status', 'reconciled')->count() !== 1) {
    throw new RuntimeException('Split race acquired duplicate or partial ownership.');
}
$evidence['cases']['bank_split_exclusive_ownership'] = $results;

$user = dc2Context();
$customer = Customer::create(['name' => 'DC2 allocation race customer', 'current_debt' => 0, 'current_credit' => 0]);
$debts = app(CustomerDebtService::class);
$debt = $debts->addDebt($customer, ['amount' => 10000, 'transaction_date' => '2026-09-01', 'due_date' => '2026-09-30', 'idempotency_key' => 'race-debt']);
$payment = $debts->recordPayment($customer, ['amount' => 5000, 'transaction_date' => '2026-09-15', 'payment_method' => 'cash', 'idempotency_key' => 'race-customer-payment']);
$before = JournalEntry::count();
$input = ['allocation_date' => '2026-09-16', 'reason' => 'Concurrent reviewed customer remittance.', 'allocations' => [['debt_transaction_id' => $debt->id, 'amount' => 4000]]];
$results = dc2Race($user, [['customer', ['payment' => $payment->id, 'input' => [...$input, 'idempotency_key' => 'race-customer-one']]], ['customer', ['payment' => $payment->id, 'input' => [...$input, 'idempotency_key' => 'race-customer-two']]]]);
dc2OneWinner($results);
if ((float) DB::table('customer_settlement_allocations')->where('company_id', $user->company_id)->sum('amount') !== 4000.0 || JournalEntry::count() !== $before || $customer->fresh()->current_debt !== '5000.00') {
    throw new RuntimeException('Customer allocation over-consumed a payment or posted money.');
}
$evidence['cases']['customer_payment_capacity'] = $results;

$user = dc2Context();
$supplier = Supplier::create(['name' => 'DC2 supplier race']);
$po = app(PurchaseOrderService::class)->create(['supplier_id' => $supplier->id, 'currency' => 'EUR', 'status' => 'ordered', 'ordered_at' => '2026-09-01', 'items' => [['description' => 'Service commitment', 'unit' => 'service', 'quantity' => 1, 'unit_price' => 2000]]]);
app(PurchaseOrderService::class)->pay($po, ['amount' => 1000, 'payment_date' => '2026-09-10', 'payment_method' => 'cash', 'idempotency_key' => 'race-supplier-payment']);
$payment = PurchaseOrderPayment::where('purchase_order_id', $po->id)->firstOrFail();
$bill = app(ExpenseService::class)->create(['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'vendor_name' => $supplier->name, 'vendor_business_number' => '811111111', 'document_type' => 'purchase_invoice', 'document_number' => 'RACE-BILL', 'source_type' => 'domestic', 'category' => 'professional_services', 'asset_treatment' => 'ordinary', 'business_purpose' => 'Concurrency test service', 'invoice_date' => '2026-09-12', 'received_date' => '2026-09-12', 'currency' => 'EUR', 'net_amount' => 1500, 'vat_amount' => 0, 'vat_rate' => 0, 'vat_treatment' => 'non_vat', 'input_vat_eligible' => false]);
app(ExpenseService::class)->post($bill);
$results = dc2Race($user, [['supplier', ['payment' => $payment->id, 'expense' => $bill->id, 'key' => 'race-supplier-one']], ['supplier', ['payment' => $payment->id, 'expense' => $bill->id, 'key' => 'race-supplier-two']]]);
dc2OneWinner($results);
if ($payment->fresh()->allocated_amount !== '600.00' || ! app(AccountingService::class)->reconciliation(false)['all_reconciled']) {
    throw new RuntimeException('Supplier allocation over-consumed its advance or differs from GL.');
}
$evidence['cases']['supplier_advance_capacity'] = $results;

$user = dc2Context();
$period = app(AccountingService::class)->savePeriod(['name' => 'DC2 close race', 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30']);
$journal = ['posting_date' => '2026-09-15', 'description' => 'Atomic close/post race adjustment', 'source_module' => 'manual', 'source_key' => 'close-race-'.$user->company_id, 'currency' => 'EUR', 'lines' => [['accounting_account_id' => AccountingAccount::where('code', '6000')->firstOrFail()->id, 'debit' => 100], ['accounting_account_id' => AccountingAccount::where('code', '3000')->firstOrFail()->id, 'credit' => 100]]];
$results = dc2Race($user, [['close', ['period' => $period->id]], ['journal', ['journal' => $journal]]]);
$period = $period->fresh();
$posted = JournalEntry::where('source_key', $journal['source_key'])->first();
if ($period->status !== 'closed' || ($posted && ($posted->status !== 'posted' || $posted->id > ($period->close_snapshot['journal_watermark'] ?? 0)))) {
    throw new RuntimeException('Period close/posting did not serialize: '.json_encode(['results' => $results, 'period_status' => $period->status, 'journal_status' => $posted?->status, 'journal_id' => $posted?->id, 'proof_watermark' => $period->close_snapshot['journal_watermark'] ?? null]));
}
$evidence['cases']['period_close_and_posting'] = $results;

$user = dc2Context();
$bank = dc2Bank();
$tx = dc2Movement($bank, 75, 'completion-original');
$service = app(BankReconciliationService::class);
$statement = $service->import($bank, UploadedFile::fake()->createWithContent('completion.csv', "Date,Amount,Balance\n2026-09-15,75.00,75.00\n"), ['date' => 'Date', 'amount' => 'Amount', 'balance' => 'Balance']);
$service->reconcile($statement->rows->sole(), $tx);
$results = dc2Race($user, [['complete', ['statement' => $statement->id]], ['bank_post', ['account' => $bank->id, 'movement' => ['type' => 'inflow', 'amount' => 10, 'transaction_date' => '2026-09-15', 'counter_accounting_account_id' => AccountingAccount::where('code', '3000')->firstOrFail()->id, 'idempotency_key' => 'completion-contender']]]]);
dc2OneWinner($results);
if ($statement->fresh()->reconciliation_status === 'completed' && $bank->transactions()->where('status','posted')->count() !== 1) {
    throw new RuntimeException('A completed bank proof was invalidated by concurrent backdated activity.');
}
$evidence['cases']['bank_completion_and_posting'] = $results;
echo json_encode($evidence,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
echo "PASS: split ownership, customer/supplier capacities, period close and bank completion remain serialized across independent MariaDB processes.\n";
