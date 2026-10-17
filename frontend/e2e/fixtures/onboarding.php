<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../../backend/vendor/autoload.php';
$app = require __DIR__.'/../../../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'e2e.sqlite' || ! app()->environment('e2e')) {
    throw new RuntimeException('Isolated E2E database required.');
}

$user = User::where('email', 'opening-owner@aims.test')->first();
if (($argv[1] ?? '') === 'verify') {
    if (! $user) {
        throw new RuntimeException('Fixture absent.');
    }echo json_encode(collect(['products', 'customers', 'stock_movements', 'customer_debt_transactions', 'expenses', 'purchase_order_payments', 'journal_entries', 'purchase_orders', 'goods_receipts', 'invoices', 'daily_sales'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('company_id', $user->company_id)->count()])->all());
    exit;
}
if ($user) {
    throw new RuntimeException('Fresh isolated browser database required.');
}
$company = Company::factory()->create(['name' => 'Synthetic DC1 Browser Company', 'base_currency' => 'EUR']);
$user = User::factory()->create(['company_id' => $company->id, 'email' => 'opening-owner@aims.test', 'password' => Hash::make('password'), 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
Auth::setUser($user);
$product = Product::create(['name' => 'Milano 04', 'sku' => 'MIL-04', 'unit' => 'm', 'quantity' => 0, 'price' => 5, 'purchase_price' => 2.5, 'min_quantity' => 20]);
ProductUnit::create(['product_id' => $product->id, 'code' => 'roll', 'factor_to_base' => 50, 'conversion_mode' => 'fixed', 'allow_purchase' => true, 'allow_sale' => true, 'is_active' => true]);
$customer = Customer::create(['name' => 'Reviewed existing customer', 'email' => 'customer@example.test', 'current_debt' => 0, 'current_credit' => 0]);
echo json_encode(['product_id' => $product->id, 'customer_id' => $customer->id]);
