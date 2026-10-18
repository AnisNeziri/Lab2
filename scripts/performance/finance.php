<?php

use App\Models\AccountingAccount;
use App\Services\AccountingService;
use App\Services\JwtService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
$reportPath = $argv[2] ?? '';
performanceDirectory(dirname($reportPath));
if (is_link($reportPath)) {
    throw new RuntimeException('Linked report refused.');
}
$company = $owner->company_id;
app(AccountingService::class)->initialize();
if (in_array('--seed-gl', $argv, true)) {
    if (DB::table('journal_entries')->where('company_id', $company)->where('source_key', 'like', 'dc2-perf-%')->exists()) {
        throw new RuntimeException('Preserve the existing finance benchmark population.');
    }
    $expense = AccountingAccount::where('code', '6000')->firstOrFail()->id;
    $equity = AccountingAccount::where('code', '3000')->firstOrFail()->id;
    $first = (int) DB::table('journal_entries')->max('id') + 1;
    for ($offset = 0; $offset < 150000; $offset += 500) {
        $entries = [];
        $lines = [];
        for ($n = $offset; $n < $offset + 500; $n++) {
            $id = $first + $n;
            $entries[] = ['id' => $id, 'company_id' => $company, 'journal_number' => 'DC2-PERF-'.($n + 1), 'posting_date' => '2026-10-01', 'description' => 'Disposable finance scale fixture', 'source_module' => 'manual', 'source_key' => 'dc2-perf-'.($n + 1), 'currency' => 'EUR', 'exchange_rate' => 1, 'total_debit' => 1, 'total_credit' => 1, 'status' => 'posted', 'created_by' => $owner->id, 'posted_by' => $owner->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            foreach ([[$expense, 1, 0], [$equity, 0, 1]] as $i => [$account,$debit,$credit]) {
                $lines[] = ['journal_entry_id' => $id, 'accounting_account_id' => $account, 'line_number' => $i + 1, 'debit' => $debit, 'credit' => $credit, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        DB::transaction(function () use ($entries, $lines) {
            DB::table('journal_entries')->insert($entries);
            DB::table('journal_lines')->insert($lines);
        });
    }
}
$account = AccountingAccount::where('code', '6000')->firstOrFail()->id;
$customer = DB::table('customers')->where('company_id', $company)->value('id');
$period = DB::table('accounting_periods')->where('company_id', $company)->value('id');
if (! $period) $period = app(AccountingService::class)->savePeriod(['name'=>'Disposable DC2 benchmark period','starts_at'=>'2026-10-01','ends_at'=>'2026-10-31'])->id;
$supplier = DB::table('suppliers')->where('company_id',$company)->value('id');
$routes = ['Control Center' => '/api/accounting/control-center?from=2026-10-01&to=2026-10-31', 'Journal page' => '/api/accounting/journals?per_page=50', 'Account activity' => '/api/accounting/accounts/'.$account.'/activity?from=2026-10-01&to=2026-10-31', 'Trial Balance' => '/api/accounting/trial-balance?from=2026-10-01&to=2026-10-31', 'P&L' => '/api/accounting/profit-loss?from=2026-10-01&to=2026-10-31', 'Balance Sheet' => '/api/accounting/balance-sheet?to=2026-10-31', 'Cash Flow' => '/api/accounting/cash-flow?from=2026-10-01&to=2026-10-31', 'AR aging' => '/api/finance/receivables-aging?as_of=2026-10-31', 'AP aging' => '/api/accounting/ap/aging?as_of=2026-10-31', 'Dated reconciliation' => '/api/accounting/reconciliation?to=2026-10-31'];
if ($customer) {
    $routes['Customer statement'] = '/api/accounting/ar/customers/'.$customer.'/statement?from=2026-10-01&to=2026-10-31';
}
if ($supplier) $routes['Supplier statement'] = '/api/accounting/ap/suppliers/'.$supplier.'/statement?from=2026-10-01&to=2026-10-31';
if ($period) {
    $routes['Close readiness'] = '/api/accounting/periods/'.$period.'/readiness';
}
$token = app(JwtService::class)->createAccessToken($owner);
$kernel = app(Kernel::class);
$queries = [];
DB::listen(function ($event) use (&$queries) {
    $queries[] = ['sql' => $event->sql, 'bindings'=>$event->bindings, 'ms' => $event->time];
});
$report = ['engine' => DB::getDriverName(), 'company_id' => $company, 'journals' => DB::table('journal_entries')->where('company_id', $company)->count(), 'journal_lines' => DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.company_id', $company)->count(), 'status' => 'PASS', 'timings' => []];
foreach ($routes as $label => $url) {
    $samples = [];
    for ($sample = 0; $sample < 3; $sample++) {
        Auth::forgetUser();
        $queries = [];
        $start = hrtime(true);
        $request = Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $response = $kernel->handle($request);
        $body = $response->getContent();
        $kernel->terminate($request, $response);
        $samples[] = ['ms' => round((hrtime(true) - $start) / 1e6, 2), 'bytes' => strlen($body), 'queries' => count($queries), 'sql_ms' => round(array_sum(array_column($queries, 'ms')), 2), 'status' => $response->getStatusCode()];
        if ($response->getStatusCode() !== 200) {
            $report['status'] = 'FAIL';
        }
    }
    $slow = collect($queries)->sortByDesc('ms')->take(3)->values()->map(function($query){
        $plan = DB::getDriverName()==='sqlite' ? DB::select('EXPLAIN QUERY PLAN '.$query['sql'], $query['bindings']) : [];
        return ['sql'=>$query['sql'],'ms'=>$query['ms'],'plan'=>$plan];
    })->all();
    $report['timings'][$label] = ['slow_queries'=>$slow, 'route' => $url, 'samples' => $samples, 'summary_bytes' => $samples[2]['bytes'], 'queries' => $samples[2]['queries'], 'ms' => $samples[2]['ms']];
    echo $label.': '.json_encode($samples[2]).PHP_EOL;
}
$report['source_counts'] = collect(['customers','suppliers','expenses','expense_payments','customer_debt_transactions','bank_statement_rows'])->mapWithKeys(fn($t)=>[$t=>DB::table($t)->where('company_id',$company)->count()])->all();
$report['peak_memory_bytes'] = memory_get_peak_usage(true);
$report['qualification'] = 'Disposable query benchmark: 150,000 balanced synthetic journals. Operational/GL differences in the pre-existing performance fixture are not evidence of a reconciled company.';
file_put_contents($reportPath, json_encode($report,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
exit($report['status'] === 'PASS' ? 0 : 1);
