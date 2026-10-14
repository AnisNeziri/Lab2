<?php

use App\Services\JwtService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
$reportPath = $argv[2] ?? '';
$reportDir = realpath(dirname($reportPath));
if (! $reportDir || ! str_starts_with($reportDir.DIRECTORY_SEPARATOR, $owned.DIRECTORY_SEPARATOR) || is_link($reportPath)) {
    throw new RuntimeException('Report under owned output required.');
}
$token = app(JwtService::class)->createAccessToken($owner);
$company = $owner->company_id;

$id = fn ($t) => DB::table($t)->where('company_id', $company)->value('id');
$optimizerId = DB::table('supply_optimization_plans')->where('company_id', $company)->where('status', 'OPTIMAL')->whereNotNull('result')->orderBy('id')->value('id');
$simulationId = DB::table('strategic_simulations')->where('company_id', $company)->where('status', 'COMPLETED')->whereNotNull('result')->orderBy('id')->value('id');
$routes = [
    'Dashboard' => ['GET', '/api/dashboard'], 'Product reference picker' => ['GET', '/api/products?reference_only=1&per_page=50'], 'Order Hub' => ['GET', '/api/order-hub'], 'Product detail' => ['GET', '/api/products/'.$id('products')],
    'Inventory / Stock' => ['GET', '/api/inventory/locator'], 'Stock movements' => ['GET', '/api/stock-movements?page=1&per_page=50'], 'Customer debt' => ['GET', '/api/customers'],
    'Purchase Orders' => ['GET', '/api/purchase-orders'], 'Goods Receipts' => ['GET', '/api/goods-receipts'], 'Shipments' => ['GET', '/api/shipments?page=1&per_page=50'],
    'Financial Intelligence' => ['GET', '/api/financial-intelligence'], 'Finance page overview' => ['GET', '/api/financial-intelligence?view=overview'], 'Finance receivables page' => ['GET', '/api/financial-intelligence?view=receivables&page=2'], 'Financial dashboard summary' => ['GET', '/api/financial-intelligence?summary_only=1'],
    'Customer/Sales Intelligence' => ['GET', '/api/customer-sales-intelligence'], 'Customer page overview' => ['GET', '/api/customer-sales-intelligence?view=overview'], 'Customer trends page' => ['GET', '/api/customer-sales-intelligence?view=trends&page=2'], 'Customer dashboard summary' => ['GET', '/api/customer-sales-intelligence?summary_only=1'],
    'Inventory Intelligence' => ['GET', '/api/analytics/intelligence'], 'Supply Optimizer list' => ['GET', '/api/supply-optimizer'],
    'Supply Optimizer detail' => ['GET', '/api/supply-optimizer/'.$optimizerId], 'Strategic Simulation list' => ['GET', '/api/strategic-simulation'],
    'Strategic Simulation detail' => ['GET', '/api/strategic-simulation/'.$simulationId], 'Global Search' => ['GET', '/api/search?q=Milano'], 'Global Search positive' => ['GET', '/api/search?q=Performance'], 'Global Search exact order' => ['GET', '/api/search?q=PERF-SO-4000'],
    'Ask AIMS' => ['POST', '/api/intelligence-assistant/ask', ['question' => 'Who owes us the most?']], 'Action Center' => ['GET', '/api/action-center']];
$queries = [];
DB::listen(function ($q) use (&$queries) {
    $queries[] = ['sql' => $q->sql, 'ms' => $q->time, 'bindings' => $q->bindings];
});
$report = ['method' => 'Authenticated in-process Laravel kernel; one cold and three warm samples. Excludes process bootstrap/network/browser. No SLA or predictive validity claim.', 'engine' => $engine, 'counts' => [], 'timings' => [], 'status' => 'PASS'];
foreach (['products', 'customers', 'suppliers', 'warehouses', 'sales_orders', 'daily_sales', 'stock_movements', 'customer_debt_transactions', 'purchase_orders', 'goods_receipts', 'shipments', 'journal_entries'] as $t) {
    $report['counts'][$t] = DB::table($t)->where('company_id', $company)->count();
}
foreach ($routes as $label => [$method,$route]) {
    if (in_array($label, ['Supply Optimizer detail', 'Strategic Simulation detail'], true) && str_ends_with($route, '/')) {
        $report['timings'][$label] = ['state' => 'NOT_TESTED', 'reason' => 'No completed heavy-job fixture; index response is not a detail measurement.'];

        continue;
    }
    $samples = [];
    $last = [];
    for ($n = 0; $n < 4; $n++) {
        Auth::forgetUser();
        $queries = [];
        $r = Request::create($route, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], json_encode($routes[$label][2] ?? []));
        $kernel = app(Kernel::class);
        $start = hrtime(true);
        $response = $kernel->handle($r);
        $body = $response->getContent();
        $samples[] = ['ms' => round((hrtime(true) - $start) / 1e6, 2), 'bytes' => strlen($body), 'queries' => count($queries), 'sql_ms' => round(array_sum(array_column($queries, 'ms')), 2), 'status' => $response->getStatusCode()];
        $last = $queries;
        $kernel->terminate($r, $response);
    }
    $times = array_column(array_slice($samples, 1), 'ms');
    sort($times);
    $duplicates = array_count_values(array_column($last, 'sql'));
    arsort($duplicates);
    $plans = [];
    foreach ($last as $q) {
        if (stripos(ltrim($q['sql']), 'select') !== 0) {
            continue;
        }try {
            $plans[] = ['sql' => $q['sql'], 'ms' => $q['ms'], 'plan' => DB::select(($engine === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').$q['sql'], $q['bindings'])];
        } catch (Throwable) {
        }
    }
    $report['timings'][$label] = ['route' => $route, 'samples' => $samples, 'median_ms' => $times[1], 'bytes' => $samples[3]['bytes'], 'query_count' => $samples[3]['queries'], 'repeated_sql' => array_slice($duplicates, 0, 8, true), 'plans' => $plans];
    if ($samples[3]['status'] !== 200) {
        $report['status'] = 'FAIL';
    }echo $label.': '.json_encode($samples[3]).PHP_EOL;
}
$report['peak_memory_bytes'] = memory_get_peak_usage(true);
$report['recorded_at'] = gmdate('c');
file_put_contents($reportPath, json_encode($report,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
exit($report['status'] === 'PASS' ? 0 : 1);
