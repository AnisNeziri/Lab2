<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\CorporateTaxDisposition;
use App\Models\CorporateTaxReview;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\InvoiceProfile;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountingTaxIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function annual(string $jurisdiction = 'XK', string $start = '2025-01-01', string $end = '2025-12-31'): string
    {
        $this->actingAsApiUser();
        $this->travelTo(Carbon::parse('2026-03-01 14:00:00', 'Europe/Belgrade'));
        InvoiceProfile::create($this->tenantAttributes(['legal_name' => 'Synthetic tax controls', 'country_code' => $jurisdiction, 'registered_address' => 'Audit office']));
        $this->postJson('/api/accounting/initialize')->assertOk();

        return '/api/accounting/periods/'.$this->postJson('/api/accounting/periods', ['name' => 'Synthetic period', 'starts_at' => $start, 'ends_at' => $end])->assertCreated()->json('id');
    }

    private function disposition(string $path, array $overrides = []): array
    {
        return $this->postJson("$path/tax-disposition", [
            'regime' => 'actual_income', 'regime_name' => 'Synthetic actual-income regime', 'jurisdiction' => 'XK',
            'effective_from' => '2025-01-01', 'effective_to' => '2025-12-31', 'policy_reference' => 'Synthetic tax policy v1',
            'reason' => 'Synthetic accountable treatment evidence for the isolated company.', ...$overrides,
        ])->assertCreated()->json();
    }

    private function state(string $path, string $state): void
    {
        $this->getJson("$path/tax-disposition")->assertOk()->assertJsonPath('state', $state);
    }

    private function close(string $path, int $status): void
    {
        $this->putJson("$path/status", ['status' => 'closed', 'reason' => 'Approved audit and final period close.'])->assertStatus($status);
    }

    private function input(array $adjustments = []): array
    {
        return ['rate_percent' => '10', 'policy_reference' => 'Synthetic approved tax rate and workbook', 'adjustments' => $adjustments];
    }

    private function approve(string $path, array $input): array
    {
        $fingerprint = $this->postJson("$path/tax-preview", $input)->assertOk()->json('fingerprint');

        return $this->postJson("$path/tax-review", [...$input, 'fingerprint' => $fingerprint])->assertCreated()->json();
    }

    private function journal(string $key, string $total, string $date = '2025-12-31', string $debit = 'operating_expense'): JournalEntry
    {
        return app(AccountingService::class)->postMapped('test', 'test', 1, $key, $date, 'Synthetic tax evidence', [
            ['mapping' => $debit, 'debit' => $total], ['mapping' => 'sales_revenue', 'credit' => $total],
        ]);
    }

    public function test_year_close_requires_authorized_supported_disposition_and_rechecks_after_reopening(): void
    {
        $path = $this->annual();
        $this->state($path, 'missing_policy');
        $this->close($path, 422);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('corporate_tax_dispositions', 0);
        $this->assertSame(0, ActivityLog::where('action', 'accounting.period.closed')->count());
        $admin = Auth::user();
        $employee = User::factory()->create($this->tenantAttributes(['role' => 'employee', 'email_verified_at' => now()]));
        $this->renewApiSession($employee);
        $this->postJson("$path/tax-disposition", [])->assertForbidden();
        $this->renewApiSession($admin);

        $this->disposition($path, ['regime' => 'unsupported']);
        $this->state($path, 'unsupported');
        $this->close($path, 422);
        $this->disposition($path, ['regime' => 'unresolved']);
        $this->state($path, 'unresolved');
        $this->disposition($path);
        $this->state($path, 'review_required');
        $this->close($path, 422);

        $this->approve($path, $this->input());
        $this->state($path, 'approved');
        $this->close($path, 200);
        $this->close($path, 200);
        $this->assertSame(1, ActivityLog::where('action', 'accounting.period.closed')->count());
        $this->assertDatabaseCount('journal_entries', 0);
        $this->putJson("$path/status", ['status' => 'open', 'reason' => 'Authorized adjustment review.'])->assertOk();
        $this->state($path, 'stale_policy');
        $this->close($path, 422);
        $this->disposition($path);
        $this->state($path, 'stale_review');
        $this->approve($path, $this->input());
        $this->close($path, 200);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_month_close_and_evidenced_non_kosovo_dispositions_follow_the_existing_scope(): void
    {
        $month = $this->annual('XK', '2026-01-01', '2026-01-31');
        $this->getJson("$month/readiness")->assertOk()->assertJsonPath('corporate_tax.state', 'not_annual');
        $this->close($month, 200);
        $this->assertSame('interim', AccountingPeriod::query()->sole()->close_snapshot['period_scope']);

        InvoiceProfile::query()->firstOrFail()->update(['country_code' => 'DE']);
        $year = $this->postJson('/api/accounting/periods', ['name' => 'Synthetic foreign fiscal year', 'starts_at' => '2025-01-01', 'ends_at' => '2025-12-31'])->assertCreated()->json('id');
        $path = "/api/accounting/periods/$year";
        $this->close($path, 422);
        $this->disposition($path, ['jurisdiction' => 'DE']);
        $this->state($path, 'unsupported');
        $this->postJson("$path/tax-preview", $this->input())->assertUnprocessable();
        $this->disposition($path, ['jurisdiction' => 'DE', 'regime' => 'inapplicable']);
        $this->state($path, 'inapplicable');
        $this->journal('changed-exemption-evidence', '1.00');
        $this->state($path, 'stale_policy');
        $this->disposition($path, ['jurisdiction' => 'DE', 'regime' => 'alternative_reviewed', 'recorded_tax' => '0.00']);
        $this->state($path, 'alternative_reviewed');
        $this->close($path, 200);
        $this->assertSame('annual', AccountingPeriod::query()->latest('id')->firstOrFail()->close_snapshot['period_scope']);
    }

    public static function unsupportedYearEndRanges(): array
    {
        return [
            'December disguised as interim' => ['2025-12-01', '2025-12-31'],
            'one-day-short annual range' => ['2025-01-02', '2025-12-31'],
            'range crossing the fiscal boundary' => ['2025-12-15', '2026-01-15'],
            'complete year with a trailing stub' => ['2025-01-01', '2026-01-15'],
        ];
    }

    #[DataProvider('unsupportedYearEndRanges')]
    public function test_year_end_cannot_be_closed_as_an_interim_or_approved_by_an_exemption(string $from, string $to): void
    {
        $path = $this->annual('XK', $from, $to);
        $this->getJson("$path/readiness")->assertOk()->assertJsonPath('corporate_tax.state', 'unsupported_year_end')
            ->assertJsonPath('corporate_tax.period_scope', 'unsupported_year_end')->assertJsonPath('ready_to_close', false);
        $this->postJson("$path/tax-disposition", [
            'regime' => 'inapplicable', 'regime_name' => 'Synthetic exception', 'jurisdiction' => 'XK',
            'effective_from' => $from, 'effective_to' => $to, 'policy_reference' => 'Synthetic tax policy',
            'reason' => 'Synthetic disposition used to test the date-range boundary.',
        ])->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->close($path, 422);
        $this->putJson("$path/status", ['status' => 'soft_closed', 'reason' => 'Leave year-end adjustments under review.'])->assertOk()
            ->assertJsonPath('status', 'soft_closed')->assertJsonPath('close_snapshot', null);
        $this->close($path, 422);
        $this->assertDatabaseCount('corporate_tax_dispositions', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_configured_fiscal_boundary_cannot_be_rotated_after_period_creation(): void
    {
        $this->actingAsApiUser();
        $controls = ['supplier_match_quantity_tolerance' => 0.001, 'supplier_match_price_tolerance' => 0.02, 'supplier_match_tax_tolerance' => 0.02];
        $this->putJson('/api/accounting/controls', [...$controls, 'fiscal_year_start_month' => 4])->assertOk();
        $this->postJson('/api/accounting/initialize')->assertOk();
        $id = $this->postJson('/api/accounting/periods', [
            'name' => 'Final monthly range of April-start fiscal year', 'starts_at' => '2025-03-01', 'ends_at' => '2025-03-31',
        ])->assertCreated()->json('id');
        $this->putJson('/api/accounting/controls', [...$controls, 'fiscal_year_start_month' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('fiscal_year_start_month');
        $this->putJson('/api/accounting/controls', [...$controls, 'fiscal_year_start_month' => 4])->assertOk();
        $this->getJson("/api/accounting/periods/$id/readiness")->assertOk()->assertJsonPath('corporate_tax.state', 'unsupported_year_end');
        $this->close("/api/accounting/periods/$id", 422);
    }

    public function test_period_labels_cannot_qualify_a_short_non_boundary_range_as_annual(): void
    {
        $path = $this->annual('XK', '2025-01-01', '2025-06-30');
        $this->putJson("$path/status", [
            'status' => 'closed', 'reason' => 'Synthetic request to finalize a short year.', 'period_scope' => 'annual',
        ])->assertOk()->assertJsonPath('close_snapshot.period_scope', 'interim');
        $this->state($path, 'not_annual');
    }

    public function test_tax_reapproval_uses_source_composition_and_posts_only_the_approved_delta(): void
    {
        $path = $this->annual();
        $this->disposition($path);
        $initial = $this->journal('audit-income', '100.00', debit: 'cash');
        $first = $this->approve($path, $this->input());
        $this->assertSame('10.00', $first['snapshot']['current_tax']);
        $this->assertSame($first['id'], $this->approve($path, $this->input())['id']);
        $this->journal('unrelated-date', '1.00', '2026-01-01');
        $this->state($path, 'approved');
        $admin = Auth::user();
        $other = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => $other->id, 'role' => 'admin', 'email_verified_at' => now()]);
        $this->renewApiSession($otherUser);
        $this->getJson("$path/tax-disposition")->assertNotFound();
        $this->journal('other-company', '10.00');
        $this->renewApiSession($admin);
        $this->state($path, 'approved');

        $changed = $this->journal('audit-offset', '100.00');
        $this->state($path, 'stale_review');
        $this->postJson("$path/tax-review", [...$this->input(), 'fingerprint' => $first['fingerprint']])->assertUnprocessable();
        $this->close($path, 422);
        $adjustments = [
            ['direction' => 'add', 'amount' => '100.00', 'reason' => 'Nondeductible synthetic adjustment', 'journal_entry_id' => $changed->id],
            ['direction' => 'deduct', 'amount' => '20.00', 'reason' => 'Synthetic deduction evidence', 'journal_entry_id' => $initial->id],
        ];
        $second = $this->approve($path, $this->input($adjustments));
        $this->assertSame('18.00', $second['snapshot']['current_tax']);
        $this->assertSame($second['id'], $this->approve($path, $this->input(array_reverse($adjustments)))['id']);
        $this->assertSame('8.00', JournalEntry::findOrFail($second['journal_entry_id'])->total_debit);
        $this->assertSame('18.00', app(AccountingService::class)->profitAndLoss('2025-01-01', '2025-12-31')['income_tax']);
        $this->assertSame(2, JournalEntry::where('source_module', 'corporate_tax')->count());

        app(AccountingService::class)->reverse($changed, '2025-12-31', 'Synthetic correction removes the original transaction.');
        $this->state($path, 'stale_review');
        $latest = $this->approve($path, $this->input());
        $this->assertSame('8.00', JournalEntry::findOrFail($latest['journal_entry_id'])->total_debit);
        $this->assertSame('10.00', app(AccountingService::class)->profitAndLoss('2025-01-01', '2025-12-31')['income_tax']);
        $this->disposition($path, ['policy_reference' => 'Synthetic tax policy version 2']);
        $this->state($path, 'stale_review');
        $this->assertNull($this->approve($path, $this->input())['journal_entry_id']);
        $this->assertSame(3, JournalEntry::where('source_module', 'corporate_tax')->count());
    }

    public function test_legacy_fingerprints_require_requalification_and_reversed_tax_is_rejected(): void
    {
        $path = $this->annual();
        $this->disposition($path);
        $this->journal('audit-income', '40.00', debit: 'cash');
        $approval = $this->approve($path, $this->input());
        $record = CorporateTaxReview::findOrFail($approval['id']);
        $legacy = $record->snapshot;
        unset($legacy['fingerprint_version'], $legacy['posting_account_ids']);
        $record->update(['snapshot' => $legacy, 'fingerprint' => str_repeat('a', 64)]);
        $this->state($path, 'legacy_review');
        $this->close($path, 422);
        $this->assertNull($this->approve($path, $this->input())['journal_entry_id']);
        app(AccountingService::class)->reverse(JournalEntry::findOrFail($approval['journal_entry_id']), '2025-12-31', 'Synthetic unauthorized evidence drift probe.');
        $this->state($path, 'journal_conflict');
        $this->close($path, 422);
        $this->assertSame(2, CorporateTaxReview::count());
    }

    public function test_source_document_version_and_account_classification_invalidate_unchanged_totals(): void
    {
        $path = $this->annual();
        $this->disposition($path);
        $journal = $this->journal('support-document-source', '40.00');
        $type = DocumentType::create(['name' => 'Synthetic tax evidence', 'code' => 'tax-evidence-fixture']);
        $document = Document::create(['title' => 'Tax adjustment evidence', 'document_type_id' => $type->id,
            'status' => 'active', 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'created_by' => Auth::id()]);
        DocumentLink::create(['document_id' => $document->id, 'entity_type' => 'journal', 'entity_id' => $journal->id, 'linked_by' => Auth::id()]);
        $base = ['document_id' => $document->id, 'filename' => 'tax.pdf', 'mime_type' => 'application/pdf', 'size' => 32,
            'provider' => 'local', 'uploaded_by' => Auth::id()];
        DocumentVersion::create([...$base, 'version' => 1, 'checksum' => str_repeat('a', 64), 'storage_key' => 'synthetic-tax-v1']);
        $input = $this->input([['direction' => 'add', 'amount' => '4.00', 'reason' => 'Source-document-based addition', 'journal_entry_id' => $journal->id]]);
        $first = $this->approve($path, $input);
        DocumentVersion::create([...$base, 'version' => 2, 'checksum' => str_repeat('b', 64), 'storage_key' => 'synthetic-tax-v2']);
        $document->update(['current_version' => 2]);
        $this->state($path, 'stale_review');
        $second = $this->approve($path, $input);
        $this->assertNotSame($first['fingerprint'], $second['fingerprint']);
        $this->assertSame($first['snapshot']['profit_before_tax'], $second['snapshot']['profit_before_tax']);
        $account = $journal->lines->first()->account;
        $account->update(['statement_group' => 'other_expense']);
        $this->state($path, 'stale_review');
        $this->assertSame('0.00', app(AccountingService::class)->profitAndLoss('2025-01-01', '2025-12-31')['profit_before_tax']);
    }

    public function test_portable_tax_restore_preserves_history_and_requires_local_reapproval(): void
    {
        $path = $this->annual();
        $sourceCompany = $this->apiCompany;
        $this->disposition($path);
        $source = $this->journal('restored-tax-source', '50.00', debit: 'cash');
        $input = $this->input([['direction' => 'add', 'amount' => '1.00', 'reason' => 'Synthetic restored adjustment', 'journal_entry_id' => $source->id]]);
        $original = $this->approve($path, $input);
        $passphrase = 'synthetic DC39.3 audit fixture only';
        $backup = $this->postJson('/api/backup/export', ['modules' => ['finance'], 'passphrase' => $passphrase])->assertOk()->getContent();
        $target = Company::factory()->create();
        $targetUser = User::factory()->create(['company_id' => $target->id, 'role' => 'admin', 'email_verified_at' => now()]);
        $this->renewApiSession($targetUser);
        $this->post('/api/backup/import', ['file' => UploadedFile::fake()->createWithContent('tax-backup.json', $backup),
            'mode' => 'merge', 'passphrase' => 'Incorrect synthetic passphrase'])->assertUnprocessable();
        $this->assertDatabaseMissing('corporate_tax_reviews', ['company_id' => $target->id]);
        $this->post('/api/backup/import', ['file' => UploadedFile::fake()->createWithContent('tax-backup.json', $backup),
            'mode' => 'merge', 'passphrase' => $passphrase])->assertOk();
        $period = AccountingPeriod::where('company_id', $target->id)->sole();
        $destination = '/api/accounting/periods/'.$period->id;
        $this->state($destination, 'stale_policy');
        $restored = CorporateTaxReview::where('company_id', $target->id)->sole();
        $this->assertSame($original['fingerprint'], $restored->fingerprint);
        $this->assertTrue($restored->snapshot['historical']);
        $this->assertTrue(CorporateTaxDisposition::where('company_id', $target->id)->sole()->historical);
        $this->assertNotSame($source->id, $restored->snapshot['adjustments'][0]['journal_entry_id']);
        $this->assertSame($target->id, JournalEntry::findOrFail($restored->journal_entry_id)->company_id);
        $this->disposition($destination);
        $this->state($destination, 'stale_review');
        $this->assertNull($this->approve($destination, $this->input($restored->snapshot['adjustments']))['journal_entry_id']);
        $this->state($destination, 'approved');
        $this->assertDatabaseHas('corporate_tax_reviews', ['company_id' => $sourceCompany->id, 'id' => $original['id'], 'fingerprint' => $original['fingerprint']]);
    }
}
