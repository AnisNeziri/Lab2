<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{Artisan, DB, Schema};

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'dc5-upgrade.sqlite') {
    throw new RuntimeException('Requires the isolated pre-DC5 upgrade clone.');
}
$hash = static function (string $table, array $columns): string {
    $rows = DB::table($table)->select($columns)->get()->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->all();
    return hash('sha256', implode("\n", $rows));
};
$before = [];
foreach (Schema::getTableListing() as $table) {
    $parts = explode('.', $table);
    $name = end($parts);
    if ($name === 'migrations' || str_starts_with($name, 'sqlite_')) continue;
    $columns = Schema::getColumnListing($table);
    $before[$table] = ['columns' => $columns, 'hash' => $hash($table, $columns), 'rows' => DB::table($table)->count()];
}
if (Schema::hasColumn('goods_receipt_items', 'shipment_item_id')) throw new RuntimeException('Upgrade clone already has DC5 schema.');
if (Artisan::call('migrate', ['--force' => true]) !== 0) throw new RuntimeException(Artisan::output());
foreach ($before as $table => $evidence) {
    if ($hash($table, $evidence['columns']) !== $evidence['hash']) throw new RuntimeException('Existing data changed in '.$table);
}
echo json_encode(['result' => 'PASS', 'tables_preserved' => count($before), 'rows_preserved' => array_sum(array_column($before, 'rows')),
    'receipt_link_added' => Schema::hasColumn('goods_receipt_items', 'shipment_item_id'),
    'reservation_runs_added' => Schema::hasTable('inventory_reservation_runs'), 'migrate_fresh_used' => false], JSON_PRETTY_PRINT)."\n";
