<?php

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventorySnapshotService;
use App\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test')) {
    throw new RuntimeException('Requires a disposable MariaDB test database.');
}
if (($argv[1] ?? '') === 'worker') {
    Auth::login(User::findOrFail((int) $argv[2]));
    while (microtime(true) < (float) $argv[6]) {
        usleep(1000);
    }
    $po = PurchaseOrder::findOrFail((int) $argv[3]);
    try {
        app(PurchaseOrderService::class)->receive($po, ['warehouse_id' => $po->warehouse_id, 'idempotency_key' => $argv[5],
            'items' => [['id' => $po->items->first()->id, 'shipment_item_id' => (int) $argv[4], 'accepted_quantity' => 6]]]);
        echo 'received';
    } catch (ValidationException $e) {
        echo 'rejected';
    }
    exit;
}
(new RolePermissionSeeder)->run();
foreach (['same-key', 'different-keys'] as $mode) {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
    Auth::login($user);
    $supplier = Supplier::create(['name' => 'DC5 race supplier']);
    $warehouse = Warehouse::create(['name' => 'DC5 race warehouse', 'code' => 'RACE', 'is_active' => true]);
    $product = Product::create(['name' => 'DC5 race fabric', 'sku' => 'RACE', 'unit' => 'm', 'quantity' => 0, 'purchase_price' => 2, 'price' => 5]);
    $po = app(PurchaseOrderService::class)->create(['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'ordered_at' => today()->toDateString(), 'status' => 'ordered', 'currency' => 'EUR',
        'items' => [['product_id' => $product->id, 'description' => 'Race fabric', 'unit' => 'm', 'quantity' => 8, 'unit_price' => 2]]]);
    $shipment = Shipment::create(['tracking_number' => 'DC5-RACE', 'status' => 'in_transit', 'purchase_order_id' => $po->id]);
    $cargo = ShipmentItem::create(['shipment_id' => $shipment->id, 'purchase_order_item_id' => $po->items->first()->id, 'product_id' => $product->id, 'description' => 'Fabric', 'unit' => 'm', 'quantity' => 8, 'loaded_quantity' => 8, 'base_quantity' => 8]);
    $workers = [];
    $start = microtime(true) + 1;
    foreach ([0, 1] as $i) {
        $key = $mode === 'same-key' ? 'dc5-race-same' : 'dc5-race-'.$i;
        $worker = new Process([PHP_BINARY, __FILE__, 'worker', (string) $user->id, (string) $po->id, (string) $cargo->id, $key, (string) $start], base_path(), null, null, 60);
        $worker->start();
        $workers[] = $worker;
    }
    $results = [];
    foreach ($workers as $worker) {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException($worker->getErrorOutput().$worker->getOutput());
        } $results[] = $worker->getOutput();
    }
    sort($results);
    $expected = $mode === 'same-key' ? ['received', 'received'] : ['received', 'rejected'];
    $snapshot = app(InventorySnapshotService::class)->forProduct($product->fresh());
    if ($results !== $expected || (float) $product->fresh()->quantity !== 6.0 || GoodsReceipt::where('purchase_order_id', $po->id)->count() !== 1 || $snapshot['in_transit'] !== 2.0) {
        throw new RuntimeException('Concurrent receipt ownership failed.');
    }
    echo "PASS: {$mode}: two scanners, one 6m receipt from 8m cargo, remaining in transit 2m.\n";
}
