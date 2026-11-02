<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'dc6-upgrade.sqlite' || Schema::hasColumn('companies', 'automation_settings')) {
    throw new RuntimeException('Requires an isolated populated pre-DC6 clone.');
}
$rows = fn ($table, $columns) => DB::table($table)->select($columns)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
$before = [];
foreach (Schema::getTableListing() as $table) {
    $name = last(explode('.', $table));
    if ($name === 'migrations' || str_starts_with($name, 'sqlite_')) {
        continue;
    }$columns = Schema::getColumnListing($table);
    $before[$table] = ['columns' => $columns, 'rows' => $rows($table, $columns)];
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
foreach ($before as $table => $record) {
    if (array_diff($record['rows'], $rows($table, $record['columns']))) {
        throw new RuntimeException('Pre-existing rows changed in '.$table);
    }
}
echo json_encode(['result' => 'PASS', 'tables_preserved' => count($before), 'existing_rows_preserved' => array_sum(array_map(fn ($r) => count($r['rows']), $before)), 'new_company_controls' => Schema::hasColumn('companies', 'automation_settings'), 'migrate_fresh_used' => false], JSON_PRETTY_PRINT)."\n";
