<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxCode;
use App\Services\AccountingService;
use App\Services\FinanceAssistantFacts;
use App\Services\FinancialIntegrityChecks;
use App\Services\InvoiceService;
use App\Services\VatReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesDC3TaxCompany;
use Tests\TestCase;

class DC3VatReportingTest extends TestCase
{
    use CreatesDC3TaxCompany,RefreshDatabase;

    public function test_last_day_mixed_sales_purchase_and_credit_notes_reconcile_with_drillable_current_form_fields(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'Europe/Belgrade'));
        $this->setupTax();
        $data = $this->invoiceData(TaxCode::where('code', 'KS18')->value('id'));
        $data['items'][] = ['description' => 'Reduced service', 'quantity' => 1, 'unit' => 'pcs', 'unit_price' => '50', 'tax_code_id' => TaxCode::where('code', 'KS08')->value('id')];
        $draft = $this->postJson('/api/invoices', $data)->assertCreated();
        $issued = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $this->purchase();
        $report = $this->getJson('/api/tax/vat-period?period=2026-10&carried_credit=0&refund_requested=0')->assertOk()->json();
        $rows = collect($report['rows'])->keyBy('number');
        $this->assertSame('18.00', $rows[13]['amount']);
        $this->assertSame('4.00', $rows[15]['amount']);
        $this->assertSame('9.00', $rows[44]['amount']);
        $this->assertSame('13.00', $rows[72]['amount']);
        $this->assertTrue($report['reconciliation']['reconciled']);
        $this->getJson('/api/tax/vat-documents?period=2026-10&book=output&box=30')->assertOk()->assertJsonPath('total',2);
        $this->getJson('/api/tax/vat-documents?period=2026-10&book=sales&box=14')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.description', 'Reduced service');
        $credit = app(InvoiceService::class)->fullCreditNote(Invoice::findOrFail($issued->json('id')), 'Correct the original complete supply');
        $report = app(VatReportingService::class)->period('2026-10', '0', '0');
        $rows = collect($report['rows'])->keyBy('number');
        $this->assertSame('18.00', $rows[54]['amount']);
        $this->assertSame('4.00', $rows[56]['amount']);
        $this->assertSame('31.00', $rows[67]['amount']);
        $this->assertTrue($report['reconciliation']['reconciled']);
        $this->assertSame('0.00', $report['reconciliation']['controls']['output_vat']['gl_total']);
        $this->getJson('/api/tax/vat-documents?period=2026-10&book=sales&box=53')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.source_id', $credit->id);
    }

    public function test_material_vat_control_difference_and_ambiguous_partial_input_block_a_complete_return(): void
    {
        $this->setupTax();
        $data = $this->invoiceData(TaxCode::where('code', 'KS18')->value('id'));
        $draft = $this->postJson('/api/invoices', $data)->assertCreated();
        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        app(AccountingService::class)->postMapped('manual', '', 0, 'vat-review-adjustment', now()->toDateString(), 'Review required: control adjustment without a tax document', [
            ['mapping' => 'output_vat', 'credit' => '1'], ['mapping' => 'operating_expense', 'debit' => '1'],
        ]);
        $recon = $this->getJson('/api/tax/vat-reconciliation')->assertOk()->json();
        $this->assertFalse($recon['reconciled']);
        $this->assertSame('-1.00', $recon['controls']['output_vat']['difference']);
        $checks = collect(app(FinancialIntegrityChecks::class)->checks())->keyBy('code');
        $this->assertFalse($checks['vat_gl']['passed']);
        $this->assertTrue($checks['vat_gl']['required']);
        $facts = app(FinanceAssistantFacts::class);
        $this->assertSame('finance_vat', $facts->intent('is VAT reconciled', 'dc3'));
        $this->assertSame('finance_fiscal', $facts->intent('a ka gabime ne fiskalizim', 'dc3'));
        $this->assertSame('finance_action_boundary', $facts->intent('please fiscalize invoice 10', 'dc3'));
        $reply = $facts->reply('finance_vat', 'is VAT reconciled', 'dc3', false);
        $this->assertTrue($reply['read_only']);
        $this->assertStringContainsString('differences', $reply['text']);
        $this->purchase(['document_number' => 'PARTIAL', 'deductible_vat_amount' => '4.50']);
        $report = app(VatReportingService::class)->period(now()->format('Y-m'), '0', '0');
        $this->assertFalse($report['ready_for_review']);
        $this->assertNotEmpty($report['unmapped']);
        $this->assertNull(collect($report['rows'])->keyBy('number')[72]['amount']);
    }

    public function test_import_assessment_is_vat_only_and_linked_landed_cost_excludes_recoverable_vat(): void
    {
        $this->setupTax();
        $bill = $this->postJson('/api/finance/expenses', [
            'vendor_name' => 'Customs', 'document_number' => 'CUSTOMS-1', 'document_type' => 'customs_document', 'source_type' => 'import',
            'asset_treatment' => 'ordinary', 'category' => 'taxes_fees', 'business_purpose' => 'Import VAT assessment',
            'invoice_date' => now()->toDateString(), 'received_date' => now()->toDateString(),
            'net_amount' => 0, 'import_taxable_base' => 10000, 'vat_amount' => 1800, 'vat_rate' => 18, 'vat_treatment' => 'import_vat',
            'input_vat_eligible' => true, 'deductible_vat_amount' => 1800,
        ])->assertCreated()->assertJsonPath('gross_amount', '1800.00');
        $this->postJson('/api/finance/expenses/'.$bill->json('id').'/post')->assertOk();
        $this->assertSame('1800.00', app(VatReportingService::class)->reconciliation()['controls']['input_vat']['gl_total']);
        $form = collect(app(VatReportingService::class)->period(now()->format('Y-m'))['rows'])->keyBy('number');
        $this->assertSame('10000.00', $form[35]['amount']);
        $this->assertSame('1800.00', $form[36]['amount']);
        $supplier = Supplier::create($this->tenantAttributes(['name' => 'Import supplier']));
        $category = Category::create($this->tenantAttributes(['name' => 'Fabric']));
        $product = Product::create($this->tenantAttributes(['name' => 'Import fabric', 'sku' => 'DC3-IMPORT', 'category_id' => $category->id, 'supplier_id' => $supplier->id, 'quantity' => 0, 'unit' => 'm', 'price' => 4, 'selling_price' => 4, 'purchase_price' => 2]));
        $po = $this->postJson('/api/purchase-orders', ['supplier_id' => $supplier->id, 'ordered_at' => now()->toDateString(), 'status' => 'ordered', 'currency' => 'EUR', 'items' => [['product_id' => $product->id, 'description' => 'Fabric', 'unit' => 'm', 'quantity' => 100, 'unit_price' => 2]]])->assertCreated()->json();
        $this->postJson('/api/purchase-orders/'.$po['id'].'/receive', ['received_at' => now()->toDateTimeString(), 'items' => [['id' => $po['items'][0]['id'], 'quantity' => 100]], 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $receipt = GoodsReceipt::firstOrFail();
        $cost = ['goods_receipt_id' => $receipt->id, 'cost_type' => 'freight', 'amount' => 100, 'currency' => 'EUR', 'allocation_method' => 'quantity', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/landed-costs', [...$cost, 'source_expense_id' => $bill->json('id')])->assertUnprocessable();
        $this->purchase(['document_number' => 'FREIGHT', 'net_amount' => 100, 'vat_amount' => 18, 'deductible_vat_amount' => 18]);
        $freight = Expense::where('document_number', 'FREIGHT')->firstOrFail();
        $landed = $this->postJson('/api/landed-costs', [...$cost, 'source_expense_id' => $freight->id])->assertCreated()->json();
        $this->postJson('/api/landed-costs/'.$landed['id'].'/post')->assertOk();
        $this->assertEquals(300, $product->fresh()->inventory_value);
        $this->assertTrue(app(AccountingService::class)->reconciliation(false)['all_reconciled']);
        $this->assertTrue(app(VatReportingService::class)->reconciliation()['reconciled']);
        $this->postJson('/api/landed-costs', [...$cost, 'source_expense_id' => $freight->id, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/finance/expenses/'.$freight->id.'/reverse', ['reason' => 'Cancel freight expense after review'])->assertUnprocessable();
    }

    private function purchase(array $overrides = []): void
    {
        $data = array_replace(['vendor_name' => 'Supplier', 'vendor_vat_number' => '330111111', 'document_type' => 'purchase_invoice', 'document_number' => 'VAT-INPUT',
            'source_type' => 'domestic', 'asset_treatment' => 'ordinary', 'category' => 'professional_services', 'business_purpose' => 'Business service',
            'invoice_date' => now()->toDateString(), 'received_date' => now()->toDateString(), 'tax_code_id' => TaxCode::where('code', 'KS18')->value('id'),
            'net_amount' => '50', 'vat_rate' => 18, 'vat_amount' => '9', 'vat_treatment' => 'standard', 'input_vat_eligible' => true, 'deductible_vat_amount' => '9'], $overrides);
        $bill = $this->postJson('/api/finance/expenses',$data)->assertCreated();
        $this->postJson('/api/finance/expenses/'.$bill->json('id').'/post')->assertOk();
    }
}
