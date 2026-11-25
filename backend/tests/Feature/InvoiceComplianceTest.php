<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceProfile;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidenced_foreign_customer_invoice_settlements_and_credit_keep_books_in_eur(): void
    {
        $this->actingAsApiUser();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-12-31 14:00:00', 'Europe/Belgrade'));
        $this->putJson('/api/invoice-profile', $this->profile(['is_vat_registered' => false, 'vat_number' => null]))->assertOk();
        $date = '2025-12-31';
        $invoiceData = ['buyer' => $this->buyer(), 'invoice_date' => $date, 'supply_date' => $date, 'currency' => 'USD',
            'items' => [['description' => 'Synthetic service', 'quantity' => 2, 'unit' => 'service', 'unit_price' => 500, 'tax_treatment' => 'non_vat', 'vat_rate' => 0]]];
        $this->postJson('/api/invoices', $invoiceData)->assertUnprocessable()->assertJsonValidationErrors('exchange_rate');
        $invoiceData += ['exchange_rate' => '0.90', 'exchange_rate_source' => 'Synthetic historical quote', 'exchange_rate_date' => $date];
        $invoice = $this->postJson('/api/invoices', $invoiceData)->assertCreated()->json('id');
        $this->postJson("/api/invoices/$invoice/issue")->assertOk()->assertJsonPath('currency', 'USD')->assertJsonPath('exchange_snapshot.base_total', '900.00');
        $bank = app(\App\Services\FinancialAccountService::class)->createAccount(['name' => 'Synthetic settlement bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => 1000, 'opening_date' => $date]);
        $payments = ['invoice_id' => $invoice, 'payment_method' => 'bank_transfer', 'financial_account_id' => $bank->id,
            'payment_date' => $date, 'exchange_rate_date' => $date, 'exchange_rate_source' => 'Synthetic bank evidence'];
        $first = [...$payments, 'amount' => 200, 'exchange_rate' => '0.95', 'idempotency_key' => 'fx-first'];
        $firstId = $this->postJson('/api/payments', $first)->assertCreated()->assertJsonPath('exchange_snapshot.received_base', '190.00')
            ->assertJsonPath('exchange_snapshot.carrying_base', '180.00')->json('id');
        $this->postJson('/api/payments', $first)->assertCreated()->assertJsonPath('id', $firstId);
        $this->postJson('/api/payments', [...$first, 'exchange_rate' => '0.94'])->assertUnprocessable();
        $second = [...$payments, 'amount' => 300, 'exchange_rate' => '0.85', 'idempotency_key' => 'fx-second'];
        $secondId = $this->postJson('/api/payments', $second)->assertCreated()->assertJsonPath('exchange_snapshot.carrying_base', '270.00')->json('id');
        $this->postJson("/api/payments/$firstId/reverse", ['reason' => 'A newer FX allocation exists.'])->assertUnprocessable();
        $accounting = app(\App\Services\AccountingService::class);
        $profit = $accounting->profitAndLoss(null, $date);
        foreach (['revenue' => '900.00', 'other_gains' => '10.00', 'other_losses' => '15.00', 'net_profit' => '895.00'] as $key => $amount) {
            $this->assertSame($amount, $profit[$key], $key);
        }
        $this->assertSame('450.00', $accounting->reconciliation(false)['rows']['accounts_receivable']['operational_amount']);
        $this->assertSame('1445.00', $accounting->reconciliation(false)['rows']['cash_bank']['gl_amount']);
        $dated = fn (string $on) => collect(app(\App\Services\DatedFinancialReconciliation::class)->report($on)['rows'])->firstWhere('control', 'accounts_receivable');
        $this->assertSame('450.00', $dated($date)['operational_amount']);
        $this->assertTrue($accounting->trialBalance(null, $date)['balanced']);
        $next = '2026-01-01';
        $this->travelTo(\Illuminate\Support\Carbon::parse("$next 14:00:00", 'Europe/Belgrade'));
        $this->postJson("/api/payments/$secondId/reverse", ['reason' => 'Reverse second bank settlement.'])->assertOk();
        $this->assertSame('450.00', $dated($date)['gl_amount']);
        $this->assertSame('720.00', $dated($next)['gl_amount']);
        $unpaid = $this->postJson('/api/invoices', $invoiceData)->assertCreated()->json('id');
        $this->postJson("/api/invoices/$unpaid/issue")->assertOk();
        $this->postJson("/api/invoices/$unpaid/credit-note", ['reason' => 'Synthetic cancellation before collection.'])
            ->assertCreated()->assertJsonPath('exchange_snapshot.base_total', '900.00');
        $this->assertSame('720.00', $accounting->reconciliation(false)['rows']['accounts_receivable']['gl_amount']);
        $this->travelBack();
    }

    public function test_year_end_asset_and_foreign_positions_close_and_carry_forward(): void
    {
        $this->actingAsApiUser();
        $date = '2025-12-31';
        $this->travelTo(\Illuminate\Support\Carbon::parse("$date 14:00:00", 'Europe/Belgrade'));
        $this->putJson('/api/invoice-profile', $this->profile(['is_vat_registered' => false, 'vat_number' => null]))->assertOk();
        $accounting = app(\App\Services\AccountingService::class);
        $bank = app(\App\Services\FinancialAccountService::class)->createAccount(['name' => 'Year-end bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => 1000, 'opening_date' => $date]);
        $postedExpense = fn (string $number, array $attributes) => $this->postJson('/api/finance/expenses', [
            'vendor_name' => 'Synthetic supply company', 'vendor_business_number' => '811111111', 'document_type' => 'purchase_invoice',
            'document_number' => $number, 'invoice_date' => $date, 'received_date' => $date, 'currency' => 'EUR',
            'source_type' => 'domestic', 'asset_treatment' => 'ordinary', 'category' => 'professional_services',
            'business_purpose' => 'Synthetic close', 'net_amount' => 100, 'vat_rate' => 0, 'vat_amount' => 0,
            'vat_treatment' => 'non_vat', 'input_vat_eligible' => false, ...$attributes,
        ])->assertCreated()->json('id');
        $foreignBill = $postedExpense('CLOSE-USD-BILL', [
            'currency' => 'USD', 'source_type' => 'import', 'exchange_rate' => '0.90', 'exchange_rate_date' => $date, 'exchange_rate_source' => 'Synthetic invoice quotation',
        ]);
        $this->postJson("/api/finance/expenses/$foreignBill/post")->assertOk();
        $assetBill = $postedExpense('CLOSE-ASSET', ['category' => 'capital_asset', 'asset_treatment' => 'investment', 'net_amount' => 365]);
        $category = $this->postJson('/api/accounting/fixed-assets/categories', [
            'name' => 'Test fixtures', 'minimum_cost' => 200, 'useful_life_months' => 12, 'policy_reference' => 'Synthetic board approval',
        ])->assertCreated()->json('id');
        $asset = $this->postJson("/api/accounting/fixed-assets/bills/$assetBill", [
            'category_id' => $category, 'asset_number' => 'CLOSE-01', 'name' => 'Test fitting',
            'residual_value' => 0, 'reason' => 'Synthetic documented acquisition.',
        ])->assertCreated()->json('id');
        $this->postJson("/api/finance/expenses/$assetBill/post")->assertOk();
        $this->postJson("/api/accounting/fixed-assets/$asset/in-service", ['date' => $date, 'reason' => 'Synthetic first use.'])->assertOk();
        $this->postJson("/api/accounting/fixed-assets/$asset/entries", [
            'kind' => 'depreciation', 'date' => $date, 'reason' => 'One day of book depreciation.', 'request_key' => 'close-asset',
        ])->assertCreated()->assertJsonPath('depreciation_delta', '1.00');

        $invoice = $this->postJson('/api/invoices', [
            'buyer' => $this->buyer(), 'invoice_date' => $date, 'supply_date' => $date, 'issue_now' => true,
            'currency' => 'USD', 'exchange_rate' => '0.90', 'exchange_rate_source' => 'Synthetic invoice quotation', 'exchange_rate_date' => $date,
            'items' => [['description' => 'Synthetic sales service', 'quantity' => 1, 'unit' => 'service', 'unit_price' => 1000, 'tax_treatment' => 'non_vat', 'vat_rate' => 0]],
        ])->assertCreated()->json('id');
        foreach ([[200, '0.95'], [300, '0.85']] as [$amount, $rate]) {
            $this->postJson('/api/payments', ['invoice_id' => $invoice, 'financial_account_id' => $bank->id, 'amount' => $amount,
                'payment_method' => 'bank_transfer', 'payment_date' => $date, 'exchange_rate' => $rate, 'exchange_rate_date' => $date,
                'exchange_rate_source' => 'Synthetic settlement evidence', 'idempotency_key' => 'closing-'.$amount])->assertCreated();
        }
        $rec = app(\App\Services\BankReconciliationService::class);
        $csv = \Illuminate\Http\UploadedFile::fake()->createWithContent('close-bank.csv', "Date,Amount,Balance\n$date,190.00,1190.00\n$date,255.00,1445.00\n");
        $statement = $rec->import($bank, $csv, ['date' => 'Date', 'amount' => 'Amount', 'balance' => 'Balance']);
        $transactions = $bank->transactions()->orderBy('id')->get();
        foreach ($statement->rows->values() as $index => $row) {
            $rec->reconcile($row, $transactions[$index]);
        }
        $this->postJson("/api/finance/bank-statements/{$statement->id}/complete")->assertOk()->assertJsonPath('balance_control.difference', '0.00');

        $period = $this->postJson('/api/accounting/periods', ['name' => 'Synthetic FY2025', 'starts_at' => '2025-01-01', 'ends_at' => $date])->assertCreated()->json('id');
        $path = "/api/accounting/periods/$period";
        $this->postJson("$path/fx-preview")->assertOk()->assertJsonPath('issues.0', 'Provide the closing EUR rate and evidence for USD.');
        $this->putJson("$path/status", ['status' => 'closed', 'reason' => 'Attempt before foreign review.'])->assertUnprocessable();
        $quote = ['USD' => ['exchange_rate' => '0.94', 'exchange_rate_date' => $date, 'exchange_rate_source' => 'Approved synthetic year-end quote']];
        $preview = $this->postJson("$path/fx-preview", ['rates' => $quote])->assertOk()
            ->assertJsonPath('adjustments.ar', '20.00')->assertJsonPath('adjustments.ap', '4.00')->json();
        $approval = ['rates' => $quote, 'fingerprint' => $preview['fingerprint'], 'reason' => 'Reviewed closing monetary positions.'];
        $this->postJson("$path/fx-approve", [...$approval, 'fingerprint' => str_repeat('0', 64)])->assertUnprocessable();
        $approved = $this->postJson("$path/fx-approve", $approval)->assertCreated()->json('id');
        $this->postJson("$path/fx-approve", $approval)->assertCreated()->assertJsonPath('id', $approved);
        $this->postJson("$path/fx-preview")->assertOk()->assertJsonPath('rates.USD.rate', '0.94000000')->assertJsonCount(0, 'issues');
        $this->getJson("$path/tax-review")->assertNoContent();

        $control = collect(app(\App\Services\DatedFinancialReconciliation::class)->report($date)['rows'])->keyBy('control');
        $this->assertSame('470.00', $control['accounts_receivable']['gl_amount']);
        $this->assertSame('459.00', $control['accounts_payable']['gl_amount']);
        $this->assertTrue($control->every(fn ($row) => $row['reconciled']));
        $this->assertTrue($accounting->trialBalance(null, $date)['balanced']);
        $balance = $accounting->balanceSheet($date);
        $this->assertSame('2279.00', $balance['assets']);
        $this->assertSame('1820.00', $balance['total_equity']);
        $profit = $accounting->profitAndLoss(null, $date);
        $this->assertSame('820.00', $profit['net_profit']);
        $this->assertSame('1445.00', $accounting->cashFlow(null, $date)['closing_cash']);
        $this->postJson("$path/tax-disposition", ['jurisdiction' => 'XK', 'regime' => 'actual_income',
            'regime_name' => 'Synthetic actual income', 'effective_from' => '2025-01-01', 'effective_to' => $date,
            'policy_reference' => 'Synthetic year-end policy version 1', 'reason' => 'Synthetic accounting approval for this fiscal year.'])->assertCreated();
        $taxInputs = ['rate_percent' => '10', 'policy_reference' => 'Synthetic actual-income policy. Separate book and tax depreciation.', 'adjustments' => [
            ['direction' => 'add', 'amount' => '1.00', 'reason' => 'Add back synthetic book depreciation.'],
            ['direction' => 'deduct', 'amount' => '0.60', 'reason' => 'Deduct independently approved synthetic tax depreciation.'],
        ]];
        $tax = $this->postJson("$path/tax-preview", $taxInputs)->assertOk()->assertJsonPath('profit_before_tax', '820.00')
            ->assertJsonPath('taxable_profit', '820.40')->assertJsonPath('current_tax', '82.04')->json();
        $taxInputs['fingerprint'] = $tax['fingerprint'];
        $taxId = $this->postJson("$path/tax-review", $taxInputs)->assertCreated()->json('id');
        $this->postJson("$path/tax-review", $taxInputs)->assertCreated()->assertJsonPath('id', $taxId);
        $this->assertSame('737.96', $accounting->profitAndLoss(null, $date)['net_profit']);
        $this->assertSame('541.04', $accounting->balanceSheet($date)['liabilities']);
        $this->assertSame('1737.96', $accounting->balanceSheet($date)['total_equity']);
        $this->putJson("$path/status", ['status' => 'closed', 'reason' => 'Synthetic close reviewed.'])->assertOk()->assertJsonPath('status', 'closed');
        $this->postJson("/api/accounting/fixed-assets/$asset/entries", [
            'kind' => 'impairment', 'date' => $date, 'amount' => 100, 'reason' => 'Attempt after the close.', 'request_key' => 'late-impair',
        ])->assertUnprocessable()->assertJsonPath('code', 'accounting_period_locked');
        $next = $accounting->balanceSheet('2026-01-01');
        $this->assertSame('737.96', $next['retained_earnings_brought_forward']);
        $this->assertSame('-16.00', $next['current_earnings']);
        $this->assertSame('1721.96', $next['total_equity']);
        // The following period starts after the prior revaluation has reversed.
        // USD 100 settles at 0.92 for EUR 92 against EUR 90 carrying value.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-01-31 14:00:00', 'Europe/Belgrade'));
        $this->postJson('/api/payments', ['invoice_id' => $invoice, 'financial_account_id' => $bank->id, 'amount' => 100,
            'payment_method' => 'bank_transfer', 'payment_date' => '2026-01-20', 'exchange_rate' => '0.92',
            'exchange_rate_date' => '2026-01-20', 'exchange_rate_source' => 'Synthetic following-period settlement',
            'idempotency_key' => 'january-settlement'])->assertCreated();
        $january = $this->postJson('/api/accounting/periods', ['name' => 'Synthetic January', 'starts_at' => '2026-01-01', 'ends_at' => '2026-01-31'])->assertCreated()->json('id');
        $path = "/api/accounting/periods/$january";
        $rates = ['USD' => ['exchange_rate' => '0.92', 'exchange_rate_date' => '2026-01-31', 'exchange_rate_source' => 'Synthetic following-period closing quote']];
        // AR USD 400: 368 - 360 = 8. AP USD 100: 92 - 90 = 2.
        $januaryFx = $this->postJson("$path/fx-preview", ['rates' => $rates])->assertOk()
            ->assertJsonPath('adjustments.ar', '8.00')->assertJsonPath('adjustments.ap', '2.00')->json();
        $approval = ['rates' => $rates, 'fingerprint' => $januaryFx['fingerprint'], 'reason' => 'Reviewed independent January rates.'];
        $id = $this->postJson("$path/fx-approve", $approval)->assertCreated()->json('id');
        $this->postJson("$path/fx-approve", $approval)->assertCreated()->assertJsonPath('id', $id);
        $januaryControl = collect(app(\App\Services\DatedFinancialReconciliation::class)->report('2026-01-31')['rows'])->keyBy('control');
        $this->assertSame('368.00', $januaryControl['accounts_receivable']['gl_amount']);
        $this->assertSame('457.00', $januaryControl['accounts_payable']['gl_amount']);
        $this->assertSame('-8.00', $accounting->balanceSheet('2026-01-31')['current_earnings']);
        $this->travelBack();
    }

    public function test_kosovo_cash_limits_use_the_complete_transaction_and_effective_date(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $date = \App\Support\CompanyClock::today()->toDateString();
        $payload = $this->payload($product, 3, $customer);
        $invoice = $this->postJson('/api/invoices', $payload)->assertCreated()->json('id');
        $this->postJson("/api/invoices/$invoice/issue")->assertOk()->assertJsonPath('grand_total', '354.00');
        $pay = ['invoice_id' => $invoice, 'amount' => 100, 'payment_date' => $date, 'payment_method' => 'cash', 'idempotency_key' => 'threshold-split'];
        $this->postJson('/api/payments', $pay)->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->postJson('/api/payments', [...$pay, 'payment_method' => 'bank_transfer', 'in_kind_amount' => '40.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('in_kind_amount');
        $cash = app(\App\Services\FinancialAccountService::class)->createAccount(['name' => 'Test cash', 'type' => 'cashbox', 'currency' => 'EUR', 'opening_balance' => 0]);
        $this->postJson('/api/payments', [...$pay, 'payment_method' => 'bank_transfer', 'financial_account_id' => $cash->id])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->postJson('/api/payments', [...$pay, 'payment_method' => 'bank_transfer'])->assertCreated();
        $this->postJson('/api/payments', [...$pay, 'idempotency_key' => 'remaining-split'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertDatabaseCount('payment_transactions', 1);
        $audit = \App\Models\ActivityLog::query()->where('action', 'accounting.kosovo_cash_control.checked')->where('entity_id', $invoice)->sole();
        $this->assertSame('08/L-257, art. 6(9)', $audit->new_value['rule']);
        $this->assertSame('electronic', $audit->new_value['basis']);

        $person = Customer::create($this->tenantAttributes(['name' => 'Synthetic private purchaser', 'customer_type' => 'individual']));
        $this->postJson("/api/customers/{$person->id}/debts", ['amount' => 2000.01, 'transaction_date' => '2026-06-01', 'idempotency_key' => 'private-receivable'])->assertCreated();
        $privatePayment = ['amount' => 50, 'transaction_date' => '2026-06-01', 'idempotency_key' => 'private-instalment'];
        $this->postJson("/api/customers/{$person->id}/payments", [...$privatePayment, 'payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->postJson("/api/customers/{$person->id}/payments", [...$privatePayment, 'payment_method' => 'card'])->assertCreated();

        $policy = app(\App\Services\Kosovo\CashPaymentPolicy::class);
        $this->assertNull($policy->threshold('2024-01-26', 'business', true));
        $this->assertSame(100, $policy->threshold('2024-01-27', 'business', true)['limit']);
        $this->assertSame(2000, $policy->threshold('2026-06-01', 'individual', true)['limit']);
        $policy->assertAllowed('2026-06-01', '300.00', 'business', 'cash');
        $policy->assertAllowed('2026-06-01', '2000.00', 'individual', 'cash');
        $policy->assertAllowed('2026-05-31', '2000.01', 'individual', 'cash');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $policy->assertAllowed('2026-06-01', '2000.01', 'individual', 'cash');
    }

    public function test_kosovo_invoice_profile_and_base_currency_cannot_conflict(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/settings/preferences', ['base_currency' => 'USD'])->assertOk();
        $this->putJson('/api/invoice-profile', $this->profile())->assertUnprocessable()->assertJsonValidationErrors('country_code');
        $this->putJson('/api/settings/preferences', ['base_currency' => 'EUR'])->assertOk();
        $this->putJson('/api/invoice-profile', $this->profile())->assertOk();
        $this->putJson('/api/settings/preferences', ['base_currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('base_currency');
    }

    public function test_company_base_invoice_snapshot_print_and_credit_keep_the_recorded_currency(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/settings/preferences', ['base_currency' => 'USD'])->assertOk();
        $this->putJson('/api/invoice-profile', $this->profile(['country_code' => 'US', 'is_vat_registered' => false]))->assertOk();
        $date = now('Europe/Belgrade');
        $invoice = $this->postJson('/api/invoices', [
            'buyer' => $this->buyer(), 'invoice_date' => $date->toDateString(), 'supply_date' => $date->toDateString(),
            'items' => [['description' => 'Fictional non-taxable sale', 'unit' => 'm', 'quantity' => 2.5, 'unit_price' => 10, 'vat_rate' => 0, 'tax_treatment' => 'non_vat']],
        ])->assertCreated()->assertJsonPath('currency', 'USD')->json('id');
        $this->postJson("/api/invoices/$invoice/issue")->assertOk()->assertJsonPath('grand_total', '25.00');
        $this->get("/api/invoices/$invoice/print")->assertOk()->assertSee('USD 25.00');
        $this->postJson("/api/invoices/$invoice/credit-note", ['reason' => 'Fictional order corrected'])->assertCreated()->assertJsonPath('currency', 'USD');
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'invoice', 'source_id' => $invoice, 'currency' => 'USD', 'total_debit' => 25, 'total_credit' => 25]);
    }

    public function test_invoice_profile_is_persistent_and_company_scoped(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/invoice-profile', $this->profile())
            ->assertOk()
            ->assertJsonPath('completeness.complete', true)
            ->assertJsonPath('profile.business_registration_number', '811234567');

        InvoiceProfile::withoutEvents(fn () => InvoiceProfile::withoutGlobalScopes()->create([
            'company_id' => Company::factory()->create()->id,
            ...$this->profile(['legal_name' => 'Foreign Company', 'business_registration_number' => '819999999']),
        ]));

        $this->getJson('/api/invoice-profile')
            ->assertOk()
            ->assertJsonPath('profile.legal_name', 'AIMS Test Company')
            ->assertJsonMissing(['legal_name' => 'Foreign Company']);
    }

    public function test_draft_can_be_edited_without_changing_stock_and_can_save_buyer(): void
    {
        [$product] = $this->setupInvoiceData();
        $payload = $this->payload($product, 2);
        $payload['customer_id'] = null;
        $payload['save_customer'] = true;
        $payload['buyer'] = $this->buyer(['legal_name' => 'Saved Buyer LLC', 'business_registration_number' => '811111111']);

        $created = $this->postJson('/api/invoices', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('grand_total', '236.00');
        $this->assertSame(20.0, (float) $product->fresh()->quantity);
        $this->assertDatabaseHas('customers', ['business_name' => 'Saved Buyer LLC', 'business_registration_number' => '811111111']);

        $payload['customer_id'] = $created->json('customer_id');
        $payload['save_customer'] = false;
        $payload['items'][0]['quantity'] = 3;
        $this->putJson('/api/invoices/'.$created->json('id'), $payload)
            ->assertOk()
            ->assertJsonPath('grand_total', '354.00');
        $this->assertSame(20.0, (float) $product->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sequential_issue_mixed_vat_math_and_stock_are_exactly_once(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $payload = $this->payload($product, 2, $customer);
        $payload['items'][0]['discount_percent'] = 10;
        $payload['payment_terms'] = '14';
        $payload['items'][] = [
            'description' => 'Reduced-rate manual item', 'unit' => 'pcs', 'quantity' => 1,
            'unit_price' => 50, 'vat_rate' => 8, 'tax_treatment' => 'standard',
        ];

        $first = $this->postJson('/api/invoices', $payload)->assertCreated();
        $issued = $this->postJson('/api/invoices/'.$first->json('id').'/issue')
            ->assertOk()
            ->assertJsonPath('status', 'issued')
            ->assertJsonPath('subtotal', '250.00')
            ->assertJsonPath('discount_total', '20.00')
            ->assertJsonPath('taxable_total', '230.00')
            ->assertJsonPath('vat_total', '36.40')
            ->assertJsonPath('grand_total', '266.40')
            ->assertJsonPath('payment_terms', 'Payment within 14 days');
        $this->assertMatchesRegularExpression('/^INV-\d{4}-000001$/', $issued->json('invoice_number'));
        $this->assertSame(18.0, (float) $product->fresh()->quantity);

        // A repeated issue request is idempotent and cannot deduct stock twice.
        $this->postJson('/api/invoices/'.$first->json('id').'/issue')->assertOk();
        $this->assertSame(18.0, (float) $product->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 1);

        $second = $this->postJson('/api/invoices', $this->payload($product, 1, $customer))->assertCreated();
        $secondIssued = $this->postJson('/api/invoices/'.$second->json('id').'/issue')->assertOk();
        $this->assertMatchesRegularExpression('/^INV-\d{4}-000002$/', $secondIssued->json('invoice_number'));
    }

    public function test_meter_decimals_work_and_piece_decimals_are_rejected(): void
    {
        [, $customer, $category] = $this->setupInvoiceData();
        $meter = $this->product($category, ['name' => 'Cable', 'sku' => 'CABLE-1', 'unit' => 'm', 'quantity' => 10]);
        $draft = $this->postJson('/api/invoices', $this->payload($meter, 1.5, $customer))->assertCreated();
        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $this->assertSame(8.5, (float) $meter->fresh()->quantity);

        $piece = $this->product($category, ['name' => 'Stand', 'sku' => 'STAND-1', 'unit' => 'pcs', 'quantity' => 10]);
        $this->postJson('/api/invoices', $this->payload($piece, 1.5, $customer))
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $albanianMeter = $this->product($category, ['name' => 'Tub', 'sku' => 'TUB-1', 'unit' => 'metra', 'quantity' => 10]);
        $albanianDraft = $this->postJson('/api/invoices', $this->payload($albanianMeter, 1.5, $customer))->assertCreated();
        $this->postJson('/api/invoices/'.$albanianDraft->json('id').'/issue')->assertOk();
        $this->assertSame(8.5, (float) $albanianMeter->fresh()->quantity);
    }

    public function test_insufficient_stock_rolls_back_issue_and_sequence_assignment(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $product->update(['quantity' => 1]);
        $draft = $this->postJson('/api/invoices', $this->payload($product, 2, $customer))->assertCreated();

        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertUnprocessable();
        $invoice = Invoice::findOrFail($draft->json('id'));
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertSame(1.0, (float) $product->fresh()->quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_issued_invoice_is_immutable_and_supports_idempotent_payments(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $payload = $this->payload($product, 2, $customer);
        $draft = $this->postJson('/api/invoices', $payload)->assertCreated();
        $issued = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $id = $issued->json('id');

        $this->putJson("/api/invoices/{$id}", $payload)->assertUnprocessable();
        $this->deleteJson("/api/invoices/{$id}")->assertUnprocessable();
        $this->postJson("/api/invoices/{$id}/void", ['reason' => 'Not permitted'])->assertUnprocessable();

        $payment = [
            'invoice_id' => $id, 'amount' => 100, 'payment_method' => 'bank_transfer',
            'payment_date' => '2026-08-10', 'idempotency_key' => 'invoice-payment-1',
        ];
        $this->postJson('/api/payments', $payment)->assertCreated()
            ->assertJsonPath('invoice.status', 'issued')
            ->assertJsonPath('invoice.payment_status', 'partially_paid');
        $this->postJson('/api/payments', $payment)->assertCreated();
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->postJson('/api/payments', [...$payment, 'amount' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $remaining = (float) Invoice::find($id)->remaining_balance;
        $this->postJson('/api/payments', [
            'invoice_id' => $id, 'amount' => $remaining, 'payment_method' => 'cash',
            'idempotency_key' => 'invoice-payment-2',
        ])->assertCreated()
            ->assertJsonPath('invoice.status', 'issued')
            ->assertJsonPath('invoice.payment_status', 'paid');
        $this->getJson('/api/invoices?payment_status=paid')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.status', 'issued');
    }

    public function test_full_credit_note_has_own_sequence_and_reverses_stock_once(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $draft = $this->postJson('/api/invoices', $this->payload($product, 2, $customer))->assertCreated();
        $invoice = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $this->assertSame(18.0, (float) $product->fresh()->quantity);

        $credit = $this->postJson('/api/invoices/'.$invoice->json('id').'/credit-note', ['reason' => 'Goods returned in full'])
            ->assertCreated()
            ->assertJsonPath('document_type', 'credit_note');
        $this->assertMatchesRegularExpression('/^CN-\d{4}-000001$/', $credit->json('invoice_number'));
        $this->assertSame(0.0, (float) $credit->json('remaining_balance'));
        $this->assertLessThan(0, (float) $credit->json('signed_total'));
        $this->assertSame(20.0, (float) $product->fresh()->quantity);
        $this->assertSame('credited', Invoice::find($invoice->json('id'))->status);

        $this->postJson('/api/invoices/'.$invoice->json('id').'/credit-note', ['reason' => 'Repeated request'])->assertCreated();
        $this->assertSame(20.0, (float) $product->fresh()->quantity);
        $this->assertSame(1, Invoice::where('document_type', 'credit_note')->count());
    }

    public function test_bilingual_pdf_is_generated_without_fake_fiscal_values(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $draft = $this->postJson('/api/invoices', $this->payload($product, 1, $customer))->assertCreated();
        $invoice = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $response = $this->getJson('/api/invoices/'.$invoice->json('id').'/pdf?locale=bilingual')
            ->assertOk()->assertJsonStructure(['pdf', 'filename']);
        $pdf = base64_decode($response->json('pdf'));
        $this->assertStringStartsWith('%PDF', $pdf);
        if ($capturePath = env('AIMS_PDF_CAPTURE_PATH')) {
            if (! is_dir(dirname($capturePath))) {
                mkdir(dirname($capturePath), 0775, true);
            }
            file_put_contents($capturePath, $pdf);
        }
        $this->assertNull(Invoice::find($invoice->json('id'))->external_fiscal_code);
    }

    public function test_invoice_numbers_are_scoped_per_company(): void
    {
        [$firstProduct, $firstCustomer] = $this->setupInvoiceData();
        $firstDraft = $this->postJson('/api/invoices', $this->payload($firstProduct, 1, $firstCustomer))->assertCreated();
        $first = $this->postJson('/api/invoices/'.$firstDraft->json('id').'/issue')->assertOk()->json('invoice_number');

        User::withoutGlobalScopes()->whereNotNull('api_token')->update(['api_token' => null]);
        [$secondProduct, $secondCustomer] = $this->setupInvoiceData();
        $secondDraft = $this->postJson('/api/invoices', $this->payload($secondProduct, 1, $secondCustomer))->assertCreated();
        $second = $this->postJson('/api/invoices/'.$secondDraft->json('id').'/issue')->assertOk()->json('invoice_number');

        $this->assertSame($first, $second);
        $this->assertSame(2, Invoice::withoutGlobalScopes()->where('invoice_number', $first)->count());
    }

    public function test_companyless_superadmin_cannot_access_tenant_invoice_data(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $token = 'companyless-superadmin-invoice-token';
        User::factory()->create([
            'company_id' => null, 'role' => 'superadmin', 'api_token' => hash('sha256', $token),
            'email_verified_at' => now(), 'must_change_password' => false,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/invoices')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/invoice-profile')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/export/invoices?format=json')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/dashboard')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/search?q=invoice')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/superadmin/companies')->assertOk();
    }

    public function test_payment_reversal_is_audited_and_allows_credit_after_all_payments_are_reversed(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $draft = $this->postJson('/api/invoices', $this->payload($product, 2, $customer))->assertCreated();
        $invoice = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $payment = $this->postJson('/api/payments', [
            'invoice_id' => $invoice->json('id'),
            'amount' => $invoice->json('grand_total'),
            'payment_method' => 'bank_transfer',
            'idempotency_key' => 'reversal-test-payment',
        ])->assertCreated()->assertJsonPath('invoice.payment_status', 'paid');

        $this->postJson('/api/invoices/'.$invoice->json('id').'/credit-note', ['reason' => 'Correction before reversal'])
            ->assertUnprocessable();
        $this->postJson('/api/payments/'.$payment->json('id').'/reverse', [])->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->postJson('/api/payments/'.$payment->json('id').'/reverse', ['reason' => 'Bank transfer was entered twice'])
            ->assertOk()
            ->assertJsonPath('status', 'reversed')
            ->assertJsonPath('reversal_reason', 'Bank transfer was entered twice')
            ->assertJsonPath('invoice.status', 'issued')
            ->assertJsonPath('invoice.payment_status', 'unpaid')
            ->assertJsonPath('invoice.total_paid', '0.00');

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $payment->json('id'),
            'status' => 'reversed',
            'reversal_reason' => 'Bank transfer was entered twice',
        ]);
        $this->assertNotNull(PaymentTransaction::findOrFail($payment->json('id'))->reversed_at);
        $this->postJson('/api/payments/'.$payment->json('id').'/reverse', ['reason' => 'Repeated reversal'])
            ->assertUnprocessable();

        $this->postJson('/api/invoices/'.$invoice->json('id').'/credit-note', ['reason' => 'Invoice cancelled after reversal'])
            ->assertCreated()
            ->assertJsonPath('document_type', 'credit_note');
        $this->assertSame(20.0, (float) $product->fresh()->quantity);
    }

    public function test_credit_note_export_signs_every_accounting_amount(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $payload = $this->payload($product, 1, $customer);
        $payload['items'][0]['discount_percent'] = 10;
        $draft = $this->postJson('/api/invoices', $payload)->assertCreated();
        $invoice = $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $this->postJson('/api/invoices/'.$invoice->json('id').'/credit-note', ['reason' => 'Full commercial correction'])
            ->assertCreated();

        $export = $this->getJson('/api/export/invoices?format=json')->assertOk()->json();
        $credit = collect($export)->firstWhere('Document Type', 'credit_note');
        $this->assertNotNull($credit);
        $this->assertSame(-100.0, (float) $credit['Subtotal']);
        $this->assertSame(-10.0, (float) $credit['Discount']);
        $this->assertSame(-90.0, (float) $credit['Taxable']);
        $this->assertSame(-16.2, (float) $credit['VAT']);
        $this->assertSame(-106.2, (float) $credit['Grand Total']);
        $this->assertSame(0.0, (float) $credit['Paid']);
        $this->assertSame(0.0, (float) $credit['Remaining']);
    }

    public function test_csv_export_neutralizes_formula_text_without_changing_numeric_totals(): void
    {
        [$product] = $this->setupInvoiceData();
        $payload = $this->payload($product, 1);
        $payload['buyer']['legal_name'] = '=2+3';
        $this->postJson('/api/invoices', $payload)->assertCreated();

        $response = $this->get('/api/export/invoices?format=csv')->assertOk();
        $lines = preg_split('/\r\n|\n|\r/', trim($response->streamedContent()));
        $headers = str_getcsv($lines[0]);
        $values = str_getcsv($lines[1]);
        $row = array_combine($headers, $values);

        $this->assertSame("'=2+3", $row['Customer']);
        $this->assertSame('118', rtrim(rtrim($row['Grand Total'], '0'), '.'));
        $this->assertStringStartsNotWith("'", $row['Grand Total']);

        StockMovement::create($this->tenantAttributes([
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => '-5.000',
            'quantity_before' => '0.000',
            'quantity_after' => '-5.000',
            'reason' => 'Legacy signed adjustment',
        ]));
        $stockResponse = $this->get('/api/export/stock_movements?format=csv')->assertOk();
        $stockLines = preg_split('/\r\n|\n|\r/', trim($stockResponse->streamedContent()));
        $stockHeaders = str_getcsv($stockLines[0]);
        $stockValues = str_getcsv($stockLines[1]);
        $stockRow = array_combine($stockHeaders, $stockValues);
        $this->assertSame('-5.000', $stockRow['Qty']);
        $this->assertStringStartsNotWith("'", $stockRow['Qty']);
    }

    public function test_issue_now_is_atomic_when_stock_validation_fails(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $product->update(['quantity' => 1]);
        $payload = $this->payload($product, 2, $customer);
        $payload['issue_now'] = true;

        $this->postJson('/api/invoices', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1.0, (float) $product->fresh()->quantity);
    }

    public function test_vat_seller_non_vat_line_requires_legal_basis_and_issued_product_cannot_be_deleted(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $payload = $this->payload($product, 1, $customer);
        $payload['items'][0]['tax_treatment'] = 'non_vat';
        $payload['items'][0]['vat_rate'] = 0;
        $draft = $this->postJson('/api/invoices', $payload)->assertCreated();
        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $payload['items'][0]['tax_legal_reference'] = 'Law No. 05/L-037, applicable legal treatment';
        $this->putJson('/api/invoices/'.$draft->json('id'), $payload)->assertOk();
        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')->assertOk();
        $this->deleteJson('/api/products/'.$product->id)->assertUnprocessable();
    }

    public function test_staff_can_view_but_cannot_edit_profile_or_save_customer(): void
    {
        $this->actingAsApiUser('staff');
        InvoiceProfile::create(['company_id' => $this->apiCompany->id, ...$this->profile()]);
        $category = Category::create($this->tenantAttributes(['name' => 'Staff Products']));
        $product = $this->product($category, ['sku' => 'STAFF-TAX-1']);

        $this->getJson('/api/invoice-profile')->assertOk();
        $this->putJson('/api/invoice-profile', $this->profile())->assertForbidden();
        $payload = $this->payload($product, 1);
        $payload['save_customer'] = true;
        $this->postJson('/api/invoices', $payload)->assertForbidden();
    }

    public function test_consumer_mixed_sales_mode_is_disabled_without_certified_fiscalization(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/invoice-profile', $this->profile(['sales_mode' => 'mixed']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_mode');
    }

    public function test_invoice_and_credit_note_prefixes_must_be_different_case_insensitively(): void
    {
        $this->actingAsApiUser();
        $this->putJson('/api/invoice-profile', $this->profile([
            'invoice_prefix' => 'INV',
            'credit_note_prefix' => 'inv',
        ]))->assertUnprocessable()->assertJsonValidationErrors('credit_note_prefix');
    }

    public function test_new_sequence_starts_after_matching_legacy_invoice_number(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $year = now('Europe/Belgrade')->year;
        Invoice::withoutGlobalScopes()->create([
            'company_id' => $this->apiCompany->id,
            'invoice_number' => "INV-{$year}-000007",
            'document_type' => 'invoice',
            'customer_name' => 'Legacy Buyer',
            'status' => 'paid',
            'compliance_status' => 'legacy',
            'currency' => 'EUR',
            'invoice_date' => "{$year}-01-10",
            'issued_at' => "{$year}-01-10 09:00:00",
            'subtotal' => 10,
            'taxable_total' => 10,
            'grand_total' => 10,
            'total_amount' => 10,
            'total_paid' => 10,
        ]);

        $draft = $this->postJson('/api/invoices', $this->payload($product, 1, $customer))->assertCreated();
        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')
            ->assertOk()
            ->assertJsonPath('invoice_number', "INV-{$year}-000008");
    }

    public function test_existing_profile_with_equal_prefixes_blocks_issue_and_credit_note(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $firstDraft = $this->postJson('/api/invoices', $this->payload($product, 1, $customer))->assertCreated();
        $issued = $this->postJson('/api/invoices/'.$firstDraft->json('id').'/issue')->assertOk();
        InvoiceProfile::query()->firstOrFail()->update(['credit_note_prefix' => 'inv']);

        $secondDraft = $this->postJson('/api/invoices', $this->payload($product, 1, $customer))->assertCreated();
        $this->postJson('/api/invoices/'.$secondDraft->json('id').'/issue')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');
        $this->postJson('/api/invoices/'.$issued->json('id').'/credit-note', ['reason' => 'Must not collide'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');
    }

    public function test_reverse_charge_requires_a_vat_registered_buyer(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $customer->update(['is_vat_registered' => false, 'vat_number' => null]);
        $payload = $this->payload($product, 1, $customer);
        $payload['items'][0]['tax_treatment'] = 'reverse_charge';
        $payload['items'][0]['vat_rate'] = 0;
        $payload['items'][0]['tax_legal_reference'] = 'Applicable reverse-charge provision';
        $draft = $this->postJson('/api/invoices', $payload)->assertCreated();

        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('buyer');
    }

    public function test_non_vat_seller_can_issue_only_non_vat_zero_rate_lines(): void
    {
        [$product, $customer] = $this->setupInvoiceData();
        $this->putJson('/api/invoice-profile', $this->profile([
            'is_vat_registered' => false,
            'vat_number' => null,
        ]))->assertOk();
        $payload = $this->payload($product, 1, $customer);
        $payload['items'][0]['tax_treatment'] = 'exempt';
        $payload['items'][0]['vat_rate'] = 0;
        $payload['items'][0]['tax_legal_reference'] = 'Purported exemption';
        $draft = $this->postJson('/api/invoices', $payload)->assertCreated();

        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $payload['items'][0]['tax_treatment'] = 'non_vat';
        $payload['items'][0]['tax_legal_reference'] = null;
        $this->putJson('/api/invoices/'.$draft->json('id'), $payload)->assertOk();
        $this->postJson('/api/invoices/'.$draft->json('id').'/issue')
            ->assertOk()
            ->assertJsonPath('vat_total', '0.00');
    }

    private function setupInvoiceData(): array
    {
        $this->actingAsApiUser();
        $this->putJson('/api/invoice-profile', $this->profile())->assertOk();
        $category = Category::create($this->tenantAttributes(['name' => 'Invoice Products']));
        $product = $this->product($category);
        $customer = Customer::create($this->tenantAttributes([
            'name' => 'Buyer', 'business_name' => 'Buyer LLC', 'customer_type' => 'business',
            'business_registration_number' => '819876543', 'fiscal_number' => '600123456',
            'is_vat_registered' => true, 'vat_number' => '330123456',
            'address' => 'Buyer Street 2', 'billing_address' => 'Buyer Street 2',
            'municipality' => 'Prishtinë', 'postal_code' => '10000', 'country_code' => 'XK',
        ]));

        return [$product, $customer, $category];
    }

    private function product(Category $category, array $overrides = []): Product
    {
        return Product::create($this->tenantAttributes(array_merge([
            'category_id' => $category->id, 'name' => 'Tax Product', 'sku' => 'TAX-1',
            'quantity' => 20, 'unit' => 'pcs', 'min_quantity' => 1, 'high_stock_threshold' => 50,
            'price' => 100, 'purchase_price' => 60, 'selling_price' => 100,
            'vat_rate' => 18, 'tax_treatment' => 'standard',
        ], $overrides)));
    }

    private function payload(Product $product, float $quantity, ?Customer $customer = null): array
    {
        $date = now('Europe/Belgrade');

        return [
            'customer_id' => $customer?->id,
            'buyer' => $customer ? null : $this->buyer(),
            'invoice_date' => $date->toDateString(), 'supply_date' => $date->toDateString(), 'due_date' => $date->copy()->addDays(14)->toDateString(),
            'items' => [[
                'product_id' => $product->id, 'description' => $product->name, 'unit' => $product->unit,
                'quantity' => $quantity, 'unit_price' => 100, 'vat_rate' => 18, 'tax_treatment' => 'standard',
            ]],
        ];
    }

    private function profile(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'AIMS Test Company', 'trade_name' => 'AIMS Test',
            'business_registration_number' => '811234567', 'fiscal_number' => '600987654',
            'is_vat_registered' => true, 'vat_number' => '330987654',
            'registered_address' => 'Mother Teresa Square 1', 'municipality' => 'Prishtinë',
            'postal_code' => '10000', 'country_code' => 'XK', 'phone' => '+38338000000',
            'email' => 'billing@example.test', 'bank_name' => 'Test Bank', 'bank_account' => '123456',
            'iban' => 'XK051212012345678906', 'swift_bic' => 'TESTXKPR',
            'invoice_prefix' => 'INV', 'credit_note_prefix' => 'CN', 'default_language' => 'bilingual',
            'default_payment_terms_days' => 14, 'default_payment_terms' => 'Payment within 14 days',
            'sales_mode' => 'business_only',
        ], $overrides);
    }

    private function buyer(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Manual Buyer LLC', 'business_registration_number' => '812222222',
            'fiscal_number' => '600222222', 'is_vat_registered' => true, 'vat_number' => '330222222',
            'address' => 'Manual Buyer Street', 'municipality' => 'Prishtinë', 'postal_code' => '10000',
            'country_code' => 'XK', 'email' => 'buyer@example.test',
        ], $overrides);
    }
}
