<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap.php';

$token = app(JwtService::class)->createAccessToken($owner);
function fixtureRequest(string $method, string $path, array $data = []): array
{
    global $token;
    Auth::forgetUser();
    $r = Request::create('/api'.$path, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], json_encode($data));
    $kernel = app(Kernel::class);
    $response = $kernel->handle($r);
    $body = json_decode($response->getContent(), true);
    $kernel->terminate($r, $response);
    if ($response->getStatusCode() >= 300) {
        throw new RuntimeException('Fixture API '.$method.' '.$path.' failed '.$response->getStatusCode().': '.json_encode($body));
    }

    return $body;
}
$run = (string) Str::uuid();
$date = gmdate('Y-m-d');
$warehouse = fixtureRequest('POST', '/warehouses', ['name' => 'PR2 integrity '.$run, 'code' => 'P'.substr(str_replace('-', '', $run), 0, 14), 'is_default' => false]);
$parent = null;
foreach (['zone', 'rack', 'shelf', 'bin'] as $type) {
    $bin = fixtureRequest('POST', '/warehouse-locations', ['warehouse_id' => $warehouse['id'], 'parent_id' => $parent, 'type' => $type, 'code' => strtoupper(substr($type, 0, 1)), 'name' => 'PR2 '.$type, 'is_active' => true]);
    $parent = $bin['id'];
}
$product = fixtureRequest('POST', '/products', ['name' => 'PR2 integrity '.$run, 'sku' => 'PR2-'.$run, 'category_id' => DB::table('categories')->where('company_id', $owner->company_id)->value('id'), 'supplier_id' => DB::table('suppliers')->where('company_id', $owner->company_id)->value('id'), 'default_warehouse_id' => $warehouse['id'], 'unit' => 'pcs', 'quantity' => 0, 'min_quantity' => 1, 'purchase_price' => 2, 'selling_price' => 5, 'price' => 5]);
fixtureRequest('POST', '/stock-movements', ['product_id' => $product['id'], 'warehouse_id' => $warehouse['id'], 'location_id' => $bin['id'], 'type' => 'in', 'quantity' => 100, 'reason' => 'PR2 authoritative opening', 'idempotency_key' => (string) Str::uuid()]);
$product['quantity'] = 100;
Auth::setUser($owner);
$customer = Customer::create(['name' => 'PR2 integrity buyer '.$run, 'current_debt' => 0, 'current_credit' => 0]);
$location = $bin['id'];
$po = fixtureRequest('POST', '/purchase-orders', ['supplier_id' => DB::table('suppliers')->where('company_id', $owner->company_id)->value('id'), 'warehouse_id' => $warehouse['id'], 'currency' => 'EUR', 'ordered_at' => $date, 'status' => 'ordered', 'items' => [['product_id' => $product['id'], 'description' => 'PR2 integrity receipt', 'quantity' => 10, 'unit' => 'pcs', 'unit_price' => 2]]]);
Auth::setUser($owner);
$actors = User::where('company_id', $owner->company_id)->where('email', 'like', 'actor%@performance.aims.test')->orderBy('id')->get();
$sessions = $actors->take(25)->map(fn ($u) => ['id' => $u->id, 'role' => $u->role, 'token' => app(JwtService::class)->createAccessToken($u)])->all();
$writers = $actors->where('role', 'admin')->take(25)->map(fn ($u) => ['id' => $u->id, 'token' => app(JwtService::class)->createAccessToken($u)])->values()->all();
$foreign = User::create(['company_id' => DB::table('companies')->where('name', 'PR2 Second Tenant / TEST DATA')->value('id'), 'name' => 'PR2 foreign actor', 'email' => $run.'@foreign.performance.test', 'password' => 'test-only-unused', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
$state = ['run' => $run, 'engine' => $engine, 'company_id' => $owner->company_id, 'product_id' => $product['id'], 'warehouse_id' => $warehouse['id'], 'location_id' => $location, 'customer_id' => $customer->id, 'po_id' => $po['id'], 'po_item_id' => $po['items'][0]['id'], 'before_quantity' => (float) $product['quantity'], 'stock_key' => (string) Str::uuid(), 'unique_stock_keys' => array_map(fn () => (string) Str::uuid(), range(1, 25)), 'payment_key' => 'pr2-payment-'.$run, 'order_key' => 'pr2-order-'.$run, 'receipt_key' => (string) Str::uuid(), 'po_payment_key' => 'pr2-po-payment-'.$run, 'date' => $date];
$statePath = $root.'/output/pr2-integrity-'.$run.'.json';
file_put_contents($statePath, json_encode($state));
echo json_encode(['environment' => $environment, 'sessions' => $sessions, 'writers' => $writers, 'owner_token' => $token, 'foreign_token' => app(JwtService::class)->createAccessToken($foreign), 'state' => $state, 'state_path' => $statePath, 'product_detail_id' => DB::table('products')->where('company_id', $owner->company_id)->where('sku','PERF-0')->value('id'), 'shipment_id' => DB::table('shipments')->where('company_id',$owner->company_id)->value('id')]);
