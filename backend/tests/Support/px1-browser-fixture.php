<?php

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OrderChannel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\CustomerDebtService;
use App\Services\OrderHubService;
use App\Services\Workforce\PayrollService;
use App\Services\Workforce\WorkforceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Bounded, synthetic UI review data. Never runs against a business database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('e2e') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'px1-preview.sqlite') {
    throw new RuntimeException('PX1 requires the isolated px1-preview.sqlite database.');
}

$owner = User::where('email', 'px1-owner@e2e.example')->first();
if ($owner && in_array('--reuse', $argv, true)) {
    echo "PX1 fixture already exists; preserved.\n";
    exit;
}
DB::beginTransaction();
$company = Company::factory()->create(['name' => 'PX1 Synthetic Trading', 'base_currency' => 'EUR', 'timezone' => 'Europe/Budapest']);
$owner = User::updateOrCreate(['email' => 'px1-owner@e2e.example'], ['name' => 'PX1 Owner', 'password' => bcrypt('px1-local-test-secret'), 'role' => 'admin', 'company_id' => $company->id, 'must_change_password' => false, 'is_active' => true, 'email_verified_at' => now()]);
Auth::setUser($owner);
(new RolePermissionSeeder)->run(true);
foreach (['manager', 'staff', 'hr'] as $role) {
    User::updateOrCreate(['email' => 'px1-'.$role.'@e2e.example'], ['name' => 'PX1 '.ucfirst($role), 'password' => bcrypt('px1-local-test-secret'), 'role' => $role, 'company_id' => $company->id, 'must_change_password' => false, 'is_active' => true, 'email_verified_at' => now()]);
}
app(AccountingService::class)->initialize();
$supplier = Supplier::create(['name' => 'Adriatic Textiles', 'email' => 'supplier@example.test']);
$category = Category::create(['name' => 'Upholstery']);
$warehouse = Warehouse::create(['name' => 'Prishtina Main Warehouse', 'code' => 'PX1', 'is_active' => true]);
$product = Product::create(['name' => 'Milano 04 Fabric', 'sku' => 'PX1-M04', 'unit' => 'm', 'quantity' => 0, 'category_id' => $category->id, 'supplier_id' => $supplier->id, 'price' => 5, 'selling_price' => 5, 'purchase_price' => 2, 'min_quantity' => 100]);
$customer = Customer::create(['name' => 'ABC Furniture', 'email' => 'customer@example.test', 'payment_terms_days' => 30, 'is_active' => true]);
app(CustomerDebtService::class)->addDebt($customer, ['amount' => 3700, 'transaction_date' => today()->subDays(45)->toDateString(), 'due_date' => today()->subDays(15)->toDateString(), 'reference_number' => 'PX1-INV-104', 'note' => 'Synthetic review receivable', 'idempotency_key' => 'px1-debt']);
$channel = OrderChannel::create(['name' => 'Trade desk', 'type' => 'manual', 'enabled' => true, 'currency' => 'EUR', 'oversale_policy' => 'require_review', 'acceptance' => 'review']);
$intake = app(OrderHubService::class)->intake($channel, ['customer_id' => $customer->id, 'external_id' => 'PX1-ORDER-104', 'idempotency_key' => 'px1-order', 'order_date' => today()->toDateString(), 'payment_type' => 'credit', 'items' => [['product_id' => $product->id, 'quantity' => 800, 'unit' => 'm', 'unit_price' => 5]]]);
$po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'po_number' => 'PX1-PO-104', 'status' => 'ordered', 'ordered_at' => today()->subDays(20), 'expected_at' => today()->addDays(4), 'total_amount' => 20000, 'currency' => 'EUR', 'exchange_rate' => 1]);
$item = $po->items()->create(['product_id' => $product->id, 'description' => $product->name, 'unit' => 'm', 'inventory_unit' => 'm', 'quantity' => 10000, 'base_quantity' => 10000, 'unit_price' => 2, 'line_total' => 20000]);
$shipment = Shipment::create(['tracking_number' => 'PX1-SH-104', 'purchase_order_id' => $po->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'transport_mode' => 'sea', 'origin_port' => 'Shanghai', 'destination_port' => 'Durrës', 'departed_at' => today()->subDays(15), 'eta' => today()->addDays(6), 'status' => 'in_transit', 'tracking_provider' => 'manual']);
$shipment->items()->create(['purchase_order_item_id' => $item->id, 'product_id' => $product->id, 'description' => $product->name, 'unit' => 'm', 'quantity' => 10000, 'base_quantity' => 10000]);
$wf = app(WorkforceService::class);
$payroll = app(PayrollService::class);
$payroll->initialize();
$employee = $wf->saveEmployee(['employee_number' => 'PX1-E01', 'name' => 'Arta Example', 'status' => 'active', 'start_date' => '2026-01-01', 'user_id' => User::where('email', 'px1-staff@e2e.example')->value('id')]);
$wf->terms($employee->id, ['effective_from' => '2026-01-01', 'position' => 'Finance Coordinator', 'department' => 'Administration', 'contract_type' => 'permanent', 'pay_basis' => 'monthly', 'amount' => 900, 'proration_policy' => 'calendar_days', 'currency' => 'EUR', 'employer_classification' => 'primary', 'pension_treatment' => 'resident_contributor', 'employee_pension_rate' => 5, 'employer_pension_rate' => 5, 'reason' => 'Synthetic UI review policy']);
$run = $payroll->createPeriod(['name' => 'October 2026', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'pay_date' => '2026-10-31']);
$payroll->calculate($run->id);
DB::commit();
echo json_encode(['company' => $company->id, 'customer' => $customer->id, 'supplier' => $supplier->id, 'order' => $intake->id, 'po' => $po->id, 'shipment' => $shipment->id, 'employee' => $employee->id, 'payroll' => $run->id], JSON_PRETTY_PRINT)."\n";
