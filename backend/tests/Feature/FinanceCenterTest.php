<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceProfile;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reverse_charge_uses_received_period_and_does_not_increase_vendor_payable(): void
    {
        $this->actingAsApiUser();
        $this->vatProfile();

        $created = $this->postJson('/api/finance/expenses', $this->expense([
            'source_type' => 'import', 'invoice_date' => '2026-01-30', 'received_date' => '2026-02-03',
            'vat_treatment' => 'reverse_charge', 'vat_rate' => 18, 'vat_amount' => 0,
            'self_assessed_vat_amount' => 18, 'deductible_vat_amount' => 18,
            'tax_legal_reference' => 'Kosovo reverse-charge rules',
        ]))->assertCreated()
            ->assertJsonPath('gross_amount', '100.00')
            ->assertJsonPath('self_assessed_vat_amount', '18.00');

        $this->postJson('/api/finance/expenses/'.$created->json('id').'/post')
            ->assertOk()->assertJsonPath('vat_period', '2026-02');

        $this->getJson('/api/finance/vat-books?date_from=2026-02-01&date_to=2026-02-28')
            ->assertOk()
            ->assertJsonPath('summary.output_vat', 18)
            ->assertJsonPath('summary.deductible_input_vat', 18)
            ->assertJsonPath('summary.net_vat', 0)
            ->assertJsonPath('purchase_book.0.gross_amount_eur', 100);
    }

    public function test_posted_expense_is_immutable_and_cross_month_reversal_is_a_signed_adjustment(): void
    {
        $this->actingAsApiUser();
        $this->vatProfile();
        $created = $this->postJson('/api/finance/expenses', $this->expense([
            'invoice_date' => '2026-01-05', 'received_date' => '2026-01-10',
        ]))->assertCreated();
        $id = $created->json('id');
        $this->postJson("/api/finance/expenses/{$id}/post")->assertOk();
        $this->putJson("/api/finance/expenses/{$id}", $this->expense())->assertUnprocessable();

        Carbon::setTestNow('2026-02-05 12:00:00');
        $this->postJson("/api/finance/expenses/{$id}/reverse", ['reason' => 'Supplier document cancelled'])
            ->assertOk()->assertJsonPath('status', 'reversed');

        $this->getJson('/api/finance/vat-books?date_from=2026-01-01&date_to=2026-01-31')
            ->assertOk()->assertJsonPath('purchase_book.0.net_amount_eur', 100);
        $this->getJson('/api/finance/vat-books?date_from=2026-02-01&date_to=2026-02-28')
            ->assertOk()->assertJsonPath('purchase_book.0.entry_type', 'reversal')
            ->assertJsonPath('purchase_book.0.net_amount_eur', -100);
    }

    public function test_credit_note_is_negative_and_non_payable_while_payments_are_reversible_and_idempotent(): void
    {
        $this->actingAsApiUser();
        $this->vatProfile();
        $credit = $this->postJson('/api/finance/expenses', $this->expense([
            'document_type' => 'credit_note', 'document_number' => 'CN-1', 'original_document_number' => 'INV-OLD',
        ]))->assertCreated();
        $this->postJson('/api/finance/expenses/'.$credit->json('id').'/post')->assertOk();
        $this->postJson('/api/finance/expenses/'.$credit->json('id').'/payments', $this->payment())
            ->assertUnprocessable();

        $bill = $this->postJson('/api/finance/expenses', $this->expense(['document_number' => 'PAY-1']))->assertCreated();
        $this->postJson('/api/finance/expenses/'.$bill->json('id').'/post')->assertOk();
        $first = $this->postJson('/api/finance/expenses/'.$bill->json('id').'/payments', $this->payment())
            ->assertCreated()->assertJsonPath('payment_status', 'partially_paid');
        $this->postJson('/api/finance/expenses/'.$bill->json('id').'/payments', $this->payment())->assertCreated();
        $this->assertDatabaseCount('expense_payments', 1);
        $this->postJson('/api/finance/expenses/'.$bill->json('id').'/payments', $this->payment(['payment_method' => 'cash']))
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $paymentId = Expense::findOrFail($bill->json('id'))->payments()->firstOrFail()->id;
        $this->postJson("/api/finance/expense-payments/{$paymentId}/reverse", ['reason' => 'Wrong bank transaction'])
            ->assertOk()->assertJsonPath('payment_status', 'unpaid');
        $this->postJson('/api/finance/expenses/'.$bill->json('id').'/reverse', ['reason' => 'Supplier cancelled bill'])
            ->assertOk();

        $books = $this->getJson('/api/finance/vat-books?date_from=2026-08-01&date_to=2026-08-31')->assertOk();
        $creditRow = collect($books->json('purchase_book'))->firstWhere('document_number', 'CN-1');
        $this->assertSame(-100.0, (float) $creditRow['net_amount_eur']);
    }

    public function test_duplicate_supplier_document_and_rb500_aggregation_are_safe(): void
    {
        $this->actingAsApiUser();
        $this->vatProfile();
        foreach ([1, 2, 3] as $number) {
            $created = $this->postJson('/api/finance/expenses', $this->expense([
                'document_number' => "RB-{$number}", 'net_amount' => 200, 'vat_rate' => 0,
                'vat_amount' => 0, 'vat_treatment' => 'non_vat', 'input_vat_eligible' => false,
                'deductible_vat_amount' => 0,
            ]))->assertCreated();
            $this->postJson('/api/finance/expenses/'.$created->json('id').'/post')->assertOk();
        }
        $this->postJson('/api/finance/expenses', $this->expense(['document_number' => 'RB-1']))
            ->assertUnprocessable()->assertJsonValidationErrors('document_number');

        $this->getJson('/api/finance/vat-books?date_from=2026-01-01&date_to=2026-12-31')
            ->assertOk()->assertJsonPath('rb500.suppliers.0.annual_total', 600)
            ->assertJsonPath('rb500.status', 'review');
    }

    public function test_overview_separates_deductible_vat_and_investment_from_operating_expense(): void
    {
        $this->actingAsApiUser();
        $this->vatProfile();
        $ordinary = $this->postJson('/api/finance/expenses', $this->expense(['document_number' => 'OP-1']))->assertCreated();
        $this->postJson('/api/finance/expenses/'.$ordinary->json('id').'/post')->assertOk();
        $asset = $this->postJson('/api/finance/expenses', $this->expense(['document_number' => 'ASSET-1', 'asset_treatment' => 'investment', 'category' => 'capital_asset']))->assertCreated();
        $this->postJson('/api/finance/expenses/'.$asset->json('id').'/post')->assertOk();

        $this->getJson('/api/finance/overview?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()->assertJsonPath('summary.total_expenses', 100)
            ->assertJsonPath('summary.investment_purchases', 100)
            ->assertJsonPath('summary.deductible_input_vat', 36);
    }

    public function test_invoice_and_vat_exports_are_real_injection_safe_xlsx_files(): void
    {
        $this->actingAsApiUser();
        $this->vatProfile();
        $invoice = Invoice::create($this->tenantAttributes([
            'invoice_number' => 'INV-XLSX-1', 'document_type' => 'invoice', 'customer_name' => '=HYPERLINK("https://bad")',
            'seller_snapshot' => ['legal_name' => 'AIMS Test'], 'buyer_snapshot' => ['legal_name' => '=HYPERLINK("https://bad")'],
            'status' => 'issued', 'payment_status' => 'unpaid', 'currency' => 'EUR', 'invoice_date' => '2026-08-01',
            'subtotal' => 100, 'discount_total' => 0, 'taxable_total' => 100, 'vat_total' => 18,
            'grand_total' => 118, 'total_amount' => 118, 'total_paid' => 0,
        ]));
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'description' => '=2+2', 'unit' => 'pcs', 'quantity' => 1,
            'unit_price' => 100, 'discount_percent' => 0, 'discount_amount' => 0, 'taxable_amount' => 100,
            'vat_rate' => 18, 'vat_amount' => 18, 'line_total' => 118, 'tax_treatment' => 'standard',
        ]);

        $invoiceExport = $this->get('/api/invoices/'.$invoice->id.'/excel?locale=bilingual')->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $invoiceExport->getContent());
        $this->assertStringContainsString('xl/workbook.xml', $invoiceExport->getContent());
        $this->assertStringContainsString('=HYPERLINK', $invoiceExport->getContent());
        $this->assertStringNotContainsString('<f>HYPERLINK', $invoiceExport->getContent());

        $vatExport = $this->get('/api/finance/vat-books/export?date_from=2026-08-01&date_to=2026-08-31&format=xlsx')
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $vatExport->getContent());
        $this->assertStringContainsString('PREPARED, NOT FILED', $vatExport->getContent());
    }

    public function test_sales_income_excludes_vat_and_mixed_rates_use_separate_book_buckets(): void
    {
        $this->actingAsApiUser();
        $this->vatProfile();
        $invoice = Invoice::create($this->tenantAttributes([
            'invoice_number' => 'INV-MIXED', 'document_type' => 'invoice', 'customer_name' => 'Mixed Buyer',
            'seller_snapshot' => ['legal_name' => 'AIMS Test'], 'buyer_snapshot' => ['legal_name' => 'Mixed Buyer'],
            'status' => 'issued', 'payment_status' => 'unpaid', 'currency' => 'EUR', 'invoice_date' => '2026-08-02',
            'issued_at' => '2026-08-02 10:00:00', 'subtotal' => 200, 'discount_total' => 0,
            'taxable_total' => 200, 'vat_total' => 26, 'grand_total' => 226, 'total_amount' => 226, 'total_paid' => 0,
        ]));
        foreach ([[100, 18], [100, 8]] as [$taxable, $rate]) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id, 'description' => "VAT {$rate}", 'unit' => 'pcs', 'quantity' => 1,
                'unit_price' => $taxable, 'taxable_amount' => $taxable, 'vat_rate' => $rate,
                'vat_amount' => $taxable * $rate / 100, 'line_total' => $taxable * (1 + $rate / 100),
                'tax_treatment' => 'standard',
            ]);
        }

        $overview = $this->getJson('/api/finance/overview?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()->assertJsonPath('summary.sales_income', 200)
            ->assertJsonPath('summary.invoice_sales_gross', 226);
        $this->assertSame(200.0, (float) $overview->json('summary.sales_income'));
        $books = $this->getJson('/api/finance/vat-books?date_from=2026-08-01&date_to=2026-08-31')->assertOk();
        $this->assertCount(2, $books->json('sales_book'));
        $this->assertSame(['12/13', '14/15'], collect($books->json('sales_book'))->pluck('bucket_code')->sort()->values()->all());
    }

    public function test_historical_aging_and_cash_reversals_preserve_their_original_periods(): void
    {
        $this->actingAsApiUser();
        $user = User::where('company_id', $this->apiCompany->id)->firstOrFail();
        $invoice = Invoice::create($this->tenantAttributes([
            'invoice_number' => 'INV-AGING', 'document_type' => 'invoice', 'customer_name' => 'History Buyer',
            'status' => 'issued', 'payment_status' => 'unpaid', 'currency' => 'EUR', 'invoice_date' => '2026-01-01',
            'issued_at' => '2026-01-01 10:00:00', 'due_at' => '2026-01-15', 'subtotal' => 100,
            'taxable_total' => 100, 'vat_total' => 18, 'grand_total' => 118, 'total_amount' => 118, 'total_paid' => 0,
        ]));
        PaymentTransaction::create($this->tenantAttributes([
            'invoice_id' => $invoice->id, 'user_id' => $user->id, 'amount' => 50, 'status' => 'reversed',
            'payment_method' => 'bank_transfer', 'payment_date' => '2026-02-05', 'paid_at' => '2026-02-05 09:00:00',
            'reversed_at' => '2026-03-02 09:00:00', 'reversed_by' => $user->id, 'reversal_reason' => 'Bank returned payment',
        ]));

        $this->getJson('/api/finance/receivables-aging?as_of=2026-01-31')->assertOk()
            ->assertJsonPath('summary.invoice_outstanding', 118);
        $this->getJson('/api/finance/receivables-aging?as_of=2026-02-28')->assertOk()
            ->assertJsonPath('summary.invoice_outstanding', 68);
        $this->getJson('/api/finance/receivables-aging?as_of=2026-03-31')->assertOk()
            ->assertJsonPath('summary.invoice_outstanding', 118);
        $this->getJson('/api/finance/cash-flow?date_from=2026-02-01&date_to=2026-02-28')->assertOk()
            ->assertJsonPath('summary.invoice_payments_received', 50);
        $this->getJson('/api/finance/cash-flow?date_from=2026-03-01&date_to=2026-03-31')->assertOk()
            ->assertJsonPath('summary.invoice_payments_received', -50);
    }

    public function test_tax_point_payment_rounding_and_not_applicable_filter_are_enforced(): void
    {
        $this->actingAsApiUser();
        $this->vatProfile();
        $bill = $this->postJson('/api/finance/expenses', $this->expense([
            'document_number' => 'LATE-SUPPLY', 'invoice_date' => '2026-01-30',
            'received_date' => '2026-02-01', 'supply_date' => '2026-03-02',
        ]))->assertCreated();
        $this->postJson('/api/finance/expenses/'.$bill->json('id').'/post')
            ->assertOk()->assertJsonPath('vat_period', '2026-03');
        $this->postJson('/api/finance/expenses/'.$bill->json('id').'/payments', $this->payment([
            'amount' => 0.004, 'idempotency_key' => 'rounded-zero',
        ]))->assertUnprocessable()->assertJsonValidationErrors('amount');

        $credit = $this->postJson('/api/finance/expenses', $this->expense([
            'document_type' => 'credit_note', 'document_number' => 'FILTER-CN', 'original_document_number' => 'OLD-1',
        ]))->assertCreated();
        $this->postJson('/api/finance/expenses/'.$credit->json('id').'/post')->assertOk();
        $filtered = $this->getJson('/api/finance/expenses?payment_status=not_applicable')->assertOk();
        $this->assertSame(['FILTER-CN'], collect($filtered->json('data'))->pluck('document_number')->all());
    }

    private function vatProfile(): InvoiceProfile
    {
        return InvoiceProfile::create($this->tenantAttributes([
            'legal_name' => 'AIMS Finance Test', 'business_registration_number' => '811234567',
            'fiscal_number' => '600000001', 'is_vat_registered' => true, 'vat_number' => '330000001',
            'registered_address' => 'Prishtina', 'country_code' => 'XK', 'sales_mode' => 'business_only',
        ]));
    }

    private function expense(array $overrides = []): array
    {
        return array_replace([
            'vendor_name' => 'Kosovo Supplier LLC', 'vendor_business_number' => '811111111',
            'vendor_fiscal_number' => '600000002', 'vendor_vat_number' => '330000002',
            'document_type' => 'purchase_invoice', 'document_number' => 'EXP-'.uniqid(),
            'source_type' => 'domestic', 'asset_treatment' => 'ordinary', 'category' => 'professional_services',
            'description' => 'Business service', 'business_purpose' => 'Company operations',
            'invoice_date' => '2026-08-01', 'received_date' => '2026-08-01', 'due_date' => '2026-08-31',
            'currency' => 'EUR', 'exchange_rate' => 1, 'net_amount' => 100, 'vat_rate' => 18,
            'vat_amount' => 18, 'vat_treatment' => 'standard', 'input_vat_eligible' => true,
            'deductible_vat_amount' => 18,
        ], $overrides);
    }

    private function payment(array $overrides = []): array
    {
        return array_replace([
            'amount' => 50, 'payment_date' => '2026-08-10', 'payment_method' => 'bank_transfer',
            'reference_number' => 'BANK-1', 'idempotency_key' => 'expense-pay-1', 'note' => 'First payment',
        ], $overrides);
    }
}
