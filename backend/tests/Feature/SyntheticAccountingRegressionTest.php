<?php

namespace Tests\Feature;

use App\Models\{AccountingAccount, Category, Customer, DailySale, Invoice, InvoiceProfile, Product, Warehouse};
use App\Services\{AccountingService, CustomerDebtService, FinancialAccountService, InvoiceService, OutboundService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyntheticAccountingRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_fractional_unit_costs_keep_cent_control_equal_to_carried_inventory(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(\App\Models\User::where('company_id', $this->apiCompany->id)->where('role', 'admin')->firstOrFail());
        $category = Category::create(['name' => 'Rounding regression']);
        $product = Product::create(['name' => 'Weighted cost fixture', 'sku' => 'ROUND-1', 'unit' => 'pcs', 'quantity' => 3, 'price' => 5, 'category_id' => $category->id, 'weighted_average_cost' => '3.333333', 'inventory_value' => '10.000000']);
        $accounting = app(AccountingService::class);
        $accounting->initialize();
        $accounting->postMapped('inventory', 'product', $product->id, 'rounding-opening', today()->toDateString(), 'Rounding fixture opening', [['mapping' => 'inventory', 'debit' => 10], ['mapping' => 'equity', 'credit' => 10]]);
        for ($i = 0; $i < 3; $i++) {
            $before = (float) $product->quantity;
            $snapshot = app(\App\Services\InventoryCostingService::class)->movementSnapshot($product, 'out', 1, $before, $before - 1, true);
            $movement = \App\Models\StockMovement::create(['product_id' => $product->id, 'type' => 'out', 'quantity' => 1, 'quantity_before' => $before, 'quantity_after' => $before - 1, 'affects_company_quantity' => true, 'movement_code' => 'daily_sale', 'unit_snapshot' => 'pcs', 'occurred_at' => now(), 'cost_total' => $snapshot['cost_total'], 'inventory_value_before' => $snapshot['inventory_value_before'], 'inventory_value_after' => $snapshot['inventory_value_after']]);
            $product->forceFill(['quantity' => $before - 1, 'inventory_value' => $snapshot['product_inventory_value'], 'weighted_average_cost' => $snapshot['product_weighted_average_cost']])->save();
            app(\App\Services\OperationalAccountingService::class)->postStockMovement($movement);
            $row = $accounting->reconciliation(false)['rows']['inventory'];
            $this->assertTrue($row['reconciled'], json_encode($row));
        }
        $this->assertSame('0.000000', $product->fresh()->inventory_value);
        $this->assertTrue(\App\Models\JournalLine::where('description', 'Inventory valuation rounding carry')->exists());
    }

    public function test_credit_fulfillment_document_and_bank_deposit_reconcile_without_duplicate_sales(): void
    {
        $this->actingAsApiUser('admin');
        $warehouse = $this->postJson('/api/warehouses', ['name' => 'Dispatch', 'code' => 'REG', 'is_default' => true])->assertCreated()->json();
        $category = Category::create(['name' => 'Regression goods']);
        $product = $this->postJson('/api/products', ['name' => 'Handles', 'sku' => 'PM3-REG', 'unit' => 'pcs', 'quantity' => 100, 'min_quantity' => 5, 'price' => 10, 'selling_price' => 10, 'purchase_price' => 2, 'category_id' => $category->id])->assertCreated()->json();
        $customer = Customer::create(['name' => 'Synthetic Buyer', 'business_name' => 'Synthetic Buyer', 'customer_type' => 'business', 'business_registration_number' => 'TEST-NUI', 'billing_address' => 'Test street', 'municipality' => 'Prishtina', 'country_code' => 'XK', 'payment_terms_days' => 14, 'current_debt' => 0, 'current_credit' => 0]);
        InvoiceProfile::create(['legal_name' => 'Synthetic Seller', 'business_registration_number' => 'TEST-SELLER', 'fiscal_number' => 'TEST-FISCAL', 'registered_address' => 'Test seller street', 'municipality' => 'Prishtina', 'country_code' => 'XK', 'invoice_prefix' => 'TEST-INV', 'credit_note_prefix' => 'TEST-CN', 'is_vat_registered' => false, 'sales_mode' => 'business_only']);
        $credit = $this->dispatch($customer, $product['id'], $warehouse['id'], 5, 'credit');
        $cash = $this->dispatch($customer, $product['id'], $warehouse['id'], 3, 'cash');
        $sale = DailySale::findOrFail($credit->daily_sale_id)->load('items');
        $invoice = app(InvoiceService::class)->createDraft(['customer_id' => $customer->id, 'invoice_date' => today()->toDateString(), 'supply_date' => today()->toDateString(), 'due_date' => today()->addDays(14)->toDateString(), 'items' => [['product_id' => $product['id'], 'description' => 'Handles', 'unit' => 'pcs', 'quantity' => 5, 'unit_price' => 10]]], $sale);
        app(InvoiceService::class)->issue($invoice);
        $accounts = app(FinancialAccountService::class);
        $bank = $accounts->createAccount(['name' => 'Operating Bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => 1000, 'opening_date' => today()->toDateString(), 'is_active' => true]);
        $accounts->post($bank, ['type' => 'inflow', 'amount' => 30, 'transaction_date' => today()->toDateString(), 'source_type' => 'daily_sale', 'source_id' => $cash->daily_sale_id, 'counter_accounting_account_id' => AccountingAccount::where('code', '1000')->value('id'), 'idempotency_key' => (string) Str::uuid()]);
        app(CustomerDebtService::class)->recordPayment($customer->fresh(), ['amount' => 25, 'transaction_date' => today()->toDateString(), 'financial_account_id' => $bank->id, 'payment_method' => 'bank_transfer', 'idempotency_key' => (string) Str::uuid()]);
        $accounting = app(AccountingService::class);
        $this->assertSame('80.00', $accounting->profitAndLoss(null, null)['revenue']);
        $this->assertSame('25.00', $customer->fresh()->current_debt);
        $this->assertSame(92.0, (float) Product::findOrFail($product['id'])->quantity);
        $this->assertSame($sale->id, \App\Models\CustomerDebtTransaction::findOrFail($credit->fresh()->customer_debt_transaction_id)->daily_sale_id);
        $rows = $accounting->reconciliation(false);
        $this->assertTrue($rows['all_reconciled'], json_encode($rows));
        $this->assertSame('1055.00', $rows['rows']['cash_bank']['operational_amount']);
    }

    private function dispatch(Customer $customer, int $product, int $warehouse, int $quantity, string $payment)
    {
        $service = app(OutboundService::class);
        $order = $service->create(['customer_id' => $customer->id, 'warehouse_id' => $warehouse, 'order_date' => today()->toDateString(), 'payment_type' => $payment, 'currency' => 'EUR', 'items' => [['product_id' => $product, 'quantity' => $quantity, 'unit' => 'pcs', 'unit_price' => 10]], 'idempotency_key' => (string) Str::uuid()]);
        foreach (['confirm', 'reserve', 'allocate'] as $action) $service->action($order->fresh(), $action, ['idempotency_key' => (string) Str::uuid()]);
        $order = $order->fresh('allocations');
        foreach ($order->allocations as $a) $service->action($order, 'pick', ['allocation_id' => $a->id, 'location_id' => $a->location_id, 'quantity' => $a->quantity, 'manual_verification' => true, 'reason' => 'Verified regression fixture', 'idempotency_key' => (string) Str::uuid()]);
        $service->action($order->fresh(), 'pack', ['items' => $order->allocations->map(fn ($a) => ['allocation_id' => $a->id, 'quantity' => $a->quantity])->all(), 'idempotency_key' => (string) Str::uuid()]);
        $order = $order->fresh('packages');
        $service->action($order, 'dispatch', ['package_ids' => $order->packages->pluck('id')->all(), 'idempotency_key' => (string) Str::uuid()]);
        return $order->dispatches()->latest('id')->firstOrFail();
    }
}
