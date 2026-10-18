<?php

namespace Tests\Feature;

use App\Models\AccountingException;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DailySale;
use App\Models\FinancialIntelligenceSnapshot;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\User;
use App\Services\AccountingRecoveryService;
use App\Services\AccountingService;
use App\Services\CustomerDebtService;
use App\Services\FinancialAccountService;
use App\Services\FinancialIntegrityChecks;
use App\Services\InstallationBackupService;
use App\Services\InventoryIntegrityService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DC2ControlCenterTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->actingAsApiUser('admin');
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        $s = app(AccountingService::class);
        $s->initialize();

        return [$s, collect($s->accounts())->keyBy('code')];
    }

    private function journal($s, $a, string $date, int $amount, string $key): JournalEntry
    {
        return $s->createAndPost(['posting_date' => $date, 'description' => 'Financial report fixture', 'source_module' => 'report_test', 'source_key' => $key, 'reference_number' => $key, 'lines' => [
            ['accounting_account_id' => $a['1000']->id, 'debit' => $amount], ['accounting_account_id' => $a['4000']->id, 'credit' => $amount],
        ]]);
    }

    public function test_control_summary_uses_posted_values_and_leaves_business_state_unchanged(): void
    {
        [$s,$a] = $this->context();
        app(FinancialAccountService::class)->createAccount(['name' => 'Opening bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => 1000, 'opening_date' => '2026-09-01', 'is_active' => true]);
        $s->createDraft(['posting_date' => '2026-09-30', 'description' => 'Unposted contribution', 'lines' => [
            ['accounting_account_id' => $a['1000']->id, 'debit' => 9999], ['accounting_account_id' => $a['3000']->id, 'credit' => 9999],
        ]]);
        $before = collect(['journal_entries', 'journal_lines', 'financial_account_transactions', 'expenses', 'customer_debt_transactions'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
        $this->getJson('/api/accounting/control-center?from=2026-09-01&to=2026-09-30')->assertOk()
            ->assertJsonPath('summary.bank', '1000.00')->assertJsonPath('summary.cash', '0.00')->assertJsonPath('counts.draft_journals', 1)->assertJsonPath('posted_values_only', true);
        $after = collect(array_keys($before))->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
        $this->assertSame($before, $after);
    }

    public function test_month_close_persists_a_check_snapshot_and_reopen_has_actor_reason_history(): void
    {
        [$s] = $this->context();
        $p = $s->savePeriod(['name' => 'September', 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30']);
        $r = $this->getJson('/api/accounting/periods/'.$p->id.'/readiness')->assertOk()->assertJsonPath('status', 'READY')->assertJsonPath('ready_to_close', true);
        foreach (['posted_balance', 'inventory_integrity', 'accounts_receivable', 'customer_advances', 'accounts_payable', 'supplier_advances', 'cash_bank', 'bank_rows', 'draft_journals'] as $code) {
            // bank rows use the public, explicit reconciliation key.
            if ($code === 'bank_rows') {
                $code = 'unreconciled_bank_items';
            }
            $this->assertContains($code, array_column($r->json('checks'), 'code'));
        }
        $this->putJson('/api/accounting/periods/'.$p->id.'/status', ['status' => 'closed', 'reason' => 'All source checks reviewed by accountant.'])->assertOk()->assertJsonPath('status', 'closed');
        $this->assertNotEmpty($p->fresh()->close_snapshot['fingerprint']);
        $this->putJson('/api/accounting/periods/'.$p->id.'/status', ['status' => 'open', 'reason' => 'Authorized correction after owner review.'])->assertOk();
        $this->assertSame(Auth::id(), $p->fresh()->reopened_by);
        $this->assertNotNull($p->fresh()->reopened_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting.period.open', 'entity_id' => $p->id]);
    }

    public function test_year_transition_carries_prior_profit_without_duplicate_journals(): void
    {
        [$s,$a] = $this->context();
        $this->journal($s, $a, '2025-12-31', 120, 'prior-year');
        $this->journal($s, $a, '2026-01-01', 30, 'new-year');
        $count = JournalEntry::count();
        $bs = $s->balanceSheet('2026-01-31');
        $this->assertSame('30.00', $bs['current_earnings']);
        $this->assertSame('120.00', $bs['retained_earnings_brought_forward']);
        $this->assertSame('150.00', $bs['total_equity']);
        $this->assertTrue($bs['balanced']);
        $this->assertSame($count, JournalEntry::count());
        Auth::user()->company->update(['fiscal_year_start_month' => 4]);
        $this->assertSame('150.00', $s->balanceSheet('2026-01-31')['current_earnings']);
    }

    public function test_closed_period_attempt_is_blocked_and_audited_after_transaction_rollback(): void
    {
        [$s,$a] = $this->context();
        $p = $s->savePeriod(['name' => 'September locked', 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30']);
        $s->setPeriodStatus($p, 'closed', 'All checks completed and reviewed.');
        $this->postJson('/api/accounting/journals', ['posting_date' => '2026-09-30', 'description' => 'Rejected backdate', 'lines' => [
            ['accounting_account_id' => $a['1000']->id, 'debit' => 10], ['accounting_account_id' => $a['3000']->id, 'credit' => 10],
        ]])->assertUnprocessable()->assertJsonPath('code', 'accounting_period_locked');
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting.closed_period_attempt', 'entity_id' => $p->id]);
    }

    public function test_account_running_balance_is_correct_on_later_pages_and_end_date(): void
    {
        [$s,$a] = $this->context();
        $this->journal($s, $a, '2026-08-31', 100, 'opening');
        for ($i = 1; $i <= 5; $i++) {
            $this->journal($s, $a, '2026-09-30', 10, 'line-'.$i);
        }
        $this->getJson('/api/accounting/accounts/'.$a['1000']->id.'/activity?from=2026-09-01&to=2026-09-30&per_page=2&page=2')->assertOk()
            ->assertJsonPath('totals.opening_balance', '100.00')->assertJsonPath('totals.closing_balance', '150.00')->assertJsonPath('lines.total', 5)
            ->assertJsonPath('lines.data.0.running_balance', '130.00')->assertJsonPath('lines.data.1.running_balance', '140.00');
    }

    public function test_simple_cash_outlook_never_loads_the_full_frozen_intelligence_payload(): void
    {
        $this->context();
        FinancialIntelligenceSnapshot::create($this->tenantAttributes(['fingerprint' => str_repeat('a', 64), 'version' => 'dc2-test', 'as_of' => '2026-09-30', 'evidence_cutoff' => now(),
            'evidence' => ['large_private_history' => str_repeat('SYNTHETIC', 200000)], 'forecast' => ['30' => ['currencies' => ['EUR' => ['inflows' => '100.00', 'outflows' => '50.00', 'expected_closing_cash' => '500.00']]]]]));
        DB::enableQueryLog();
        $r = $this->getJson('/api/accounting/control-center')->assertOk()->assertJsonPath('cash_outlook.expected_collections', '100.00')->assertJsonPath('cash_outlook.booked_accounting', false);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'financial_intelligence_snapshots'));
        DB::disableQueryLog();
        $this->assertLessThan(20000, strlen($r->getContent()));
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('select *', $sql);
        }
    }

    public function test_batched_inventory_check_reuses_the_existing_authoritative_comparison(): void
    {
        $this->context();
        $category = Category::create($this->tenantAttributes(['name' => 'Control fixtures']));
        for ($i = 0; $i < 201; $i++) {
            Product::create($this->tenantAttributes(['category_id' => $category->id, 'name' => 'Zero product '.$i, 'sku' => 'DC2-Z-'.$i, 'unit' => 'pcs', 'quantity' => 0, 'price' => 1]));
        }
        DB::enableQueryLog();
        $summary = app(InventoryIntegrityService::class)->companySummary();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertTrue($summary['passed']);
        $this->assertSame(201, $summary['products_checked']);
        $this->assertLessThanOrEqual(12, $count);
        $p = Product::firstOrFail();
        DB::table('products')->where('id', $p->id)->update(['quantity' => 1]);
        $bad = app(InventoryIntegrityService::class)->companySummary();
        $single = app(InventoryIntegrityService::class)->productSnapshot($p->fresh());
        $this->assertFalse($bad['passed']);
        $this->assertSame($single['issues'], $bad['examples'][0]['issues']);
    }

    public function test_integrity_detects_out_of_band_posted_line_corruption(): void
    {
        [$s,$a] = $this->context();
        $j = $this->journal($s, $a, '2026-09-30', 100, 'corruption-fixture');
        DB::table('journal_lines')->where('id', $j->lines()->first()->id)->update(['debit' => 99]);
        $this->assertSame(1, app(FinancialIntegrityChecks::class)->journalDifferences());
        $this->getJson('/api/accounting/control-center')->assertOk()->assertJsonPath('integrity_status', 'BLOCKED');
    }

    public function test_accountant_role_is_explicit_and_does_not_include_manual_control_overrides(): void
    {
        $this->context();
        $permissions = app(PermissionService::class);
        $this->assertTrue($permissions->roleHasPermission('accountant', 'accounting.journal.post'));
        $this->assertTrue($permissions->roleHasPermission('accountant', 'accounting.periods.close'));
        $this->assertFalse($permissions->roleHasPermission('accountant', 'accounting.journal.controlled'));
        $this->assertFalse($permissions->roleHasPermission('accountant', 'users.manage'));
        $this->assertFalse($permissions->roleHasPermission('manager', 'accounting.journal.post'));
        $this->assertFalse($permissions->roleHasPermission('manager', 'accounting.periods.reopen'));
        config(['system.operation_mode' => 'offline']);
        $this->postJson('/api/users', ['name' => 'Synthetic accountant', 'email' => 'dc2-accountant@example.test', 'role' => 'accountant',
            'temporary_password' => 'Synthetic-test-only-42', 'temporary_password_confirmation' => 'Synthetic-test-only-42'])->assertCreated()->assertJsonPath('user.role', 'accountant');
    }

    public function test_required_source_postings_respect_adoption_date_and_detect_missing_customer_journal(): void
    {
        [$s] = $this->context();
        Auth::user()->company->update(['accounting_start_date' => '2026-09-01']);
        $customer = Customer::create($this->tenantAttributes(['name' => 'Source proof customer', 'current_debt' => 0, 'current_credit' => 0]));
        $debt = app(CustomerDebtService::class)->addDebt($customer, ['amount' => 70, 'transaction_date' => '2026-09-15', 'idempotency_key' => 'source-proof']);
        $journal = JournalEntry::where('source_type', 'customer_debt_transaction')->where('source_id', $debt->id)->firstOrFail();
        // Simulate an imported linkage defect without editing monetary history.
        DB::table('journal_entries')->where('id', $journal->id)->update(['source_type' => 'unknown_legacy_source']);
        DailySale::create($this->tenantAttributes(['sale_number' => 'PRE-ADOPTION', 'sale_date' => '2026-08-01', 'status' => 'finalized', 'total_amount' => 10, 'total_quantity' => 1, 'created_by' => Auth::id()]));
        $check = collect(app(FinancialIntegrityChecks::class)->checks())->firstWhere('code', 'required_postings');
        $this->assertSame(1, $check['count']);
        $this->assertFalse($check['passed']);
        $this->assertSame('70.00', $customer->fresh()->current_debt);
    }

    public function test_integrity_detects_cross_company_gl_mapping_and_recovery_cannot_post_a_draft(): void
    {
        [$s] = $this->context();
        $other = Company::factory()->create();
        $accountId = DB::table('accounting_accounts')->insertGetId(['company_id' => $other->id, 'code' => 'OTHER-1000', 'name' => 'Other company cash', 'type' => 'asset', 'normal_balance' => 'debit', 'is_posting' => true, 'is_active' => true]);
        DB::table('accounting_posting_mappings')->where('company_id', Auth::user()->company_id)->where('mapping_key', 'cash')->update(['accounting_account_id' => $accountId]);
        $check = collect(app(FinancialIntegrityChecks::class)->checks())->firstWhere('code', 'company_links');
        $this->assertFalse($check['passed']);
        $sale = DailySale::create($this->tenantAttributes(['sale_number' => 'DRAFT-RECOVERY', 'sale_date' => '2026-09-15', 'status' => 'draft', 'total_amount' => 25, 'total_quantity' => 1, 'created_by' => Auth::id()]));
        $exception = AccountingException::create($this->tenantAttributes(['exception_key' => 'draft-recovery', 'type' => 'missing_accounting_entry', 'severity' => 'critical', 'status' => 'open', 'message' => 'Stale imported exception', 'details' => ['source_type' => 'daily_sale', 'source_id' => $sale->id]]));
        try {
            app(AccountingRecoveryService::class)->retry($exception);
            $this->fail('Draft source was posted by recovery.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source', $e->errors());
        }
        $this->assertSame(0, JournalEntry::where('source_type', 'daily_sale')->where('source_id', $sale->id)->count());
        $this->assertDatabaseHas('accounting_recovery_attempts', ['accounting_exception_id' => $exception->id, 'status' => 'failed']);
    }

    public function test_installation_reconciliation_still_detects_cross_company_master_links(): void
    {
        $this->context();
        $category = Category::create($this->tenantAttributes(['name' => 'Safe category']));
        $product = Product::create($this->tenantAttributes(['name' => 'Empty inventory control', 'sku' => 'TENANT-PROOF', 'category_id' => $category->id, 'quantity' => 0, 'inventory_value' => 0, 'unit' => 'pcs', 'price' => 1, 'purchase_price' => 1]));
        $other = Company::factory()->create();
        $foreignCategory = DB::table('categories')->insertGetId(['company_id' => $other->id, 'name' => 'Other category']);
        $this->assertTrue(app(InstallationBackupService::class)->reconcile()['healthy']);
        DB::table('products')->where('id', $product->id)->update(['category_id' => $foreignCategory]);
        $this->assertFalse(app(InstallationBackupService::class)->reconcile()['healthy']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => $foreignCategory]);
    }

    public function test_integrity_uses_cent_precision_and_still_detects_one_cent_posted_corruption(): void
    {
        [$service,$accounts] = $this->context();
        $journal = $service->createAndPost(['posting_date' => '2026-09-15', 'description' => 'Decimal cent precision', 'source_module' => 'precision_test', 'source_key' => 'decimal-cents', 'lines' => [
            ['accounting_account_id' => $accounts['1000']->id, 'debit' => '0.10'],
            ['accounting_account_id' => $accounts['1000']->id, 'debit' => '0.20'],
            ['accounting_account_id' => $accounts['3000']->id, 'credit' => '0.30'],
        ]]);
        $checks = app(FinancialIntegrityChecks::class);
        $this->assertSame(0, $checks->journalDifferences());
        $this->assertTrue(collect($checks->checks())->firstWhere('code', 'valid_lines')['passed']);
        DB::table('journal_lines')->where('journal_entry_id', $journal->id)->where('credit', '>', 0)->update(['credit' => '0.31']);
        $this->assertSame(1, $checks->journalDifferences());
        $this->assertFalse($service->trialBalance(null, '2026-09-30')['balanced']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journal->id, 'credit' => '0.31']);
        if (DB::getDriverName() === 'sqlite') {
            DB::table('journal_lines')->where('journal_entry_id', $journal->id)->where('credit', '>', 0)->update(['credit' => '0.301']);
            $this->assertFalse(collect($checks->checks())->firstWhere('code', 'valid_lines')['passed']);
        }
    }

    public function test_fractional_internal_transfer_is_not_an_unclassified_cash_flow(): void
    {
        [$service,$accounts] = $this->context();
        $service->createAndPost(['posting_date' => '2026-09-15', 'description' => 'Internal split transfer', 'source_module' => 'banking', 'source_key' => 'fractional-transfer', 'lines' => [
            ['accounting_account_id' => $accounts['1000']->id, 'debit' => '0.10'],
            ['accounting_account_id' => $accounts['1010']->id, 'debit' => '0.20'],
            ['accounting_account_id' => $accounts['1000']->id, 'credit' => '0.30'],
        ]]);
        $flow = $service->cashFlow('2026-09-01', '2026-09-30');
        $this->assertSame('0.00', $flow['net_cash_change']);
        $this->assertSame(0, $flow['unclassified_entries']);
        $this->assertTrue($flow['reconciles']);
    }
}
