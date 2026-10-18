<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\DatedFinancialReconciliation;
use App\Services\SupplierPaymentAllocationService;
use App\Support\CompanyClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DC2SupplierControlTest extends TestCase
{
    use RefreshDatabase;

    private function context(int $quantity = 10000, float $price = 2.5): array
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        app(AccountingService::class)->initialize();
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'DC2 supplier']));
        $category = Category::create($this->tenantAttributes(['name' => 'DC2 fabrics']));
        $product = Product::create($this->tenantAttributes(['category_id' => $category->id, 'supplier_id' => $supplier->id, 'name' => 'DC2 fabric', 'sku' => 'DC2-FAB', 'quantity' => 0, 'unit' => 'm', 'price' => 5, 'purchase_price' => $price]));
        $id = $this->postJson('/api/purchase-orders', ['supplier_id' => $supplier->id, 'ordered_at' => '2026-09-01', 'currency' => 'EUR', 'status' => 'ordered', 'items' => [['product_id' => $product->id, 'description' => 'Fabric', 'quantity' => $quantity, 'unit' => 'm', 'unit_price' => $price]]])->assertCreated()->json('id');

        return [$supplier, $product, PurchaseOrder::findOrFail($id)];
    }

    private function advance(PurchaseOrder $po, int $amount, string $key): PurchaseOrderPayment
    {
        $this->postJson('/api/purchase-orders/'.$po->id.'/payments', ['amount' => $amount, 'payment_date' => '2026-09-02', 'payment_method' => 'bank_transfer', 'idempotency_key' => $key])->assertCreated();

        return PurchaseOrderPayment::where('idempotency_key', $key)->firstOrFail();
    }

    private function bill(Supplier $supplier, ?PurchaseOrder $po, int $quantity = 10000, float $price = 2.5, array $overrides = []): array
    {
        return [...['supplier_id' => $supplier->id, 'purchase_order_id' => $po?->id, 'vendor_name' => $supplier->name, 'vendor_business_number' => '811111111',
            'document_type' => 'purchase_invoice', 'document_number' => 'DC2-BILL', 'source_type' => 'domestic', 'asset_treatment' => 'ordinary', 'category' => $po ? 'inventory' : 'rent', 'business_purpose' => 'Company operations',
            'invoice_date' => '2026-09-15', 'received_date' => '2026-09-15', 'due_date' => '2026-10-15', 'currency' => 'EUR', 'exchange_rate' => 1,
            'net_amount' => $quantity * $price, 'vat_rate' => 0, 'vat_amount' => 0, 'vat_treatment' => 'non_vat', 'input_vat_eligible' => false, 'deductible_vat_amount' => 0,
            'items' => [['purchase_order_item_id' => $po?->items->first()->id, 'product_id' => $po?->items->first()->product_id, 'description' => $po ? 'Fabric' : 'Office rent', 'quantity' => $quantity, 'unit' => $po ? 'm' : 'month', 'unit_price' => $price, 'vat_rate' => 0]]], ...$overrides];
    }

    public function test_supplier_draft_never_allocates_advance_or_changes_posted_books(): void
    {
        [$supplier,,$po] = $this->context();
        $this->advance($po, 6000, 'six-thousand');
        $before = JournalEntry::count();
        $id = $this->postJson('/api/supplier-invoices', $this->bill($supplier, $po))->assertCreated()->json('id');
        $this->assertSame($before, JournalEntry::count());
        $this->assertDatabaseCount('supplier_invoice_payment_allocations', 0);
        $this->postJson('/api/supplier-invoices/'.$id.'/allocate-payment', ['purchase_order_payment_id' => PurchaseOrderPayment::first()->id, 'amount' => 100, 'idempotency_key' => 'unsafe-draft'])->assertUnprocessable();
        $this->postJson('/api/finance/expenses/'.$id.'/post')->assertUnprocessable();
    }

    public function test_required_supplier_journey_c_allocates_sixteen_thousand_after_review_with_nine_thousand_payable(): void
    {
        [$supplier,$product,$po] = $this->context();
        $this->advance($po, 6000, 'first-advance');
        $this->advance($po, 10000, 'second-advance');
        $this->postJson('/api/purchase-orders/'.$po->id.'/receive', ['received_at' => '2026-09-10 10:00:00', 'items' => [['id' => $po->items->first()->id, 'quantity' => 10000]], 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $receipt = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
        $id = $this->postJson('/api/supplier-invoices', $this->bill($supplier, $po, 10000, 2.5, ['goods_receipt_ids' => [$receipt->id]]))->assertCreated()->json('id');
        $this->postJson('/api/supplier-invoices/'.$id.'/approve', ['reason' => 'Review receipt and supplier settlement.'])->assertOk();
        $r = app(AccountingService::class)->reconciliation(false);
        $this->assertTrue($r['all_reconciled']);
        $this->assertSame('9000.00', $r['rows']['accounts_payable']['gl_amount']);
        $this->assertSame('0.00', $r['rows']['supplier_advances']['gl_amount']);
        $this->assertSame('-16000.00', $r['rows']['cash_bank']['gl_amount']);
        $this->assertSame('25000.00', $r['rows']['inventory']['gl_amount']);
        $this->assertEquals(10000, $product->fresh()->quantity);
        $this->assertEquals(9000, Expense::findOrFail($id)->remaining_amount);
        $this->getJson('/api/accounting/ap/aging?as_of=2026-09-30')->assertOk()->assertJsonPath('summary.total', '25000.00');
        $dated = app(DatedFinancialReconciliation::class)->report('2026-09-30');
        $this->assertTrue($dated['all_reconciled'], json_encode($dated));
        $this->assertSame('16000.00', collect($dated['rows'])->firstWhere('control', 'supplier_advances')['operational_amount']);
        $this->getJson('/api/accounting/ap/aging?as_of='.CompanyClock::today()->toDateString())->assertOk()->assertJsonPath('summary.total', '9000.00');
        $this->getJson('/api/accounting/ap/suppliers/'.$supplier->id.'/workbench')->assertOk()->assertJsonPath('summary.total', '9000.00')->assertJsonPath('advances.data.0.remaining', '0.00');
        $this->getJson('/api/accounting/ap/suppliers/'.$supplier->id.'/statement?from=2026-09-01&to=2026-09-30')->assertOk()->assertJsonPath('closing.payable', '25000.00')->assertJsonPath('closing.advance', '16000.00');
        $this->getJson('/api/accounting/ap/suppliers/'.$supplier->id.'/statement?from=2026-09-01&to='.CompanyClock::today()->toDateString())->assertOk()->assertJsonPath('closing.payable', '9000.00')->assertJsonPath('closing.advance', '0.00');
        $csv = $this->getJson('/api/accounting/exports/supplier-statement?supplier_id='.$supplier->id.'&from=2026-09-01&to='.CompanyClock::today()->toDateString())->assertOk()->streamedContent();
        $this->assertStringContainsString('9000.00', $csv);
    }

    public function test_incremental_allocation_posts_only_the_new_amount_and_cross_rate_realized_difference(): void
    {
        [$supplier,,$po] = $this->context(100, 10);
        $payment = $this->advance($po, 800, 'advance');
        $bill = Expense::create($this->tenantAttributes(['supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'vendor_name' => $supplier->name, 'vendor_key' => 'fx-bill', 'document_number' => 'FX-BILL', 'document_number_normalized' => 'fx-bill', 'document_type' => 'purchase_invoice', 'source_type' => 'import', 'category' => 'rent', 'asset_treatment' => 'ordinary', 'invoice_date' => '2026-09-15', 'received_date' => '2026-09-15', 'currency' => 'USD', 'exchange_rate' => 0.9, 'net_amount' => 1000, 'gross_amount' => 1000, 'net_amount_eur' => 900, 'gross_amount_eur' => 900, 'status' => 'posted']));
        $payment->update(['currency' => 'USD', 'exchange_rate' => 0.95, 'amount_eur' => 760]);
        $s = app(SupplierPaymentAllocationService::class);
        $s->allocate($payment, $bill, 200, 'First partial application.', 'part-one');
        $s->allocate($payment, $bill, 100, 'Second partial application.', 'part-two');
        $requestId = DB::table('supplier_payment_allocation_requests')->where('idempotency_key', 'part-two')->value('id');
        $journal = JournalEntry::where('source_key', 'supplier-allocation:'.$requestId)->with('lines.account')->firstOrFail();
        $debits = $journal->lines->sum('debit');
        $this->assertEquals(95, $debits);
        $this->assertEquals(90, $journal->lines->firstWhere('account.code', '2000')->debit);
        $this->assertEquals(5, $journal->lines->firstWhere('account.code', '6300')->debit);
        $this->assertEquals(300, $payment->fresh()->allocated_amount);
    }

    public function test_required_three_way_journey_d_surfaces_quantity_and_total_price_variance_without_changing_receipt(): void
    {
        [$supplier,$product,$po] = $this->context(10000, 2);
        $this->postJson('/api/purchase-orders/'.$po->id.'/receive', ['received_at' => '2026-09-10 10:00:00', 'items' => [['id' => $po->items->first()->id, 'quantity' => 9800]], 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $receipt = GoodsReceipt::where('purchase_order_id', $po->id)->firstOrFail();
        $r = $this->postJson('/api/supplier-invoices', $this->bill($supplier, $po, 10000, 2.1, ['goods_receipt_ids' => [$receipt->id]]))->assertCreated();
        $this->assertEquals(200, $r->json('match_summary.lines.0.quantityVariance'));
        $this->assertEquals(1000, $r->json('match_summary.price_variance_total'));
        $this->assertContains('QUANTITY_VARIANCE', $r->json('match_summary.lines.0.matchFlags'));
        $this->assertContains('PRICE_VARIANCE', $r->json('match_summary.lines.0.matchFlags'));
        $this->assertEquals(9800, $product->fresh()->quantity);
        $this->assertEquals(10000, $po->fresh('items')->items->first()->quantity);
        $this->postJson('/api/supplier-invoices/'.$r->json('id').'/approve', [])->assertUnprocessable();
    }

    public function test_release_reallocate_and_required_reversal_journey_h_preserve_originals_and_gl(): void
    {
        [$supplier,,$po] = $this->context(100, 10);
        $this->advance($po, 600, 'advance-to-correct');
        $id = $this->postJson('/api/supplier-invoices', $this->bill($supplier, $po, 100, 10))->assertCreated()->json('id');
        $this->postJson('/api/supplier-invoices/'.$id.'/approve', ['reason' => 'Reviewed non-received billing exception.'])->assertOk();
        $request = DB::table('supplier_payment_allocation_requests')->first();
        $input = ['reversal_date' => CompanyClock::today()->toDateString(), 'reason' => 'Correct the supplier remittance allocation.'];
        $this->postJson('/api/supplier-payment-allocation-requests/'.$request->id.'/reverse', $input)->assertOk();
        $this->postJson('/api/supplier-payment-allocation-requests/'.$request->id.'/reverse', $input)->assertOk();
        $this->assertDatabaseHas('supplier_payment_allocation_requests', ['id' => $request->id, 'amount' => '600.00', 'status' => 'reversed']);
        $r = app(AccountingService::class)->reconciliation(false);
        $this->assertTrue($r['all_reconciled']);
        $this->assertSame('1000.00', $r['rows']['accounts_payable']['gl_amount']);
        $this->assertSame('600.00', $r['rows']['supplier_advances']['gl_amount']);
        $this->postJson('/api/supplier-invoices/'.$id.'/allocate-payment', ['purchase_order_payment_id' => $request->purchase_order_payment_id, 'amount' => 300, 'reason' => 'Corrected remittance allocation.', 'idempotency_key' => 'corrected-selection'])->assertOk();
        $new = DB::table('supplier_payment_allocation_requests')->where('status', 'completed')->first();
        $this->postJson('/api/supplier-payment-allocation-requests/'.$new->id.'/reverse', $input)->assertOk();
        $this->postJson('/api/finance/expenses/'.$id.'/reverse', ['reason' => 'Supplier cancelled the original bill.'])->assertOk();
        $original = JournalEntry::where('source_key', 'expense:'.$id)->firstOrFail();
        $this->assertSame('reversed', $original->status);
        $this->assertNotNull($original->reversal);
        $this->assertSame('Supplier cancelled the original bill.', $original->reversal_reason);
        $r = app(AccountingService::class)->reconciliation(false);
        $this->assertTrue($r['all_reconciled']);
        $this->assertSame('0.00', $r['rows']['accounts_payable']['gl_amount']);
        $this->assertDatabaseHas('expenses', ['id' => $id, 'status' => 'reversed']);
        $this->getJson('/api/accounting/ap/aging?as_of=2026-09-30')->assertOk()->assertJsonPath('summary.total', '1000.00');
        $this->getJson('/api/accounting/ap/aging?as_of='.CompanyClock::today()->toDateString())->assertOk()->assertJsonPath('summary.total', '0.00');
    }

    public function test_non_po_service_bill_posts_expense_and_supports_partial_supplier_settlement(): void
    {
        [$supplier] = $this->context();
        $id = $this->postJson('/api/supplier-invoices', $this->bill($supplier, null, 1, 25000))->assertCreated()->assertJsonPath('match_summary.match_label', 'NO_PO')->json('id');
        $this->postJson('/api/supplier-invoices/'.$id.'/approve', ['reason' => 'Legitimate office rent without a purchase order.'])->assertOk();
        $this->postJson('/api/finance/expenses/'.$id.'/payments', ['amount' => 6000, 'payment_date' => '2026-09-20', 'payment_method' => 'cash', 'idempotency_key' => 'rent-partial'])->assertCreated()->assertJsonPath('remaining_amount', 19000);
        $r = app(AccountingService::class)->reconciliation(false);
        $this->assertTrue($r['all_reconciled']);
        $this->assertSame('19000.00', $r['rows']['accounts_payable']['gl_amount']);
        $this->assertSame('25000.00', app(AccountingService::class)->profitAndLoss(null, null)['operating_expenses']);
    }

    public function test_foreign_po_payment_requires_its_own_historical_rate_and_snapshots_it(): void
    {
        [,, $po] = $this->context(100, 10);
        $po->update(['currency' => 'USD', 'exchange_rate' => 0.8, 'exchange_rate_date' => '2026-09-01', 'exchange_rate_source' => 'Reviewed PO rate']);
        $input = ['amount' => 100, 'payment_date' => '2026-09-05', 'payment_method' => 'cash', 'idempotency_key' => 'foreign-payment'];
        $this->postJson('/api/purchase-orders/'.$po->id.'/payments', $input)->assertUnprocessable();
        $this->postJson('/api/purchase-orders/'.$po->id.'/payments', [...$input, 'exchange_rate' => 0.95, 'exchange_rate_date' => '2026-09-04', 'exchange_rate_source' => 'Authorized payment bank quote'])->assertCreated();
        $p = PurchaseOrderPayment::where('idempotency_key', 'foreign-payment')->firstOrFail();
        $journal = JournalEntry::where('source_key', 'purchase-order-payment:'.$p->id)->firstOrFail();
        $this->assertSame('2026-09-04', $journal->exchange_rate_date->toDateString());
        $this->assertSame('Authorized payment bank quote', $journal->exchange_rate_source);
        $this->assertSame('95.00', $p->amount_eur);
        $this->assertSame('0.950000', $p->exchange_rate);
        $this->assertSame('2026-09-04', $p->exchange_rate_date->toDateString());
        $this->postJson('/api/purchase-orders/'.$po->id.'/payments', [...$input, 'exchange_rate' => 0.96, 'exchange_rate_date' => '2026-09-04', 'exchange_rate_source' => 'Authorized payment bank quote'])->assertUnprocessable();
        $this->postJson('/api/purchase-orders/'.$po->id.'/payments', [...$input, 'idempotency_key' => 'future-rate', 'exchange_rate' => 0.95, 'exchange_rate_date' => '2026-09-06', 'exchange_rate_source' => 'Later quote'])->assertUnprocessable();
    }

    public function test_zero_rated_exempt_and_out_of_scope_bills_remain_distinct_without_invented_tax(): void
    {
        [$supplier] = $this->context();
        foreach (['zero_rated', 'exempt', 'out_of_scope'] as $treatment) {
            $id = $this->postJson('/api/supplier-invoices', $this->bill($supplier, null, 1, 120, ['document_number' => 'TAX-'.$treatment, 'vat_treatment' => $treatment, 'tax_legal_reference' => 'Authorized accountant classification for synthetic data.']))->assertCreated()->assertJsonPath('vat_treatment', $treatment)->json('id');
            $this->postJson('/api/supplier-invoices/'.$id.'/approve', ['reason' => 'Accountant reviewed the non-PO tax classification.'])->assertOk()->assertJsonPath('vat_amount', '0.00')->assertJsonPath('vat_treatment', $treatment);
        }
        $this->assertEquals(0, app(AccountingService::class)->controlBalances()['input_vat']);
        $this->assertEquals(360, app(AccountingService::class)->reconciliation(false)['rows']['accounts_payable']['gl_amount']);
    }
}
