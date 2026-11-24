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

    public function test_unvalued_stock_cannot_be_reconciled_after_a_priced_receipt(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(\App\Models\User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $accounting = app(AccountingService::class);
        $accounting->initialize();
        $category = Category::create(['name' => 'Synthetic held stock']);
        $product = Product::create(['name' => 'Legacy fabric', 'sku' => 'LEG-39', 'unit' => 'm', 'quantity' => 8, 'price' => 10, 'category_id' => $category->id]);
        $costing = app(\App\Services\InventoryCostingService::class);
        $receipt = $costing->movementSnapshot($product, 'in', 2.5, 8, 10.5, true, 4);
        $this->assertSame(10.0, $receipt['cost_total']);
        $this->assertNull($receipt['product_inventory_value']);
        $this->assertNull($receipt['product_weighted_average_cost']);
        $this->assertNull($costing->movementSnapshot($product, 'out', 1.5, 8, 6.5, true)['cost_total']);

        $product->update(['quantity' => 10.5]);
        \App\Models\StockMovement::create(['product_id' => $product->id, 'type' => 'in', 'quantity' => 8, 'quantity_before' => 0, 'quantity_after' => 8,
            'affects_company_quantity' => true, 'movement_code' => 'legacy_stock_in', 'unit_snapshot' => 'm', 'occurred_at' => now()]);
        $row = $accounting->reconciliation(true)['rows']['inventory'];
        $this->assertSame('evidence_required', $row['status']);
        $this->assertSame(1, $row['uncosted_movements']);
        $this->assertNull($row['operational_amount']);
        $this->assertNull($row['difference']);
        $this->assertSame('0.00', $row['gl_amount']);
        $dated = collect(app(\App\Services\DatedFinancialReconciliation::class)->report(today()->toDateString())['rows'])->keyBy('control')['inventory'];
        $this->assertFalse($dated['reconciled']);
        $this->assertNull($dated['operational_amount']);
        $this->assertDatabaseHas('accounting_exceptions', ['type' => 'inventory_source_evidence', 'severity' => 'critical', 'status' => 'open']);
        $summary = $this->getJson('/api/accounting/control-center')->assertOk();
        $summary->assertJsonPath('summary.gross_profit', null)->assertJsonPath('working_capital.total', null);
        $forecast = app(\App\Services\FinanceAssistantFacts::class)->read('get_posted_profit_comparison');
        $this->assertNull($forecast['current']['net_profit']);
        $this->assertNull($forecast['difference']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $costing->applyLandedCostAdjustment($product, 5);
    }

    public function test_supplier_advance_with_missing_fx_evidence_is_withheld(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(\App\Models\User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $accounting = app(AccountingService::class);
        $accounting->initialize();
        \App\Models\PurchaseOrderPayment::create([
            'amount' => 100, 'amount_eur' => 100, 'currency' => 'USD', 'exchange_rate' => 1,
            'status' => 'completed', 'payment_method' => 'bank_transfer', 'payment_date' => today()->toDateString(), 'paid_at' => now(),
        ]);
        $row = $accounting->reconciliation(false)['rows']['supplier_advances'];
        $this->assertSame('evidence_required', $row['status']);
        $this->assertNull($row['operational_amount']);
        $this->assertSame(1, $row['missing_currency_evidence']);
        $dated = collect(app(\App\Services\DatedFinancialReconciliation::class)->report(today()->toDateString())['rows'])->keyBy('control')['supplier_advances'];
        $this->assertSame('evidence_required', $dated['status']);
        $this->assertNull($dated['difference']);
    }

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
        $profit = $accounting->profitAndLoss(null, null);
        $this->assertSame('80.00', $profit['revenue']);
        $this->assertSame('16.00', $profit['cost_of_goods_sold']);
        $this->assertSame('64.00', $profit['gross_profit']);
        $this->assertSame('64.00', $profit['net_profit']);
        $this->assertSame('25.00', $customer->fresh()->current_debt);
        $this->assertSame(92.0, (float) Product::findOrFail($product['id'])->quantity);
        $this->assertSame($sale->id, \App\Models\CustomerDebtTransaction::findOrFail($credit->fresh()->customer_debt_transaction_id)->daily_sale_id);
        $rows = $accounting->reconciliation(false);
        $this->assertTrue($rows['all_reconciled'], json_encode($rows));
        $this->assertSame('184.00', $rows['rows']['inventory']['operational_amount']);
        $this->assertSame('1055.00', $rows['rows']['cash_bank']['operational_amount']);
        $trial = $accounting->trialBalance(null, null);
        $this->assertSame('1280.00', $trial['total_closing_debit']);
        $this->assertSame('1280.00', $trial['total_closing_credit']);
        $balance = $accounting->balanceSheet(null);
        $this->assertTrue($balance['balanced']);
        $this->assertSame('1264.00', $balance['assets']);
        $this->assertSame('1264.00', $balance['total_equity']);
        $cashFlow = $accounting->cashFlow(null, null);
        $this->assertTrue($cashFlow['reconciles']);
        $this->assertSame('55.00', $cashFlow['operating']);
        $this->assertSame('1000.00', $cashFlow['financing']);
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
