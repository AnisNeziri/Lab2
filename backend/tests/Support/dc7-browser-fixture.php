<?php

use App\Models\AnalyticsSnapshot;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OperationalTask;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\AutomationService;
use App\Services\Platform\BusinessSearchIndexer;
use App\Services\Platform\SearchEntityRegistry;
use App\Services\StockMovementService;
use App\Support\CompanyClock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('e2e') || config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'dc7-preview.sqlite') {
    throw new RuntimeException('Requires isolated DC7 browser database.');
}
$company = Company::factory()->create(['name' => 'DC7 Synthetic Operations', 'base_currency' => 'EUR', 'timezone' => 'Europe/Budapest']);
$owner = User::factory()->create(['email' => 'dc7-owner-'.$company->id.'@e2e.example', 'name' => 'DC7 Purchasing Reviewer', 'password' => bcrypt('dc7-local-test-secret'),
    'role' => 'admin', 'company_id' => $company->id, 'must_change_password' => false, 'is_active' => true, 'email_verified_at' => now()]);
Auth::setUser($owner);
(new RolePermissionSeeder)->run(true);
app(AccountingService::class)->initialize();
$category = Category::create(['name' => 'DC7 Fabric']);
$supplier = Supplier::create(['name' => 'DC7 Textiles', 'is_active' => true]);
$p = Product::create(['name' => 'Milano 04', 'sku' => 'DC7-M04', 'unit' => 'm', 'quantity' => 0, 'category_id' => $category->id, 'supplier_id' => $supplier->id,
    'price' => 5, 'selling_price' => 5, 'purchase_price' => 2, 'min_quantity' => 2000]);
$p->forceFill(['created_at' => now()->subYear()])->save();
ProductSupplier::create(['product_id' => $p->id, 'supplier_id' => $supplier->id, 'purchase_price' => 2, 'currency' => 'EUR', 'exchange_rate_to_base' => 1, 'usual_lead_time_days' => 3, 'pack_size' => 50, 'minimum_order_quantity' => 100, 'is_active' => true]);
for ($d = 1; $d <= 90; $d++) {
    AnalyticsSnapshot::create(['entity_type' => 'demand_observation', 'entity_id' => $p->id, 'warehouse_id' => 0, 'snapshot_date' => today()->subDays($d),
        'observed_at' => today()->subDays($d)->endOfDay(), 'feature_version' => 'observed-v2', 'facts' => ['unit' => 'm', 'demand' => 100, 'sales_quantity' => 100, 'returns_quantity' => 0, 'available' => 5000, 'stockout' => false, 'quality' => 'observed', 'complete' => true, 'potentially_censored' => false]]);
}
$stock = app(StockMovementService::class);
$stock->store(['product_id' => $p->id, 'type' => 'in', 'quantity' => 5000, 'movement_code' => 'import_adjustment_in', 'reason' => 'Synthetic DC7 opening', 'idempotency_key' => 'dc7-start-'.$company->id]);
$rule = app(AutomationService::class)->save(['name' => 'Low Stock Purchasing Review', 'trigger' => 'inventory.low_stock', 'priority' => 'high', 'conditions' => ['all' => [['field' => 'shortage', 'operator' => 'gt', 'value' => 0]]],
    'actions' => [['type' => 'create_task', 'title' => 'Review Milano replenishment', 'assigned_team' => 'purchasing', 'due_days' => 1, 'escalate_after_days' => 2]]]);
app(AutomationService::class)->toggle($rule, true);
$stock->store(['product_id' => $p->id, 'type' => 'out', 'quantity' => 3550, 'movement_code' => 'import_adjustment_out', 'reason' => 'Synthetic DC7 depletion', 'idempotency_key' => 'dc7-deplete-'.$company->id]);
$task = OperationalTask::where('automation_id', $rule->id)->firstOrFail();

$customer = Customer::create(['name' => 'ABC Furniture', 'current_debt' => 0, 'current_credit' => 0, 'is_active' => true]);
$shipment = Shipment::create(['tracking_number' => 'SHIP-CHINA-1048', 'transport_mode' => 'sea', 'origin_port' => 'Ningbo China', 'destination_port' => 'Durres', 'status' => 'in_transit', 'tracking_provider' => 'manual', 'eta' => CompanyClock::today()->addWeek()]);
$type = DocumentType::create(['name' => 'Customs Declaration']);
$doc = Document::create(['uuid' => (string) Str::uuid(), 'document_type_id' => $type->id, 'title' => 'ABC Milano fabric customs China shipment', 'reference' => 'CUSTOMS-ABC-1048', 'status' => 'active', 'created_by' => $owner->id]);
foreach (SearchEntityRegistry::TYPES as $key => [$model]) {
    foreach ($model::where('company_id', $company->id)->limit(100)->get() as $row) {
        app(BusinessSearchIndexer::class)->index($company->id, $key, $row->id);
    }
}
echo json_encode(['company' => $company->id, 'email' => $owner->email, 'product' => $p->id, 'customer' => $customer->id, 'document' => $doc->id, 'shipment' => $shipment->id], JSON_PRETTY_PRINT)."\n";
