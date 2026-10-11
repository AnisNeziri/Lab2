<?php

require __DIR__.'/guards.php';
performanceErrors();
$path = realpath($argv[1] ?? '');
if (! $path || is_link($argv[1]) || ! str_starts_with(strtolower($path), strtolower(realpath(dirname(__DIR__, 2).'/output').DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Owned profile required.');
}
$report = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$budgets = ['Dashboard' => [30, 150000], 'Product reference picker' => [20, 70000], 'Stock movements' => [20, 200000], 'Finance page overview' => [20, 10000], 'Customer page overview' => [25, 10000], 'Action Center' => [100, 200000]];
$failed = [];
foreach ($budgets as $name => [$queries,$bytes]) {
    $row = $report['timings'][$name] ?? null;
    if (! $row || $row['query_count'] > $queries || $row['bytes'] > $bytes || $row['median_ms'] > 5000) {
        $failed[] = $name;
    }
}
echo json_encode(['status' => $failed ? 'FAIL' : 'PASS', 'failed' => $failed, 'qualification' => 'Tolerant 5-second CI guard plus bounded query/payload contracts; these are not production latency targets.']).PHP_EOL;
exit($failed ? 1 : 0);
