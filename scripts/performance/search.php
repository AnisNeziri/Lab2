<?php

require __DIR__.'/bootstrap.php';
use App\Models\Product;
use App\Services\AssistantEntityResolver;
use Illuminate\Support\Facades\DB;

$company = $owner->company_id;
$report = ['method' => 'Bounded resolver candidates; performance-only SQLite fixture; 1 cold plus 3 warm samples at each scale; no accuracy claim.', 'status' => 'PASS', 'scales' => []];
$category = DB::table('categories')->where('company_id', $company)->value('id');
foreach ([500, 2000, 10000] as $size) {
    $count = DB::table('products')->where('company_id', $company)->count();
    $rows = [];
    for ($i = $count; $i < $size; $i++) {
        $rows[] = ['company_id' => $company, 'category_id' => $category, 'name' => 'Benchmark record '.$i, 'sku' => 'BENCH-'.$i, 'quantity' => 0, 'price' => 1, 'unit' => 'pcs', 'created_at' => now(), 'updated_at' => now()];
        if (count($rows) === 200) {
            DB::table('products')->insert($rows);
            $rows = [];
        }
    }if ($rows) {
        DB::table('products')->insert($rows);
    }
    $count = DB::table('customers')->where('company_id', $company)->count();
    $rows = [];
    for ($i = $count; $i < $size; $i++) {
        $rows[] = ['company_id' => $company, 'name' => 'Benchmark buyer '.$i, 'current_debt' => 0, 'current_credit' => 0, 'created_at' => now(), 'updated_at' => now()];
        if (count($rows) === 200) {
            DB::table('customers')->insert($rows);
            $rows = [];
        }
    }if ($rows) {
        DB::table('customers')->insert($rows);
    }
    $unique = Product::firstOrCreate(['sku' => 'ZZKXZR-'.$size], ['name' => 'ZZKXZR-'.$size, 'quantity' => 0, 'price' => 1, 'unit' => 'pcs']);
    $queries = 0;
    if ($size === 500) {
        DB::listen(function () use (&$queries) {
            $queries++;
        });
    }
    $terms = ['exact_late' => ['BENCH-'.($size - 1), 'product'], 'exact_marker' => ['ZZKXZR-'.$size, 'product'], 'fuzzy' => ['ZZKZXR-'.$size, 'product'], 'miss' => ['qqxvvzzqqxvv', 'product'], 'customer_exact' => ['Benchmark buyer '.($size - 1), 'customer']];
    if ($size === 500) {
        $terms['exact_late'] = ['PERF-499', 'product'];
        $terms['customer_exact'] = ['Performance Customer 499', 'customer'];
    }
    $report['entity_counts'][$size] = ['products' => DB::table('products')->where('company_id', $company)->count(), 'customers' => DB::table('customers')->where('company_id', $company)->count()];
    foreach ($terms as $label => [$term,$type]) {
        $samples = [];
        $result = [];
        for ($n = 0; $n < 4; $n++) {
            $queries = 0;
            $start = hrtime(true);
            $result = app(AssistantEntityResolver::class)->resolve($term, $type);
            $samples[] = ['ms' => round((hrtime(true) - $start) / 1e6, 2), 'queries' => $queries, 'bytes' => strlen(json_encode($result))];
        }
        $times = array_column(array_slice($samples, 1), 'ms');
        sort($times);
        $report['scales'][$size][$label] = ['median_ms' => $times[1], 'samples' => $samples, 'resolved' => (bool) $result['entity'], 'choices' => count($result['choices']), 'fuzzy' => $result['fuzzy']];
        if (count($result['choices']) > 6 || str_starts_with($label, 'exact_') && ! $result['entity']) {
            $report['status'] = 'FAIL';
        }
    }
}
$report['peak_memory_bytes'] = memory_get_peak_usage(true);
file_put_contents($root.'/output/pr2-resolver-scale.json', json_encode($report, JSON_PRETTY_PRINT));
echo json_encode($report);
exit($report['status'] === 'PASS' ? 0 : 1);
