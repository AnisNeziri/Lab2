<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\AccountingPostingMapping;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DC3TaxControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_decimal_inclusive_discount_and_mixed_rates_survive_issue_without_recalculation_drift(): void
    {
        $this->setupTax();
        $code = TaxCode::where('code', 'KS18')->firstOrFail();
        $data = $this->invoiceData($code->id);
        $data['items'][0] += ['pricing_mode' => 'inclusive', 'discount_percent' => '10'];
        $data['items'][0]['unit_price'] = '118';
        $data['items'][] = ['description' => 'Reduced service', 'unit' => 'pcs', 'quantity' => '1', 'unit_price' => '50', 'tax_code_id' => TaxCode::where('code', 'KS08')->value('id')];
        $draft = $this->postJson('/api/invoices', $data)->assertCreated()->assertJsonPath('taxable_total', '140.00')->assertJsonPath('vat_total', '20.20')->assertJsonPath('grand_total', '160.20');
        $issued = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk()->assertJsonPath('taxable_total', '140.00')->assertJsonPath('vat_total', '20.20')->assertJsonPath('grand_total', '160.20');
        $this->assertSame('18.0000', $issued->json('items.0.tax_snapshot.rate'));
        $this->assertSame('10.00', $issued->json('items.0.discount_amount'));
        $this->assertSame('11.80', $issued->json('items.0.pricing_discount_amount'));
    }

    public function test_used_rule_is_versioned_and_posted_tax_evidence_cannot_be_changed(): void
    {
        $this->setupTax();
        $code = TaxCode::where('code', 'KS18')->firstOrFail();
        $draft = $this->postJson('/api/invoices', $this->invoiceData($code->id))->assertCreated();
        $invoice = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $change = $code->only(['code', 'name', 'rate', 'treatment', 'sales_enabled', 'purchases_enabled', 'active', 'output_account_id', 'input_account_id', 'sef_tax_group', 'legal_reference']);
        $change += ['effective_from' => '2015-09-01', 'effective_to' => null];
        $change['name'] = 'Changed historical category';
        $this->putJson('/api/tax/codes/'.$code->id, $change)->assertUnprocessable();
        $this->postJson('/api/tax/codes/'.$code->id.'/retire')->assertOk();
        $this->getJson('/api/invoices/'.$invoice->json('id'))->assertOk()->assertJsonPath('items.0.tax_snapshot.name', 'VAT 18%');
        $line = Invoice::findOrFail($invoice->json('id'))->items()->firstOrFail();
        try {
            $line->update(['vat_amount' => '0']);
            $this->fail('Posted tax mutation was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tax', $e->errors());
        }
    }

    public function test_tax_code_and_default_assignment_cannot_cross_companies_or_effective_dates(): void
    {
        $this->setupTax();
        $code = TaxCode::where('code', 'KS18')->firstOrFail();
        $foreign = TaxCode::withoutEvents(fn () => TaxCode::withoutGlobalScopes()->create([...$code->getAttributes(), 'id' => null, 'company_id' => Company::factory()->create()->id, 'code' => 'FOREIGN']));
        $this->postJson('/api/invoices', $this->invoiceData($foreign->id))->assertUnprocessable();
        $this->putJson('/api/tax/codes/'.$foreign->id, [])->assertNotFound();
        $code->update(['effective_to' => now()->subDay()->toDateString()]);
        $this->postJson('/api/invoices', $this->invoiceData($code->id))->assertUnprocessable();
    }

    public function test_unclassified_exemption_requires_legal_basis_and_does_not_infer_product_names(): void
    {
        $this->setupTax();
        $code = TaxCode::where('code', 'EX-NC')->firstOrFail();
        $draft = $this->postJson('/api/invoices', $this->invoiceData($code->id))->assertCreated();
        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_reverse_charge_recognizes_both_controls_and_nonrecoverable_tax_as_expense(): void
    {
        $this->setupTax();
        $code = TaxCode::where('code', 'RC18')->firstOrFail();
        $draft = $this->postJson('/api/finance/expenses', [
            'vendor_name' => 'Supplier', 'vendor_vat_number' => '330111111', 'document_type' => 'purchase_invoice', 'document_number' => 'RC-TEST',
            'source_type' => 'domestic', 'asset_treatment' => 'ordinary', 'category' => 'professional_services', 'business_purpose' => 'Business service',
            'invoice_date' => now()->toDateString(), 'received_date' => now()->toDateString(),
            'net_amount' => '100', 'vat_rate' => '18', 'vat_amount' => '0', 'vat_treatment' => 'reverse_charge', 'input_vat_eligible' => true,
            'deductible_vat_amount' => '9', 'tax_legal_reference' => 'Accountant-confirmed reverse-charge basis', 'tax_code_id' => $code->id,
        ])->assertCreated()->assertJsonPath('non_deductible_vat_amount', '9.00')->assertJsonPath('gross_amount', '100.00');
        $this->postJson('/api/finance/expenses/'.$draft->json('id').'/post')->assertOk();
        $entry = JournalEntry::where('source_key', 'expense:'.$draft->json('id'))->firstOrFail();
        $this->assertSame('118.00', $entry->total_debit);
        $this->assertSame('118.00', $entry->total_credit);
        $this->assertSame('9.00', $entry->lines()->where('accounting_account_id', $code->input_account_id)->value('debit'));
        $this->assertSame('18.00', $entry->lines()->where('accounting_account_id', $code->output_account_id)->value('credit'));
        $expenseAccount = AccountingPostingMapping::where('mapping_key', 'operating_expense')->value('accounting_account_id');
        $this->assertSame('109.00', $entry->lines()->where('accounting_account_id', $expenseAccount)->value('debit'));
    }

    public function test_output_vat_follows_the_frozen_company_control_account(): void
    {
        $this->setupTax();
        $code = TaxCode::where('code', 'KS18')->firstOrFail();
        $account = AccountingAccount::create(['code' => 'VAT-LOCAL', 'name' => 'Dedicated output VAT', 'type' => 'liability', 'normal_balance' => 'credit', 'is_posting' => true, 'is_active' => true]);
        $code->update(['output_account_id' => $account->id]);
        $draft = $this->postJson('/api/invoices', $this->invoiceData($code->id))->assertCreated();
        $issued = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $entry = JournalEntry::where('source_key', 'invoice:'.$issued->json('id'))->firstOrFail();
        $this->assertSame('18.00', $entry->lines()->where('accounting_account_id', $account->id)->value('credit'));
    }

    private function setupTax(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/invoice-profile', [
            'legal_name' => 'DC3 Test Company', 'business_registration_number' => '811234567', 'fiscal_number' => '600987654', 'is_vat_registered' => true, 'vat_number' => '330987654',
            'registered_address' => 'Test Street 1', 'municipality' => 'Prishtine', 'postal_code' => '10000', 'country_code' => 'XK', 'phone' => '+38338000000', 'email' => 'test@example.test',
            'invoice_prefix' => 'INV', 'credit_note_prefix' => 'CN', 'default_language' => 'bilingual', 'default_payment_terms_days' => 14, 'sales_mode' => 'business_only',
        ])->assertOk();
        $this->postJson('/api/tax/configuration/initialize')->assertOk();
    }

    private function invoiceData(int $taxId): array
    {
        return ['invoice_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
            'buyer' => ['legal_name' => 'Business Buyer', 'business_registration_number' => '812222222', 'address' => 'Buyer Street 1', 'country_code' => 'XK'],
            'items' => [['description' => 'Ordinary service', 'unit' => 'pcs', 'quantity' => '1', 'unit_price' => '100', 'tax_code_id' => $taxId]]];
    }
}
