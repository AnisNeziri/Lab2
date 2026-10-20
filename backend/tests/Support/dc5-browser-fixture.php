<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OrderIntake;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\OutboundService;
use App\Services\PurchaseOrderService;
use App\Services\StockMovementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('e2e') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'dc5-preview.sqlite') {
    throw new RuntimeException('DC5 fixture requires the isolated dc5-preview.sqlite database.');
}
DB::beginTransaction();
$company = Company::factory()->create(['name' => 'DC5 Synthetic Inventory', 'base_currency' => 'EUR', 'timezone' => 'Europe/Budapest']);
$owner = User::updateOrCreate(['email' => 'dc5-owner@e2e.example'], ['name' => 'DC5 Warehouse Reviewer', 'password' => bcrypt('dc5-local-test-secret'), 'role' => 'admin', 'company_id' => $company->id,
    'must_change_password' => false, 'is_active' => true, 'email_verified_at' => now()]);
Auth::setUser($owner);
(new RolePermissionSeeder)->run(true);
app(AccountingService::class)->initialize();
$category = Category::create(['name' => 'DC5 Upholstery']);
$supplier = Supplier::create(['name' => 'DC5 Textiles']);
$warehouse = Warehouse::create(['name' => 'Ferizaj', 'code' => 'FER', 'is_active' => true, 'is_default' => true]);
$product = Product::create(['name' => 'Milano 04', 'sku' => 'DC5-M04', 'unit' => 'm', 'quantity' => 0, 'category_id' => $category->id, 'supplier_id' => $supplier->id,
    'default_warehouse_id' => $warehouse->id, 'price' => 5, 'selling_price' => 5, 'purchase_price' => 2, 'min_quantity' => 500]);
app(StockMovementService::class)->store(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'in', 'quantity' => 5000, 'stock_state' => 'available',
    'movement_code' => 'import_adjustment_in', 'reason' => 'Synthetic DC5 starting stock', 'idempotency_key' => 'dc5-opening-'.$company->id]);
$customer = Customer::create(['name' => 'DC5 Furniture', 'is_active' => true]);
$orders = [];
$service = app(OutboundService::class);
foreach ([2000, 4000] as $i => $quantity) {
    $order = $service->create(['customer_id' => $customer->id, 'order_date' => today()->toDateString(), 'payment_type' => 'cash', 'idempotency_key' => 'dc5-order-'.$i,
        'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit' => 'm', 'unit_price' => 5]]]);
    $service->action($order, 'confirm', ['idempotency_key' => 'dc5-confirm-'.$i]);
    $service->action($order, 'reserve', ['idempotency_key' => 'dc5-reserve-'.$i]);
    $orders[] = $order;
}
$po = app(PurchaseOrderService::class)->create(['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'ordered_at' => today()->toDateString(),
    'expected_at' => today()->addDays(8)->toDateString(), 'status' => 'ordered', 'currency' => 'EUR', 'items' => [['product_id' => $product->id, 'description' => 'Milano 04', 'unit' => 'm', 'quantity' => 8000, 'unit_price' => 2]]]);
$shipment = Shipment::create(['tracking_number' => 'SH-2048', 'status' => 'in_transit', 'eta' => today()->addDays(8), 'purchase_order_id' => $po->id, 'warehouse_id' => $warehouse->id, 'transport_mode' => 'sea']);
$cargo = ShipmentItem::create(['shipment_id' => $shipment->id, 'purchase_order_item_id' => $po->items->first()->id, 'product_id' => $product->id, 'description' => 'Milano 04',
    'unit' => 'm', 'quantity' => 8000, 'planned_quantity' => 8000, 'loaded_quantity' => 8000, 'base_quantity' => 8000]);
DB::commit();
echo json_encode(['product' => $product->id, 'warehouse' => $warehouse->id, 'order' => OrderIntake::where('sales_order_id', $orders[1]->id)->value('id'),
    'sales_order' => $orders[1]->id, 'po' => $po->id, 'po_item' => $po->items->first()->id, 'cargo' => $cargo->id], JSON_PRETTY_PRINT)."\n";
