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
