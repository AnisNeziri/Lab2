<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\BankStatement;
use App\Models\BankStatementRow;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AccountingService;
use App\Services\LandedCostService;
use App\Services\BankReconciliationService;
use App\Services\FinancialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalAccountingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_sale_posts_revenue_and_historical_cogs_and_edit_reverses_original(): void
    {
        $this->actingAsApiUser('admin');
        $category = Category::create($this->tenantAttributes(['name' => 'Accounting goods']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id, 'name' => 'Costed item', 'sku' => 'GL-SALE',
            'quantity' => 20, 'unit' => 'pcs', 'price' => 10, 'selling_price' => 10, 'purchase_price' => 6,
        ]));
        $payload = ['sale_date' => now()->toDateString(), 'items' => [[
            'product_id' => $product->id, 'product_name' => $product->name,
            'unit' => 'pcs', 'quantity' => 5, 'unit_price' => 10,
        ]]];
        $sale = $this->postJson('/api/daily-sales', $payload)->assertCreated();

        $service = app(AccountingService::class);
        $this->assertSame('50.00', $service->profitAndLoss(null, now()->toDateString())['revenue']);
        $this->assertSame('30.00', $service->profitAndLoss(null, now()->toDateString())['cost_of_goods_sold']);

        $payload['items'][0]['quantity'] = 2;
        $this->putJson('/api/daily-sales/'.$sale->json('id'), $payload)->assertOk();
        $pl = $service->profitAndLoss(null, now()->toDateString());
        $this->assertSame('20.00', $pl['revenue']);
        $this->assertSame('12.00', $pl['cost_of_goods_sold']);
        $this->assertSame(18.0, $product->fresh()->quantity);
        $this->assertTrue(JournalEntry::query()->where('source_type', 'daily_sale')->where('status', 'reversed')->exists());
    }

    public function test_partial_goods_receipts_and_landed_cost_post_authoritative_values_once(): void
    {
        [$supplier, $product] = $this->procurementContext();
        $order = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id, 'ordered_at' => now()->toDateString(),
            'currency' => 'EUR', 'status' => 'ordered', 'items' => [[
                'product_id' => $product->id, 'description' => $product->name,
                'unit' => 'pcs', 'quantity' => 10, 'unit_price' => 100,
            ]],
        ])->assertCreated();
        $this->postJson('/api/purchase-orders/'.$order->json('id').'/receive', [
            'items' => [['id' => $order->json('items.0.id'), 'quantity' => 4]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();
        $receipt = GoodsReceipt::query()->firstOrFail();
        $landed = app(LandedCostService::class)->createDraft([
            'goods_receipt_id' => $receipt->id, 'cost_type' => 'freight', 'amount' => 40,
            'currency' => 'EUR', 'allocation_method' => 'quantity',
            'goods_receipt_item_ids' => $receipt->items()->pluck('id')->all(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        app(LandedCostService::class)->post($landed);
        app(LandedCostService::class)->post($landed->fresh());

        $this->postJson('/api/daily-sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id, 'product_name' => $product->name,
                'unit' => 'pcs', 'quantity' => 2, 'unit_price' => 150,
            ]],
        ])->assertCreated();

        $rows = collect(app(AccountingService::class)->trialBalance(null, now()->toDateString())['rows'])->keyBy('code');
        $this->assertSame('220.00', $rows['1200']['closing_balance']);
        $this->assertSame('-440.00', $rows['2100']['closing_balance']);
        $this->assertSame('220.00', $rows['5000']['closing_balance']);
        $this->assertSame(3, JournalEntry::query()->whereIn('source_module', ['inventory', 'landed_cost'])->count());

        $reversed = app(LandedCostService::class)->reverse($landed->fresh(), 'Supplier cancelled the freight charge.');
        $rows = collect(app(AccountingService::class)->trialBalance(null, now()->toDateString())['rows'])->keyBy('code');
        $this->assertSame('reversed', $reversed->status);
        $this->assertSame('200.00', $rows['1200']['closing_balance']);
        $this->assertSame('-400.00', $rows['2100']['closing_balance']);
        $this->assertSame('200.00', $rows['5000']['closing_balance']);
        $this->assertSame(100.0, (float) $product->fresh()->weighted_average_cost);
        $this->assertTrue(JournalEntry::query()->where('source_key', 'landed-cost:'.$landed->id)->where('status', 'reversed')->exists());
    }

    public function test_opening_setup_requires_balance_and_is_finalized_once(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(\App\Models\User::query()->where('company_id', $this->apiCompany->id)->firstOrFail());
        $accounting = app(AccountingService::class);
        $accounts = collect($accounting->initialize()['accounts'])->keyBy('code');
        try {
            $accounting->finalizeOpening(now()->toDateString(), [
                ['accounting_account_id' => $accounts['1000']->id, 'debit' => 100],
                ['accounting_account_id' => $accounts['3000']->id, 'credit' => 90],
            ]);
            $this->fail('Unbalanced opening was accepted.');
        } catch (ValidationException) {
            $this->assertFalse($this->apiCompany->fresh()->accounting_opening_finalized_at !== null);
        }
        $entry = $accounting->finalizeOpening(now()->toDateString(), [
            ['accounting_account_id' => $accounts['1000']->id, 'debit' => 100],
            ['accounting_account_id' => $accounts['3000']->id, 'credit' => 100],
        ]);
        $this->assertSame('posted', $entry->status);
        $this->assertNotNull($this->apiCompany->fresh()->accounting_opening_finalized_at);
        $this->expectException(ValidationException::class);
        $accounting->finalizeOpening(now()->toDateString(), [
            ['accounting_account_id' => $accounts['1000']->id, 'debit' => 1],
            ['accounting_account_id' => $accounts['3000']->id, 'credit' => 1],
        ]);
    }

    public function test_foreign_supplier_settlement_posts_realized_fx_without_rewriting_original(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(\App\Models\User::query()->where('company_id', $this->apiCompany->id)->firstOrFail());
        $accounting = app(AccountingService::class);
        $accounting->initialize();
        $expense = Expense::create($this->tenantAttributes([
            'vendor_name' => 'FX Supplier', 'document_type' => 'purchase_invoice',
            'vendor_key' => hash('sha256', 'fx-supplier'), 'document_number_normalized' => 'FX-1',
            'document_number' => 'FX-1', 'category' => 'other', 'invoice_date' => now()->toDateString(),
            'received_date' => now()->toDateString(), 'net_amount' => 100, 'net_amount_eur' => 90,
            'vat_amount' => 0, 'deductible_vat_amount' => 0, 'non_deductible_vat_amount' => 0,
            'currency' => 'USD', 'exchange_rate' => .9, 'gross_amount' => 100,
            'gross_amount_eur' => 90, 'deductible_vat_amount_eur' => 0, 'status' => 'posted',
        ]));
        $accounting->postExpense($expense);
        $payment = ExpensePayment::create($this->tenantAttributes([
            'expense_id' => $expense->id, 'amount' => 100, 'amount_eur' => 95,
            'exchange_rate' => .95, 'status' => 'completed', 'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer', 'idempotency_key' => 'fx-payment',
        ]));
        $accounting->postExpensePayment($payment->load('expense'));
        $rows = collect($accounting->trialBalance(null, now()->toDateString())['rows'])->keyBy('code');
        $this->assertSame('0.00', $rows['2000']['closing_balance']);
        $this->assertSame('5.00', $rows['6300']['closing_balance']);
        $this->assertSame('0.900000', $expense->fresh()->exchange_rate);
    }

    public function test_bank_reconciliation_links_existing_transaction_and_journal_without_duplicate(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(\App\Models\User::query()->where('company_id', $this->apiCompany->id)->firstOrFail());
        $accounting = app(AccountingService::class);
        $accounts = collect($accounting->initialize()['accounts'])->keyBy('code');
        $bank = app(FinancialAccountService::class)->createAccount([
            'type' => 'bank', 'name' => 'Test bank', 'currency' => 'EUR',
            'opening_balance' => 0, 'is_active' => true,
        ]);
        $transaction = app(FinancialAccountService::class)->post($bank, [
            'type' => 'outflow', 'amount' => 25, 'transaction_date' => now()->toDateString(),
            'description' => 'Bank fee', 'idempotency_key' => (string) Str::uuid(),
            'counter_accounting_account_id' => $accounts['6000']->id,
        ]);
        $statement = BankStatement::create($this->tenantAttributes([
            'financial_account_id' => $bank->id, 'file_name' => 'bank.csv',
            'file_hash' => hash('sha256', 'bank-test'), 'column_mapping' => [],
            'imported_by' => Auth::id(),
        ]));
        $row = BankStatementRow::create($this->tenantAttributes([
            'bank_statement_id' => $statement->id, 'transaction_date' => now()->toDateString(),
            'description' => 'Bank fee', 'amount' => -25, 'status' => 'unmatched',
            'row_hash' => hash('sha256', 'row-test'),
        ]));
        $before = JournalEntry::query()->count();
        $matched = app(BankReconciliationService::class)->reconcile($row, $transaction->fresh());
        $this->assertSame($transaction->journal_entry_id, $matched->journal_entry_id);
        $this->assertSame($before, JournalEntry::query()->count());
        $this->assertNotNull($matched->journalEntry);
    }

    public function test_control_account_reconciliation_reports_operational_and_gl_amounts(): void
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(\App\Models\User::query()->where('company_id', $this->apiCompany->id)->firstOrFail());
        app(AccountingService::class)->initialize();
        app(FinancialAccountService::class)->createAccount([
            'type' => 'cashbox', 'name' => 'Opening till', 'currency' => 'EUR',
            'opening_balance' => 75, 'opening_date' => now()->toDateString(), 'is_active' => true,
        ]);
        $result = app(AccountingService::class)->reconciliation(true);
        $this->assertTrue($result['all_reconciled']);
        $this->assertSame('75.00', $result['rows']['cash_bank']['operational_amount']);
        $this->assertSame('75.00', $result['rows']['cash_bank']['gl_amount']);
        $this->getJson('/api/accounting/integrity')
            ->assertOk()
            ->assertJsonPath('reconciliation.all_reconciled', true);
    }

    private function procurementContext(): array
    {
        $this->actingAsApiUser('admin');
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'GL Supplier']));
        $category = Category::create($this->tenantAttributes(['name' => 'GL Inventory']));
        $product = Product::create($this->tenantAttributes([
            'category_id' => $category->id, 'supplier_id' => $supplier->id,
            'name' => 'Received item', 'sku' => 'GL-RECEIPT', 'quantity' => 0,
            'unit' => 'pcs', 'price' => 150, 'purchase_price' => 100,
        ]));
        return [$supplier, $product];
    }
}
