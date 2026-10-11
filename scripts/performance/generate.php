<?php

use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * PR2 fast scale fixture. All inserted records are PERFORMANCE / TEST DATA.
 * Bulk inserts intentionally skip the chronological ML lifecycle; never use for accuracy claims.
 * No caller-supplied DB name/path, no connection to the configured application database.
 */
require __DIR__.'/guards.php';
performanceErrors();
$options = getopt('', ['engine:', 'seed:', 'products:', 'customers:', 'suppliers:', 'warehouses:', 'orders:', 'reset', 'yes']);
if (! isset($options['yes'])) {
    throw new RuntimeException('Explicit --yes required for PERFORMANCE / TEST DATA.');
}
if (getenv('APP_ENV') === 'production') {
    throw new RuntimeException('Performance generation is forbidden in production.');
}
$engine = $options['engine'] ?? 'sqlite';
if (! in_array($engine, ['sqlite', 'mysql'], true)) {
    throw new RuntimeException('SQLite or local MariaDB only.');
}
$limits = ['seed' => [1, 2147483647, 20261011], 'products' => [10, 10000, 1000], 'customers' => [10, 10000, 2000], 'suppliers' => [5, 200, 100], 'warehouses' => [2, 20, 10], 'orders' => [100, 250000, 50000]];
$settings = [];
foreach ($limits as $key => [$min,$max,$default]) {
    $v = filter_var($options[$key] ?? $default, FILTER_VALIDATE_INT);
    if ($v === false || $v < $min || $v > $max) {
        throw new RuntimeException('Invalid bounded volume: '.$key);
    }$settings[$key] = $v;
}
$root = dirname(__DIR__, 2);
$dir = $root.'/output/performance/'.$settings['seed'];
foreach ([$root.'/output', $root.'/output/performance', $dir] as $part) {
    performanceDirectory($part);
}
$port = performancePort();
$marker = 'AIMS_PR2_PERFORMANCE_ONLY';
$reset = isset($options['reset']);
if ($engine === 'sqlite') {
    $database = $dir.'/aims-pr2.sqlite';
    if (is_link($database)) {
        throw new RuntimeException('Symlinked database refused.');
    }
    if (is_file($database)) {
        $p = new PDO('sqlite:'.$database);
        try {
            $owned = $p->query('select marker from _aims_performance_manifest')->fetchColumn();
        } catch (Throwable) {
            $owned = null;
        }
        if ($owned !== $marker) {
            throw new RuntimeException('Refusing to modify an unrecognized database.');
        }$p = null;
        if (! $reset) {
            throw new RuntimeException('Owned fixture exists. Use --reset.');
        }
        foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
            if (is_link($file)) {
                throw new RuntimeException('Symlinked sidecar refused.');
            }if (is_file($file)) {
                unlink($file);
            }
        }
    }
    $p = new PDO('sqlite:'.$database);
} else {
    $database = 'aims_pr2_perf_'.$settings['seed'];
    $p = new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', getenv('AIMS_PERF_MYSQL_USER') ?: 'root', getenv('AIMS_PERF_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $check = $p->prepare('select SCHEMA_NAME from information_schema.SCHEMATA where SCHEMA_NAME=?');
    $check->execute([$database]);
    if ($check->fetchColumn()) {
        try {
            $owned = $p->query('select marker from '.$database.'._aims_performance_manifest')->fetchColumn();
        } catch (Throwable) {
            $owned = null;
        }
        if ($owned !== $marker) {
            throw new RuntimeException('Refusing to modify an unrecognized database.');
        }if (! $reset) {
            throw new RuntimeException('Owned fixture exists. Use --reset.');
        }
        $p->exec('DROP DATABASE '.$database);
    }
    $p->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $p->exec('USE '.$database);
}
$p->exec('create table _aims_performance_manifest (marker varchar(80) not null, status varchar(30) not null, seed integer not null)');
$p->prepare('insert into _aims_performance_manifest values (?,?,?)')->execute([$marker, 'generating', $settings['seed']]);
$p = null;
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'DB_CONNECTION' => $engine, 'DB_DATABASE' => $database, 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port, 'DB_USERNAME' => getenv('AIMS_PERF_MYSQL_USER') ?: 'root', 'DB_PASSWORD' => getenv('AIMS_PERF_MYSQL_PASSWORD') ?: '', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'log', 'REDIS_ENABLED' => 'false', 'TRACKING_EXTERNAL_ENABLED' => 'false', 'APP_CONFIG_CACHE' => $dir.'/unused-config.php', 'LARAVEL_STORAGE_PATH' => $dir.'/storage'] as $k => $v) {
    putenv($k.'='.$v);
    $_ENV[$k] = $_SERVER[$k] = $v;
}
foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private'] as $part) {
    @mkdir($dir.'/storage/'.$part, 0700, true);
}
require $root.'/backend/vendor/autoload.php';
$app = require $root.'/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
performanceErrors();

