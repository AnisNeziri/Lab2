<?php

namespace Tests\Feature;

use App\Contracts\DocumentStorageProvider;
use App\Models\BusinessEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerDebtTransaction;
use App\Models\DocumentType;
use App\Models\Expense;
use App\Models\FinancialAccountTransaction;
use App\Models\JournalEntry;
use App\Models\OnboardingRow;
use App\Models\OnboardingSession;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\CustomerDebtService;
use App\Services\FinanceService;
use App\Services\Onboarding\OnboardingReconciliation;
use App\Services\Onboarding\OnboardingSchema;
use App\Support\CompanyClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ControlledOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const PHRASE = 'synthetic-opening-backup-2026';

    private function stage(): int
    {
        $this->actingAsApiUser();
        $this->getJson('/api/me')->assertOk();
        $r = $this->postJson('/api/onboarding', [
            'name' => 'Synthetic controlled opening', 'source_period' => 'Reviewed 2026 closing statements', 'cutover_date' => '2026-01-01', 'currency' => 'EUR', 'timezone' => 'Europe/Tirane',
            'expected_totals' => ['inventory_value' => '31.25', 'receivables' => '3700', 'customer_advances' => '500', 'payables' => '25000', 'supplier_advances' => '6000', 'cash' => '0', 'bank' => '10000', 'equity' => '-5768.75', 'inventory_units' => ['m' => '12.500']],
        ])->assertCreated();
        $id = $r->json('session.id');
        foreach (array_keys(OnboardingSchema::DOMAINS) as $domain) {
            $this->post('/api/onboarding/'.$id.'/sources', ['domain' => $domain, 'revision' => OnboardingSession::findOrFail($id)->revision, 'file' => UploadedFile::fake()->createWithContent($domain.'.csv', OnboardingSchema::template($domain))], ['Accept' => 'application/json'])->assertOk();
        }

        return $id;
    }

    private function validateApprove(int $id): array
    {
        $this->postJson('/api/onboarding/'.$id.'/validate', ['revision' => OnboardingSession::findOrFail($id)->revision])->assertOk()->assertJsonPath('reconciliation.blocking', []);
        $this->postJson('/api/onboarding/'.$id.'/backups', ['revision' => OnboardingSession::findOrFail($id)->revision, 'kind' => 'pre', 'passphrase' => self::PHRASE])->assertOk();
        $s = OnboardingSession::findOrFail($id);
        $this->postJson('/api/onboarding/'.$id.'/review', ['revision' => $s->revision, 'source_totals_confirmed' => true, 'warnings_acknowledged' => true])->assertOk();
        $s->refresh();
        $payload = ['revision' => $s->revision, 'fingerprint' => $s->review_fingerprint, 'confirmed' => true];
        $this->postJson('/api/onboarding/'.$id.'/approve', $payload)->assertOk();

        return $payload;
    }

    public function test_full_dry_run_bounded_posting_financial_separation_and_independent_restore(): void
    {
        $id = $this->stage();
        $this->assertSame(11, OnboardingRow::count());
        $documentRoot = storage_path('app/testing-documents/'.Str::uuid());
        config(['synthetic.document_root' => $documentRoot]);
        app()->forgetInstance(DocumentStorageProvider::class);
        $this->getJson('/api/documents/config')->assertOk();
        $this->post('/api/documents', ['title' => 'Synthetic opening statement', 'document_type_id' => DocumentType::first()->id, 'file' => UploadedFile::fake()->createWithContent('opening-evidence.txt', 'Synthetic opening evidence only')], ['Accept' => 'application/json'])->assertCreated();
        foreach (['products', 'customers', 'suppliers', 'warehouses', 'stock_movements', 'customer_debt_transactions', 'expenses', 'purchase_order_payments', 'journal_entries', 'invoices', 'purchase_orders', 'goods_receipts', 'daily_sales'] as $table) {
            $this->assertSame(0, DB::table($table)->where('company_id', $this->apiCompany->id)->count(), $table.' must remain empty in staging');
        }
        $payload = $this->validateApprove($id);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, JournalEntry::count());
        for ($i = 0; $i < 10 && ! OnboardingSession::findOrFail($id)->posted_at; $i++) {
            $this->postJson('/api/onboarding/'.$id.'/post', $payload + ['limit' => 3])->assertOk()->assertJsonPath('failure', null);
        }
        $this->assertSame('posted', OnboardingSession::findOrFail($id)->status);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(12.5, Product::first()->quantity);
        $this->assertSame('3700.00', Customer::first()->current_debt);
        $this->assertSame('500.00', Customer::first()->current_credit);
        $this->assertSame('opening_balance', Expense::first()->document_type);
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertNull(PurchaseOrderPayment::first()->purchase_order_id);
        $this->assertSame('6000.00', PurchaseOrderPayment::first()->unallocated_amount);
        $count = JournalEntry::count();
        $this->postJson('/api/onboarding/'.$id.'/post', $payload)->assertOk();
        $this->assertSame($count, JournalEntry::count());
        $this->postJson('/api/onboarding/'.$id.'/verify')->assertOk()->assertJsonPath('session.verification.passed', true);
        $this->postJson('/api/onboarding/'.$id.'/backups', ['revision' => $payload['revision'], 'kind' => 'post', 'passphrase' => self::PHRASE])->assertOk();
        $filesBefore = File::allFiles($documentRoot);
        $oldDocumentRoot = getenv('AIMS_DOCUMENT_ROOT');
        putenv('AIMS_DOCUMENT_ROOT='.$documentRoot);
        try {
            $this->postJson('/api/onboarding/'.$id.'/restore-verify', ['passphrase' => self::PHRASE])->assertOk()->assertJsonPath('passed', true)->assertJsonPath('result.broken_source_links', 0)->assertJsonPath('result.totals.equity', '-5768.75')->assertJsonPath('result.documents_checked', 1)->assertJsonPath('result.documents_isolated', true);
        } finally {
            putenv($oldDocumentRoot === false ? 'AIMS_DOCUMENT_ROOT' : 'AIMS_DOCUMENT_ROOT='.$oldDocumentRoot);
        }
        $this->assertCount(count($filesBefore), File::allFiles($documentRoot), 'The child restore must never write into the original document root');
        $this->postJson('/api/onboarding/'.$id.'/lock', $payload)->assertOk()->assertJsonPath('session.status', 'locked');
        $this->getJson('/api/onboarding/'.$id.'/rows?domain=supplier_advances')->assertOk()->assertJsonPath('data.0.can_reverse_advance', true)->assertJsonPath('data.0.current_position_status', 'completed');
        $account = \App\Models\FinancialAccount::firstOrFail();
        $this->putJson('/api/finance/accounts/'.$account->id, ['opening_balance' => '1.00'])->assertUnprocessable();
        $this->putJson('/api/finance/accounts/'.$account->id, ['opening_date' => '2026-02-01'])->assertUnprocessable();
        $this->putJson('/api/finance/accounts/'.$account->id, ['name' => 'Reviewed bank display name'])->assertOk();
        $this->assertSame('10000.00', $account->fresh()->opening_balance);
        $this->patchJson('/api/onboarding/'.$id.'/rows', ['revision' => $payload['revision'], 'rows' => [['id' => OnboardingRow::first()->id, 'overrides' => ['name' => 'Attempted mutation']]]])->assertUnprocessable();
        $this->get('/api/onboarding/'.$id.'/evidence')->assertOk()->assertSee('Independent restore: PASS');
        $advance = CustomerDebtTransaction::where('type', 'opening_advance')->first();
        app(CustomerDebtService::class)->correct($advance, ['amount' => '400', 'transaction_date' => CompanyClock::today()->toDateString(), 'reason' => 'Reviewed current-period correction', 'note' => 'Correct opening advance']);
        $this->assertSame('3700.00', Customer::first()->current_debt);
        $this->assertSame('400.00', Customer::first()->current_credit);
        $payment = PurchaseOrderPayment::first();
        $this->postJson('/api/purchase-order-payments/'.$payment->id.'/reverse', ['reason' => 'Reviewed opening supplier advance correction'])->assertOk();
        $this->assertSame(0, FinancialAccountTransaction::count());
        $this->assertTrue(app(AccountingService::class)->reconciliation(false)['all_reconciled']);
        $this->getJson('/api/onboarding/'.$id)->assertOk()->assertJsonPath('reconciliation.actual.customer_advances', '500.00')->assertJsonPath('reconciliation.actual.supplier_advances', '6000.00')->assertJsonPath('reconciliation.blocking', []);
        $books = app(FinanceService::class)->vatBooks('2026-01-01', '2026-12-31');
        $this->assertSame([], $books['purchase_book']);
        $this->assertSame(0, BusinessEvent::where('event_type', 'supplier.payable_recognized')->count());
    }

    public function test_row_failure_rolls_back_authorities_and_resumes_without_duplicate_movement(): void
    {
        $id = $this->stage();
        $payload = $this->validateApprove($id);
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && preg_match('/^insert into ["`]stock_movements["`]/i', $query->sql)) {
                $fail = false;
                throw new \RuntimeException('Injected opening stock interruption');
            }
        });
        $this->postJson('/api/onboarding/'.$id.'/post', $payload + ['limit' => 50])->assertOk()->assertJsonPath('session.status', 'posting_failed');
        $this->assertFalse($fail);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0.0, Product::first()->quantity);
        $this->postJson('/api/onboarding/'.$id.'/cancel', ['revision' => $payload['revision'], 'reason' => 'Cannot cancel committed openings'])->assertUnprocessable();
        $this->postJson('/api/onboarding/'.$id.'/post', $payload + ['limit' => 50])->assertOk()->assertJsonPath('failure', null)->assertJsonPath('session.status', 'posted');
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(12.5, Product::first()->quantity);
    }

    public function test_source_changes_invalidate_approval_and_identical_upload_is_a_no_op(): void
    {
        $id = $this->stage();
        $payload = $this->validateApprove($id);
        $s = OnboardingSession::findOrFail($id);
        $this->post('/api/onboarding/'.$id.'/sources', ['domain' => 'products', 'revision' => $s->revision, 'file' => UploadedFile::fake()->createWithContent('products.csv', OnboardingSchema::template('products'))], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(11, OnboardingRow::count());
        $this->assertSame($s->revision, $s->fresh()->revision);
        $row = OnboardingRow::where('domain', 'products')->first();
        $this->patchJson('/api/onboarding/'.$id.'/rows', ['revision' => $s->revision, 'rows' => [['id' => $row->id, 'overrides' => ['name' => 'Reviewed new name']]]])->assertOk()->assertJsonPath('session.approved_fingerprint', null);
        $this->postJson('/api/onboarding/'.$id.'/post', $payload)->assertStatus(409);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_existing_business_history_blocks_opening_and_staff_has_no_onboarding_permission(): void
    {
        $id = $this->stage();
        Product::create($this->tenantAttributes(['name' => 'Existing stock', 'sku' => 'EXIST', 'unit' => 'pcs', 'price' => 2, 'purchase_price' => 1, 'selling_price' => 2, 'quantity' => 1]));
        $this->assertFalse(app(OnboardingReconciliation::class)->operationallyEmpty());
        $user = auth()->user();
        $user->update(['role' => 'staff']);
        $this->getJson('/api/onboarding/'.$id)->assertForbidden();
    }

    public function test_duplicate_identity_requires_explicit_review_and_foreign_match_is_rejected(): void
    {
        $id = $this->stage();
        $existing = Product::create($this->tenantAttributes(['name' => 'Milano existing identity', 'sku' => 'MIL-04', 'unit' => 'm', 'price' => 5, 'purchase_price' => 2.5, 'selling_price' => 5, 'quantity' => 0]));
        $this->postJson('/api/onboarding/'.$id.'/validate', ['revision' => OnboardingSession::find($id)->revision])->assertOk();
        $row = OnboardingRow::where('domain', 'products')->first();
        $this->assertSame('review', $row->status);
        $this->assertSame($existing->id, $row->candidates[0]['id']);
        $this->patchJson('/api/onboarding/'.$id.'/rows', ['revision' => OnboardingSession::find($id)->revision, 'rows' => [['id' => $row->id, 'action' => 'use_existing', 'match_id' => 9999999]]])->assertOk();
        $this->postJson('/api/onboarding/'.$id.'/validate', ['revision' => OnboardingSession::find($id)->revision])->assertOk();
        $this->assertContains('unauthorized_or_missing_match', $row->fresh()->errors);
        $this->patchJson('/api/onboarding/'.$id.'/rows', ['revision' => OnboardingSession::find($id)->revision, 'rows' => [['id' => $row->id, 'action' => 'use_existing', 'match_id' => $existing->id]]])->assertOk();
        $this->postJson('/api/onboarding/'.$id.'/validate', ['revision' => OnboardingSession::find($id)->revision])->assertOk()->assertJsonPath('reconciliation.blocking', []);
        $this->assertSame('valid', $row->fresh()->status);
        $this->assertSame(1, Product::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_explicit_comma_decimals_and_unit_aliases_normalize_without_guessing(): void
    {
        $id = $this->stage();
        $csv = "external_ref;product_ref;warehouse_ref;location_ref;quantity;unit;unit_cost;value;stock_state;reference\nI001;P001;W001;;12,500;meters;2,500000;31,25;available;Reviewed count\n";
        $this->post('/api/onboarding/'.$id.'/sources', ['domain' => 'inventory', 'revision' => OnboardingSession::find($id)->revision, 'delimiter' => 'semicolon', 'decimal_separator' => ',', 'file' => UploadedFile::fake()->createWithContent('comma.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        // A different source is additional data until explicitly excluded; it is never silently replaced.
        $old = OnboardingRow::where('domain', 'inventory')->oldest('id')->first();
        $this->patchJson('/api/onboarding/'.$id.'/rows', ['revision' => OnboardingSession::find($id)->revision, 'rows' => [['id' => $old->id, 'action' => 'skip', 'reason' => 'Superseded by reviewed decimal-format source']]])->assertOk();
        $this->postJson('/api/onboarding/'.$id.'/validate', ['revision' => OnboardingSession::find($id)->revision])->assertOk()->assertJsonPath('reconciliation.blocking', []);
        $row = OnboardingRow::where('domain', 'inventory')->latest('id')->first();
        $this->assertSame('12.500', $row->normalized['base_quantity']);
        $this->assertSame('2.500000', $row->normalized['unit_cost']);
        $this->assertContains('unit_alias_normalized', $row->warnings);
    }

    public function test_private_source_integrity_and_company_isolation(): void
    {
        $id = $this->stage();
        $payload = $this->validateApprove($id);
        $source = OnboardingSession::find($id)->batches()->first();
        $source->update(['source_base64' => base64_encode('Changed private source')]);
        $this->postJson('/api/onboarding/'.$id.'/post', $payload)->assertUnprocessable();
        $this->assertSame(0, StockMovement::count());
        $other = Company::factory()->create();
        $token = 'other-opening-owner';
        User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'email_verified_at' => now(), 'api_token' => hash('sha256', $token)]);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/onboarding/'.$id)->assertNotFound();
        $this->getJson('/api/onboarding/'.$id.'/rows')->assertNotFound();
        $this->get('/api/onboarding/'.$id.'/batches/'.$source->id.'/source', ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson('/api/search?q=Synthetic')->assertOk()->assertJsonMissingPath('onboarding_sessions');
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'what is blocking company onboarding'])->assertOk()->assertJsonPath('cards', []);
    }

    public function test_interrupted_source_can_resume_and_failed_file_can_be_excluded_without_partial_rows(): void
    {
        $id = $this->stage();
        $s = OnboardingSession::find($id);
        $batch = $s->batches()->where('domain', 'products')->first();
        $batch->rows()->delete();
        $batch->update(['status' => 'importing', 'parsed_count' => 0]);
        $s->update(['status' => 'importing']);
        $this->postJson('/api/onboarding/'.$id.'/batches/'.$batch->id.'/resume', ['revision' => $s->revision])->assertOk();
        $this->assertSame(1, $batch->rows()->count());
        $this->assertSame('staged', $batch->fresh()->status);
        $bad = "external_ref,sku,name,unit\n";
        for ($i = 0; $i < 101; $i++) {
            $bad .= "BAD{$i},BAD-{$i},Synthetic broken file,pcs\n";
        }$bad .= "Wrong,column,count\n";
        $this->post('/api/onboarding/'.$id.'/sources', ['domain' => 'products', 'revision' => $s->fresh()->revision, 'file' => UploadedFile::fake()->createWithContent('broken.csv', $bad)], ['Accept' => 'application/json'])->assertUnprocessable();
        $failed = $s->batches()->latest('id')->first();
        $this->assertSame('failed', $failed->status);
        $this->assertSame(0, $failed->rows()->count());
        $this->postJson('/api/onboarding/'.$id.'/batches/'.$failed->id.'/exclude', ['revision' => $s->fresh()->revision, 'reason' => 'Rejected malformed source; original retained'])->assertOk();
        $this->assertFalse($failed->fresh()->is_active);
        $this->assertNotEmpty($failed->fresh()->source_base64);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_staged_normalized_names_and_contacts_require_review_even_without_existing_masters(): void
    {
        $id = $this->stage();
        $s = OnboardingSession::find($id);
        $csv = "external_ref,sku,name,unit\nP002,MILANO-04,Milano-04,m\n";
        $this->post('/api/onboarding/'.$id.'/sources', ['domain' => 'products', 'revision' => $s->revision, 'file' => UploadedFile::fake()->createWithContent('name-variants.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/onboarding/'.$id.'/validate', ['revision' => $s->fresh()->revision])->assertOk();
        foreach (OnboardingRow::where('domain', 'products')->get() as $row) {
            $this->assertSame('review', $row->status);
            $this->assertNotEmpty($row->candidates[0]['staged_row_id']);
        }
        $this->assertSame(0, Product::count());
    }

    public function test_action_permission_without_view_is_rejected_before_any_source_mutation(): void
    {
        $id = $this->stage();
        $s = OnboardingSession::findOrFail($id);
        $permission = Permission::where('slug', 'onboarding.view')->firstOrFail();
        Role::where('slug', 'admin')->firstOrFail()->permissions()->detach($permission->id);
        Cache::forget('role_permissions:admin');
        $this->post('/api/onboarding/'.$id.'/sources', ['domain' => 'products', 'revision' => $s->revision, 'file' => UploadedFile::fake()->createWithContent('denied.csv', "external_ref,sku,name,unit\nDENIED,DENIED,Denied,pcs\n")], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame($s->revision, $s->fresh()->revision);
        $this->assertSame(11, OnboardingRow::count());
        $this->assertSame(11, $s->batches()->count());
    }

    public function test_separate_reviewer_policy_and_cutoff_day_are_enforced_before_posting(): void
    {
        $id = $this->stage();
        $s = OnboardingSession::findOrFail($id);
        $s->update(['separate_reviewer_required' => true, 'cutover_date' => CarbonImmutable::now($s->timezone)->toDateString()]);
        $this->postJson('/api/onboarding/'.$id.'/validate', ['revision' => $s->revision])->assertOk();
        $this->postJson('/api/onboarding/'.$id.'/backups', ['revision' => $s->fresh()->revision, 'kind' => 'pre', 'passphrase' => self::PHRASE])->assertOk();
        $this->postJson('/api/onboarding/'.$id.'/review', ['revision' => $s->fresh()->revision, 'source_totals_confirmed' => true, 'warnings_acknowledged' => true])->assertOk();
        $s->refresh();
        $payload = ['revision' => $s->revision, 'fingerprint' => $s->review_fingerprint, 'confirmed' => true];
        $this->postJson('/api/onboarding/'.$id.'/approve', $payload)->assertUnprocessable();
        $this->assertNull($s->fresh()->approved_at);
        $other = User::factory()->create(['company_id' => $this->apiCompany->id, 'role' => 'admin', 'api_token' => hash('sha256', 'other-reviewer'), 'email_verified_at' => now()]);
        $this->withHeader('Authorization', 'Bearer other-reviewer')->postJson('/api/onboarding/'.$id.'/approve', $payload)->assertOk();
        $this->postJson('/api/onboarding/'.$id.'/post', $payload)->assertUnprocessable();
        $this->assertNull($s->fresh()->posting_started_at);
        $this->assertSame(0, Product::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_fractional_rounding_overflow_and_numeric_identifiers_block_safely_in_staging(): void
    {
        $id = $this->stage();
        $s = OnboardingSession::findOrFail($id);
        $inventory = OnboardingRow::where('domain', 'inventory')->firstOrFail();
        $this->patchJson('/api/onboarding/'.$id.'/rows', ['revision' => $s->revision, 'rows' => [['id' => $inventory->id, 'overrides' => ['quantity' => '0.001', 'unit_cost' => '5', 'value' => '']]]])->assertOk();
        $this->post('/api/onboarding/'.$id.'/sources', ['domain' => 'inventory', 'revision' => $s->fresh()->revision, 'file' => UploadedFile::fake()->createWithContent('rounding.csv', "external_ref,product_ref,warehouse_ref,quantity,unit,unit_cost\nI002,P001,W001,0.001,m,5\n")], ['Accept' => 'application/json'])->assertOk();
        $result = $this->postJson('/api/onboarding/'.$id.'/validate', ['revision' => $s->fresh()->revision])->assertOk();
        $this->assertContains('inventory_rounding_requires_review', $result->json('reconciliation.blocking'), json_encode(OnboardingRow::where('domain', 'inventory')->get()->map->only(['status', 'normalized', 'errors'])));
        $this->patchJson('/api/onboarding/'.$id.'/rows', ['revision' => $s->fresh()->revision, 'rows' => [['id' => $inventory->id, 'overrides' => ['quantity' => '999999999', 'unit_cost' => '999999999.999999']]]])->assertOk();
        $this->post('/api/onboarding/'.$id.'/sources', ['domain' => 'products', 'revision' => $s->fresh()->revision, 'file' => UploadedFile::fake()->createWithContent('numeric.json', '[{"external_ref":"PRECISION","sku":1e22,"name":"Preserve exact digits","unit":"pcs"}]')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/onboarding/'.$id.'/validate', ['revision' => $s->fresh()->revision])->assertOk();
        $this->assertContains('inventory_value_over_limit', $inventory->fresh()->errors);
        $this->assertContains('identifier_requires_text', OnboardingRow::where('external_ref', 'PRECISION')->firstOrFail()->errors);
        $this->assertSame(0, Product::count());
        $this->assertSame(0, JournalEntry::count());
    }
}
