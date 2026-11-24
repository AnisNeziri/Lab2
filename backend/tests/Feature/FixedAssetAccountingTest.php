<?php

namespace Tests\Feature;

use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Support\CompanyClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquisition_depreciation_impairment_and_disposal_reconcile_and_reverse(): void
    {
        $this->actingAsApiUser();
        \App\Models\InvoiceProfile::create($this->tenantAttributes([
            'legal_name' => 'Synthetic Kosovo Company', 'country_code' => 'XK', 'registered_address' => 'Synthetic Street',
            'is_vat_registered' => true, 'vat_number' => '330000001',
        ]));
        $assetDate = '2025-01-01';
        $category = $this->postJson('/api/accounting/fixed-assets/categories', [
            'name' => 'Equipment', 'minimum_cost' => '500.00', 'useful_life_months' => 12, 'policy_reference' => 'Synthetic cost model v1',
        ])->assertCreated()->json('id');
        $expense = $this->postJson('/api/finance/expenses', $this->bill('EQ-1', $assetDate, '3650.00', '657.00', '657.00'))->assertCreated()->json('id');
        $this->postJson("/api/finance/expenses/$expense/post")->assertUnprocessable()->assertJsonValidationErrors('expense_id');
        $this->assertDatabaseCount('journal_entries', 0);
        $asset = $this->postJson("/api/accounting/fixed-assets/bills/$expense", [
            'category_id' => $category, 'asset_number' => 'EQ-01', 'name' => 'Fabric cutting equipment',
            'residual_value' => 0, 'reason' => 'Reviewed supplier receipt and operating use.',
        ])->assertCreated()->json('id');
        $this->postJson("/api/finance/expenses/$expense/post")->assertOk();
        $this->postJson("/api/finance/expenses/$expense/post")->assertOk();
        $extra = $this->postJson('/api/finance/expenses', $this->bill('EQ-2', $assetDate, '100.00', '18.00', '0.00'))->assertCreated()->json('id');
        $this->postJson("/api/accounting/fixed-assets/bills/$extra", [
            'fixed_asset_id' => $asset, 'reason' => 'Installation before use, including nonrecoverable VAT.',
        ])->assertCreated();
        $this->postJson("/api/finance/expenses/$extra/post")->assertOk();
        $this->postJson("/api/accounting/fixed-assets/$asset/in-service", ['date' => $assetDate, 'reason' => 'Installation acceptance recorded.'])->assertOk();

        $this->assertJournal("expense:$expense", ['1500' => '3650.00', '1150' => '657.00', '2000' => '-4307.00']);
        $this->assertJournal("expense:$extra", ['1500' => '118.00', '2000' => '-118.00']);
        $get = "/api/accounting/fixed-assets/$asset";
        $this->getJson("$get?as_of=2025-01-31")->assertOk()->assertJsonPath('cost', '3768.00')
            ->assertJsonPath('depreciation_due', '320.02');
        $data = ['kind' => 'depreciation', 'date' => '2025-01-31', 'reason' => 'January depreciation reviewed.', 'request_key' => 'january'];
        $dep = $this->postJson("$get/entries", $data)->assertCreated()->assertJsonPath('depreciation_delta', '320.02')->json('id');
        $this->postJson("$get/entries", $data)->assertCreated()->assertJsonPath('id', $dep);
        $this->postJson("$get/entries", [...$data, 'reason' => 'Different request.'])->assertUnprocessable();
        $this->postJson("$get/entries", [...$data, 'request_key' => 'january-duplicate'])->assertUnprocessable();

        $this->postJson("$get/entries", ['kind' => 'impairment', 'date' => '2025-01-31', 'amount' => '447.98', 'reason' => 'Synthetic recoverable-value evidence.', 'request_key' => 'impair-1'])
            ->assertCreated()->assertJsonPath('impairment_delta', '447.98');
        $this->getJson("$get?as_of=2025-01-31")->assertOk()->assertJsonPath('net_book_value', '3000.00');
        $this->getJson("$get?as_of=2025-02-28")->assertOk()->assertJsonPath('depreciation_due', '251.50');
        $this->postJson("$get/entries", [...$data, 'date' => '2025-02-28', 'request_key' => 'february'])->assertCreated()->assertJsonPath('depreciation_delta', '251.50');
        $disposal = $this->postJson("$get/entries", [
            'kind' => 'write_off', 'date' => '2025-02-28', 'reason' => 'Synthetic loss without proceeds.', 'request_key' => 'write-off',
        ])->assertCreated()->assertJsonPath('cost_delta', '-3768.00')->json('id');
        $this->assertJournal("fixed-asset-entry:$disposal", ['1500' => '-3768.00', '1510' => '571.52', '1520' => '447.98', '6520' => '2748.50']);
        $this->postJson("/api/finance/expenses/$expense/reverse", ['reason' => 'Attempt before reversing downstream asset activity.'])->assertUnprocessable();
        $this->postJson("/api/accounting/fixed-assets/entries/$disposal/reverse", ['date' => '2025-03-01', 'reason' => 'Correct the synthetic disposal.'])->assertCreated();
        $this->getJson("$get?as_of=2025-02-28")->assertOk()->assertJsonPath('net_book_value', '0.00');
        $this->getJson("$get?as_of=2025-03-01")->assertOk()->assertJsonPath('net_book_value', '2748.50');
        $this->getJson('/api/accounting/fixed-assets?as_of=2025-03-01')->assertOk()
            ->assertJsonPath('reconciliation.reconciled', true)
            ->assertJsonPath('reconciliation.rows.cost.ledger', '3768.00')
            ->assertJsonPath('reconciliation.rows.depreciation.ledger', '571.52');
        $this->assertTrue(app(AccountingService::class)->trialBalance(null, '2025-03-01')['balanced']);
    }

    public function test_asset_sale_reclassifies_invoice_proceeds_and_credit_restores_register(): void
    {
        $this->actingAsApiUser('admin');
        \App\Models\InvoiceProfile::create($this->tenantAttributes([
            'legal_name' => 'Synthetic Seller', 'business_registration_number' => '811111111', 'fiscal_number' => '600111111',
            'country_code' => 'XK', 'registered_address' => 'Synthetic Street 1', 'municipality' => 'Prishtina',
            'invoice_prefix' => 'TEST', 'credit_note_prefix' => 'TESTCN', 'sales_mode' => 'business_only',
        ]));
        $date = CompanyClock::today()->toDateString();
        $category = $this->postJson('/api/accounting/fixed-assets/categories', [
            'name' => 'Asset sale equipment', 'minimum_cost' => 10, 'useful_life_months' => 12, 'policy_reference' => 'Synthetic policy',
        ])->assertCreated()->json('id');
        $bill = $this->postJson('/api/finance/expenses', $this->bill('SALE-BILL', $date, '500.00', '0.00', '0.00'))->assertCreated()->json('id');
        $asset = $this->postJson("/api/accounting/fixed-assets/bills/$bill", [
            'category_id' => $category, 'asset_number' => 'SALE-1', 'name' => 'Reviewed fixture',
            'residual_value' => 0, 'reason' => 'Approved synthetic purchase.',
        ])->assertCreated()->json('id');
        $this->postJson("/api/finance/expenses/$bill/post")->assertOk();
        $invoice = $this->postJson('/api/invoices', [
            'invoice_date' => $date, 'supply_date' => $date,
            'buyer' => ['legal_name' => 'Synthetic buyer', 'address' => 'Test Street', 'business_registration_number' => '811222222', 'country_code' => 'XK'],
            'items' => [['description' => 'Sale of asset SALE-1', 'quantity' => 1, 'unit_price' => 700, 'tax_treatment' => 'non_vat']],
        ])->assertCreated()->json('id');
        $sale = $this->postJson("/api/accounting/fixed-assets/$asset/sale", ['invoice_id' => $invoice, 'reason' => 'Synthetic asset sale approved.'])->assertCreated()->json('id');
        $this->deleteJson("/api/invoices/$invoice")->assertUnprocessable();
        $this->postJson("/api/invoices/$invoice/issue")->assertOk();
        $this->assertJournal("fixed-asset-entry:$sale", ['1500' => '-500.00', '4000' => '700.00', '4220' => '-200.00']);
        $this->getJson("/api/accounting/fixed-assets/$asset")->assertOk()->assertJsonPath('net_book_value', '0.00');
        $bank = app(\App\Services\FinancialAccountService::class)->createAccount(['name' => 'Synthetic disposal bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => 0]);
        $payment = $this->postJson('/api/payments', ['invoice_id' => $invoice, 'amount' => 700, 'payment_date' => $date,
            'payment_method' => 'bank_transfer', 'financial_account_id' => $bank->id, 'idempotency_key' => 'disposal-pay',
        ])->assertCreated()->json('id');
        $accounting = app(AccountingService::class);
        $this->assertSame('700.00', $accounting->cashFlow(null, $date)['investing']);
        $this->assertSame('200.00', $accounting->profitAndLoss(null, $date)['other_gains']);
        $this->postJson("/api/payments/$payment/reverse", ['reason' => 'Synthetic sale payment reversed.'])->assertOk();
        $this->assertSame('0.00', $accounting->cashFlow(null, $date)['investing']);
        $this->postJson("/api/invoices/$invoice/credit-note", ['reason' => 'Synthetic asset sale cancelled.'])->assertCreated();
        $this->getJson("/api/accounting/fixed-assets/$asset")->assertOk()->assertJsonPath('net_book_value', '500.00');
        $this->getJson('/api/accounting/fixed-assets')->assertOk()->assertJsonPath('reconciliation.reconciled', true);
    }

    public function test_role_tenant_and_period_boundaries_prevent_posting(): void
    {
        $this->actingAsApiUser('admin');
        $category = $this->postJson('/api/accounting/fixed-assets/categories', [
            'name' => 'Fittings', 'minimum_cost' => 1, 'useful_life_months' => 12, 'policy_reference' => 'Synthetic approved policy',
        ])->assertCreated()->json('id');
        $date = CompanyClock::today()->toDateString();
        $expense = $this->postJson('/api/finance/expenses', $this->bill('TENANT-EQ', $date, '120.00', '0.00', '0.00'))->assertCreated()->json('id');
        $asset = $this->postJson("/api/accounting/fixed-assets/bills/$expense", [
            'category_id' => $category, 'asset_number' => 'A-01', 'name' => 'Equipment',
            'residual_value' => 0, 'reason' => 'Approved synthetic equipment.',
        ])->assertCreated()->json('id');
        $this->assertNotNull(FixedAsset::find($asset));
        $this->postJson("/api/finance/expenses/$expense/post")->assertOk();
        $this->postJson("/api/accounting/fixed-assets/$asset/in-service", ['date' => $date, 'reason' => 'Synthetic acceptance.'])->assertOk();
        \App\Models\AccountingPeriod::create($this->tenantAttributes(['name' => 'Locked synthetic test', 'starts_at' => $date, 'ends_at' => $date, 'status' => 'closed']));
        $payload = ['kind' => 'depreciation', 'date' => $date, 'reason' => 'Attempt in locked period.', 'request_key' => 'closed'];
        $this->postJson("/api/accounting/fixed-assets/$asset/entries", $payload)->assertUnprocessable()->assertJsonPath('code', 'accounting_period_locked');
        $warehouse = \App\Models\User::factory()->create($this->tenantAttributes(['role' => 'warehouse', 'email_verified_at' => now()]));
        $this->renewApiSession($warehouse);
        $this->postJson("/api/accounting/fixed-assets/$asset/entries", $payload)->assertForbidden();
        $outsider = \App\Models\User::factory()->create(['company_id' => \App\Models\Company::factory()->create()->id, 'role' => 'admin', 'email_verified_at' => now()]);
        $this->renewApiSession($outsider);
        $this->getJson("/api/accounting/fixed-assets/$asset")->assertNotFound();
        $this->assertDatabaseCount('journal_entries', 1);
    }

    private function assertJournal(string $key, array $expected): void
    {
        $entry = JournalEntry::query()->where('source_key', $key)->with('lines.account')->sole();
        $amounts = $entry->lines->mapWithKeys(fn ($line) => [$line->account->code => \App\Support\Money::subtract($line->debit, $line->credit)])->all();
        $this->assertEquals($expected, $amounts);
    }

    private function bill(string $number, string $date, string $net, string $tax, string $recoverable): array
    {
        return [
            'vendor_name' => 'Synthetic equipment vendor', 'vendor_business_number' => '811111111', 'vendor_vat_number' => '330111111',
            'document_number' => $number, 'document_type' => 'purchase_invoice', 'source_type' => 'domestic', 'category' => 'capital_asset',
            'asset_treatment' => 'investment', 'business_purpose' => 'Warehouse operation', 'invoice_date' => $date, 'received_date' => $date,
            'currency' => 'EUR', 'net_amount' => $net, 'vat_rate' => $tax === '0.00' ? 0 : 18, 'vat_amount' => $tax,
            'vat_treatment' => $tax === '0.00' ? 'non_vat' : 'standard', 'input_vat_eligible' => $recoverable !== '0.00', 'deductible_vat_amount' => $recoverable,
        ];
    }
}