$start = hrtime(true);
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RolePermissionSeeder', '--force' => true]);
DB::table('users')->where('role', 'superadmin')->update(['is_active' => false]);
$now = gmdate('Y-m-d H:i:s');
$timestamps = ['created_at' => $now, 'updated_at' => $now];
$company = DB::table('companies')->insertGetId(['name' => 'AIMS PR2 PERFORMANCE / TEST DATA', 'address' => 'Disposable load fixture only'] + $timestamps);
$foreign = DB::table('companies')->insertGetId(['name' => 'PR2 Second Tenant / TEST DATA', 'address' => 'Tenant isolation fixture'] + $timestamps);
$password = password_hash('Aims.Performance.2026!', PASSWORD_BCRYPT, ['cost' => 4]);
$actors = [];
for ($i = 0; $i < 50; $i++) {
    $role = $i === 0 ? 'admin' : ($i < 15 ? 'staff' : ($i < 24 ? 'manager' : 'admin'));
    $uid = DB::table('users')->insertGetId(['name' => 'Performance actor '.$i, 'email' => 'actor'.$i.'@performance.aims.test', 'password' => $password, 'role' => $role, 'company_id' => $company, 'email_verified_at' => $now, 'is_active' => true, 'must_change_password' => false] + $timestamps);
    $actors[] = $uid;
    DB::table('user_roles')->insert(['user_id' => $uid, 'role_id' => DB::table('roles')->where('slug', $role)->value('id'), 'assigned_at' => $now]);
}
$category = DB::table('categories')->insertGetId(['company_id' => $company, 'name' => 'PERFORMANCE / TEST DATA'] + $timestamps);
$supplierIds = [];
$warehouseIds = [];
$customerIds = [];
$productIds = [];
for ($i = 0; $i < $settings['suppliers']; $i++) {
    $supplierIds[] = DB::table('suppliers')->insertGetId(['company_id' => $company, 'name' => 'Performance Supplier '.$i, 'is_active' => true] + $timestamps);
}
for ($i = 0; $i < $settings['warehouses']; $i++) {
    $warehouseIds[] = DB::table('warehouses')->insertGetId(['company_id' => $company, 'name' => 'Performance Warehouse '.$i, 'code' => 'PERF-W'.$i, 'is_active' => true, 'is_default' => $i === 0] + $timestamps);
}
for ($i = 0; $i < $settings['customers']; $i++) {
    $customerIds[] = DB::table('customers')->insertGetId(['company_id' => $company, 'name' => 'Performance Customer '.$i, 'email' => 'customer'.$i.'@performance.aims.test', 'current_debt' => 0, 'current_credit' => 0, 'is_active' => true] + $timestamps);
}
for ($i = 0; $i < $settings['products']; $i++) {
    $warehouse = $warehouseIds[$i % count($warehouseIds)];
    $pid = DB::table('products')->insertGetId(['company_id' => $company, 'name' => 'Performance Product '.$i, 'sku' => 'PERF-'.$i, 'category_id' => $category, 'supplier_id' => $supplierIds[$i % count($supplierIds)], 'default_warehouse_id' => $warehouse, 'quantity' => 100, 'price' => 5, 'selling_price' => 5, 'purchase_price' => 3, 'weighted_average_cost' => 3, 'inventory_value' => 300, 'min_quantity' => 10, 'unit' => 'pcs', 'tracking_mode' => 'none'] + $timestamps);
    $productIds[] = $pid;
    DB::table('warehouse_stock')->insert(['company_id' => $company, 'warehouse_id' => $warehouse, 'product_id' => $pid, 'quantity' => 100, 'available_quantity' => 100, 'location_key' => 0] + $timestamps);
    DB::table('stock_movements')->insert(['company_id' => $company, 'product_id' => $pid, 'warehouse_id' => $warehouse, 'type' => 'in', 'quantity' => 100, 'quantity_before' => 0, 'quantity_after' => 100, 'warehouse_quantity_before' => 0, 'warehouse_quantity_after' => 100, 'movement_code' => 'manual_adjustment_in', 'source_type' => 'performance_fixture', 'reason' => 'PERFORMANCE / TEST DATA opening', 'idempotency_key' => 'perf-opening-'.$pid, 'occurred_at' => $now, 'stock_state' => 'available'] + $timestamps);
}
DB::table('products')->insert(['company_id' => $foreign, 'name' => 'FOREIGN TENANT SECRET PERF', 'sku' => 'FOREIGN-PERF', 'quantity' => 0, 'price' => 1] + $timestamps);
$channel = DB::table('order_channels')->insertGetId(['company_id' => $company, 'name' => 'PERFORMANCE Manual', 'type' => 'manual', 'enabled' => true, 'currency' => 'EUR', 'warehouse_id' => $warehouseIds[0]] + $timestamps);
$account = DB::table('accounting_accounts')->where('company_id', $company)->value('id');
if (! $account) {
    Auth::setUser(User::find($actors[0]));
    app(AccountingService::class)->initialize();
    $account = DB::table('accounting_accounts')->where('company_id', $company)->value('id');
}
$tables = ['sales_orders', 'sales_order_items', 'order_intakes', 'daily_sales', 'daily_sale_items', 'stock_movements', 'customer_debt_transactions', 'purchase_orders', 'purchase_order_items', 'goods_receipts', 'goods_receipt_items', 'shipments', 'journal_entries', 'journal_lines'];
$next = [];
foreach ($tables as $t) {
    $next[$t] = (int) DB::table($t)->max('id') + 1;
}
$customerBalances = array_fill_keys($customerIds, 0);
for ($offset = 0; $offset < $settings['orders']; $offset += 250) {
    $batch = [];
    foreach ($tables as $t) {
        $batch[$t] = [];
    }
    $end = min($offset + 250, $settings['orders']);
    for ($i = $offset; $i < $end; $i++) {
        $pid = $productIds[$i % count($productIds)];
        $cid = $customerIds[$i % count($customerIds)];
        $wid = $warehouseIds[($i % count($productIds)) % count($warehouseIds)];
        $sid = $supplierIds[$i % count($supplierIds)];
        $day = gmdate('Y-m-d', strtotime('-'.($i % 365).' days'));
        $stamp = $day.' 12:00:00';
        $ts = ['created_at' => $stamp, 'updated_at' => $stamp];
        $key = 'perf-'.$i;
        $order = $next['sales_orders']++;
        $item = $next['sales_order_items']++;
        $sale = $next['daily_sales']++;
        $batch['sales_orders'][] = ['id' => $order, 'company_id' => $company, 'customer_id' => $cid, 'order_number' => 'PERF-SO-'.$i, 'order_date' => $day, 'currency' => 'EUR', 'status' => 'draft', 'warehouse_id' => $wid, 'total_amount' => 5, 'committed_amount' => 0, 'payment_type' => 'cash', 'idempotency_key' => $key, 'request_fingerprint' => hash('sha256', $key), 'created_by' => $actors[$i % count($actors)]] + $ts;
        $batch['sales_order_items'][] = ['id' => $item, 'company_id' => $company, 'sales_order_id' => $order, 'product_id' => $pid, 'unit' => 'pcs', 'quantity' => 1, 'base_quantity' => 1, 'unit_price' => 5, 'line_total' => 5] + $ts;
        $batch['order_intakes'][] = ['id' => $next['order_intakes']++, 'company_id' => $company, 'order_channel_id' => $channel, 'sales_order_id' => $order, 'external_id' => 'PERF-EXT-'.$i, 'idempotency_key' => $key, 'fingerprint' => hash('sha256', $key), 'state' => 'received', 'payload' => '{"performance_only":true}'] + $ts;
        $batch['daily_sales'][] = ['id' => $sale, 'company_id' => $company, 'customer_id' => $cid, 'customer_name' => 'Performance Customer '.($i % count($customerIds)), 'sale_number' => 'PERF-DS-'.$i, 'sale_date' => $day, 'status' => 'draft', 'total_amount' => 5, 'total_quantity' => 1, 'paid_amount' => 0, 'idempotency_key' => 'perf-sale-'.$i] + $ts;
        $batch['daily_sale_items'][] = ['id' => $next['daily_sale_items']++, 'company_id' => $company, 'daily_sale_id' => $sale, 'line_number' => 1, 'product_id' => $pid, 'product_name' => 'Performance Product '.($i % count($productIds)), 'unit' => 'pcs', 'quantity' => 1, 'base_quantity' => 1, 'unit_price' => 5, 'line_total' => 5, 'unit_cost' => 3, 'cost_total' => 3, 'gross_profit' => 2, 'warehouse_id' => $wid] + $ts;
        foreach (['in', 'out'] as $direction) {
            $batch['stock_movements'][] = ['id' => $next['stock_movements']++, 'company_id' => $company, 'product_id' => $pid, 'warehouse_id' => $wid, 'type' => $direction, 'quantity' => 1, 'quantity_before' => $direction === 'in' ? 100 : 101, 'quantity_after' => $direction === 'in' ? 101 : 100, 'warehouse_quantity_before' => $direction === 'in' ? 100 : 101, 'warehouse_quantity_after' => $direction === 'in' ? 101 : 100, 'movement_code' => $direction === 'in' ? 'manual_adjustment_in' : 'manual_adjustment_out', 'source_type' => 'performance_fixture', 'reason' => 'PERFORMANCE / TEST DATA paired history', 'idempotency_key' => $key.'-'.$direction, 'occurred_at' => $stamp, 'stock_state' => 'available'] + $ts;
        }
        foreach (['debt_added', 'payment'] as $type) {
            $batch['customer_debt_transactions'][] = ['id' => $next['customer_debt_transactions']++, 'company_id' => $company, 'customer_id' => $cid, 'user_id' => $actors[0], 'type' => $type, 'amount' => 5, 'balance_before' => $type === 'debt_added' ? 0 : 5, 'balance_after' => $type === 'debt_added' ? 5 : 0, 'transaction_date' => $day, 'due_date' => $day, 'idempotency_key' => $key.'-'.$type, 'note' => 'PERFORMANCE / TEST DATA paired zero net ledger'] + $ts;
        }
        $journal = $next['journal_entries']++;
        $batch['journal_entries'][] = ['id' => $journal, 'company_id' => $company, 'journal_number' => 'PERF-J-'.$i, 'posting_date' => $day, 'description' => 'PERFORMANCE / TEST DATA draft balanced journal', 'source_module' => 'manual', 'source_key' => $key, 'currency' => 'EUR', 'status' => 'draft', 'total_debit' => 5, 'total_credit' => 5] + $ts;
        foreach ([1, 2] as $line) {
            $batch['journal_lines'][] = ['id' => $next['journal_lines']++, 'journal_entry_id' => $journal, 'accounting_account_id' => $account, 'line_number' => $line, 'debit' => $line === 1 ? 5 : 0, 'credit' => $line === 2 ? 5 : 0] + $ts;
        }
        if ($i % 10 === 0) {
            $po = $next['purchase_orders']++;
            $poi = $next['purchase_order_items']++;
            $receipt = $next['goods_receipts']++;
            $batch['purchase_orders'][] = ['id' => $po, 'company_id' => $company, 'supplier_id' => $sid, 'po_number' => 'PERF-PO-'.$i, 'status' => 'partially_received', 'total_amount' => 30, 'warehouse_id' => $wid, 'currency' => 'EUR', 'due_at' => $day, 'expected_at' => $day] + $ts;
            $batch['purchase_order_items'][] = ['id' => $poi, 'purchase_order_id' => $po, 'product_id' => $pid, 'description' => 'PERFORMANCE / TEST DATA', 'quantity' => 10, 'base_quantity' => 10, 'received_quantity' => 1, 'received_base_quantity' => 1, 'unit' => 'pcs', 'inventory_unit' => 'pcs', 'unit_price' => 3, 'line_total' => 30] + $ts;
            $batch['goods_receipts'][] = ['id' => $receipt, 'company_id' => $company, 'purchase_order_id' => $po, 'warehouse_id' => $wid, 'receipt_number' => 'PERF-GR-'.$i, 'received_at' => $stamp, 'status' => 'posted', 'idempotency_key' => 'perf-receipt-'.$i, 'received_by' => $actors[0]] + $ts;
            $batch['goods_receipt_items'][] = ['id' => $next['goods_receipt_items']++, 'goods_receipt_id' => $receipt, 'purchase_order_item_id' => $poi, 'product_id' => $pid, 'ordered_unit' => 'pcs', 'inventory_unit' => 'pcs', 'accepted_quantity' => 1, 'accepted_base_quantity' => 1] + $ts;
            $batch['shipments'][] = ['id' => $next['shipments']++, 'company_id' => $company, 'purchase_order_id' => $po, 'supplier_id' => $sid, 'warehouse_id' => $wid, 'tracking_number' => 'PERF-SHIP-'.$i, 'vessel_name' => 'PERFORMANCE / TEST DATA', 'status' => 'in_transit', 'transport_mode' => 'road', 'tracking_mode' => 'manual', 'is_saved' => true] + $ts;
        }
    }
    DB::transaction(function () use ($batch) {
        foreach ($batch as $table => $rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    });
    if ($end % 10000 === 0 || $end === $settings['orders']) {
        echo 'Generated '.$end.' / '.$settings['orders'].' performance orders.'.PHP_EOL;
    }
}
Auth::setUser(User::find($actors[0]));
require __DIR__.'/evidence.php';
$readEvidence = addPerformanceEvidence($company);
$counts = [];
foreach (array_merge(['products', 'customers', 'suppliers', 'warehouses'], $tables) as $t) {
    $counts[$t] = DB::table($t)->count();
}
if ($engine === 'sqlite' && DB::select('PRAGMA foreign_key_check')) {
    throw new RuntimeException('Fixture foreign-key check failed.');
}
DB::table('_aims_performance_manifest')->update(['status' => 'complete']);
$report = ['marker' => $marker, 'purpose' => 'PERFORMANCE / TEST DATA; bulk history and drafts; NOT chronological/predictive certification', 'engine' => $engine, 'database' => $database, 'loopback_port' => $engine === 'mysql' ? $port : null, 'company_id' => $company, 'foreign_company_id' => $foreign, 'volumes' => $settings, 'counts' => $counts, 'generation_seconds' => round((hrtime(true) - $start) / 1e9, 2), 'peak_memory_bytes' => memory_get_peak_usage(true), 'recorded_at' => gmdate('c')];
file_put_contents($dir.'/'.$engine.'-manifest.json', json_encode($report,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($report).PHP_EOL;
