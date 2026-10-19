<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\AccountingPostingMapping;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmploymentTerm;
use App\Models\FinancialAccountTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancialIntegrityChecks;
use App\Services\InstallationBackupService;
use App\Services\PermissionService;
use App\Services\Workforce\KosovoPayrollCalculator;
use App\Services\Workforce\PayrollReadService;
use App\Services\Workforce\PayrollService;
use App\Services\Workforce\WorkforcePresence;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DC4WorkforcePayrollTest extends TestCase
{
    use RefreshDatabase;

    private function setupPayroll(): void
    {
        $this->actingAsApiUser();
        $this->postJson('/api/payroll/configuration/initialize')->assertOk();
    }

    private function employee(string $salary = '900', string $from = '2026-01-01', ?int $user = null): int
    {
        $id = $this->postJson('/api/workforce/employees', ['employee_number' => 'E-'.random_int(1000, 9999), 'name' => 'Arta Test', 'personal_number' => 'SYNTHETIC-ID-001', 'start_date' => $from, 'status' => 'active', 'department' => 'Administration', 'position' => 'Coordinator', 'payment_method' => 'bank', 'user_id' => $user])->assertCreated()->json('id');
        $this->postJson('/api/workforce/employees/'.$id.'/terms', $this->term($salary, $from))->assertCreated();

        return $id;
    }

    private function term(string $salary, string $from): array
    {
        return ['effective_from' => $from, 'contract_type' => 'permanent', 'position' => 'Coordinator', 'department' => 'Administration', 'pay_basis' => 'monthly', 'amount' => $salary, 'currency' => 'EUR', 'proration_policy' => 'calendar_days', 'employer_classification' => 'primary', 'pension_treatment' => 'resident_contributor', 'employee_pension_rate' => '5', 'employer_pension_rate' => '5', 'reason' => 'Approved employment terms'];
    }

    private function payrollRun(string $month = '2026-10'): int
    {
        return $this->postJson('/api/payroll', ['name' => $month, 'starts_on' => $month.'-01', 'ends_on' => date('Y-m-t', strtotime($month.'-01')), 'pay_date' => date('Y-m-t', strtotime($month.'-01'))])->assertCreated()->json('id');
    }

    private function record(int $employee, string $kind, string $amount, string $date = '2026-10-15', array $details = []): int
    {
        $id = $this->postJson('/api/workforce/records', ['employee_id' => $employee, 'kind' => $kind, 'starts_on' => $date, 'ends_on' => $date, 'units' => 1, 'amount' => $amount, 'reason' => 'Approved payroll instruction', 'details' => $details])->assertCreated()->json('id');
        $this->postJson('/api/workforce/records/'.$id.'/decision', ['status' => 'approved', 'reason' => 'Reviewed authorized instruction'])->assertOk();

        return $id;
    }

    private function approve(int $run): string
    {
        $hash = $this->postJson('/api/payroll/'.$run.'/calculate')->assertOk()->json('fingerprint');
        $this->postJson('/api/payroll/'.$run.'/approve', ['fingerprint' => $hash, 'acknowledge_warnings' => true])->assertOk();

        return $hash;
    }

    private function bank(): int
    {
        return $this->postJson('/api/finance/accounts', ['name' => 'Payroll Bank', 'type' => 'bank', 'currency' => 'EUR', 'opening_balance' => '10000', 'opening_date' => '2026-01-01', 'is_active' => true])->assertCreated()->json('id');
    }

    public function test_current_official_bn24_fixture_and_separate_pension_components(): void
    {
        // ATK BN24 id=21: primary, gross 1000, EE/ER 5%; independently verified from its published calculator.
        $calculator = app(KosovoPayrollCalculator::class);
        $input = ['gross' => '1000', 'employer_classification' => 'primary', 'pension_treatment' => 'resident_contributor', 'employee_pension_rate' => '5', 'employer_pension_rate' => '5'];
        $r = $calculator->calculate(KosovoPayrollCalculator::CURRENT, $input);
        foreach (['employee_pension' => '50.00', 'employer_pension' => '50.00', 'taxable' => '950.00', 'tax' => '66.00', 'net' => '884.00', 'employer_cost' => '1050.00'] as $key => $expected) {
            $this->assertSame($expected, $r[$key]);
        }
        $this->assertSame('95.00', $calculator->calculate(KosovoPayrollCalculator::CURRENT, [...$input, 'employer_classification' => 'secondary'])['tax']);
        $foreign = $calculator->calculate(KosovoPayrollCalculator::CURRENT, [...$input, 'pension_treatment' => 'foreign_temporary_excluded', 'pension_basis' => 'Documented temporary non-resident under Article 6.14', 'employee_pension_rate' => 0, 'employer_pension_rate' => 0]);
        $this->assertSame('0.00', $foreign['employee_pension']);
        $this->assertSame('929.00', $foreign['net']);
        $estimate = $calculator->estimateGross(KosovoPayrollCalculator::CURRENT, $input, '884');
        $this->assertSame('1000.00', $estimate['gross']);
    }

    public function test_effective_salary_rule_snapshots_and_approval_invalidation(): void
    {
        $this->setupPayroll();
        $employee = $this->employee('800');
        $first = EmploymentTerm::firstOrFail();
        $this->postJson('/api/workforce/employees/'.$employee.'/terms', [...$this->term('900', '2026-07-01'), 'supersedes_id' => $first->id])->assertCreated();
        $this->postJson('/api/payroll/configuration/rules', ['version' => 'Reviewed-2026-07', 'effective_from' => '2026-07-01', 'sources' => ['https://crmm.atk-ks.org/sq/Public/CalculatorDetail?id=21']])->assertCreated();
        $june = $this->payrollRun('2026-06');
        $this->postJson('/api/payroll/'.$june.'/calculate')->assertOk()->assertJsonPath('totals.gross', '800.00');
        $october = $this->payrollRun();
        $hash = $this->approve($october);
        $line = PayrollLine::where('payroll_run_id', $october)->firstOrFail();
        $this->assertSame('900.00', $line->gross);
        $this->assertSame('Reviewed-2026-07', $line->snapshot['rule']['version']);
        $this->record($employee, 'bonus', '100');
        $this->assertSame('needs_review', PayrollRun::find($october)->status);
        $this->postJson('/api/payroll/'.$october.'/post', ['fingerprint' => $hash])->assertUnprocessable();
        $this->postJson('/api/payroll/'.$october.'/calculate')->assertOk()->assertJsonPath('totals.gross', '1000.00');
        $this->assertSame('800.00', $first->fresh()->amount);
        try {
            $first->update(['amount' => '2000']);
            $this->fail('Historic terms changed.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_payroll_advance_post_payment_close_and_retry_share_one_financial_authority(): void
    {
        $this->setupPayroll();
        $employee = $this->employee();
        $bank = $this->bank();
        $advance = ['financial_account_id' => $bank, 'amount' => '300', 'paid_on' => '2026-10-01', 'reason' => 'Approved employee advance', 'idempotency_key' => 'advance-journey-a'];
        $this->postJson('/api/workforce/employees/'.$employee.'/advances', $advance)->assertCreated();
        $this->postJson('/api/workforce/employees/'.$employee.'/advances', $advance)->assertCreated();
        $this->assertSame(1, EmployeeAdvance::count());
        $this->record($employee, 'bonus', '100');
        $this->record($employee, 'advance_repayment', '150');
        $run = $this->payrollRun();
        $hash = $this->approve($run);
        $preClose = app(PayrollReadService::class)->closeChecks('2026-10-01', '2026-10-31');
        $this->assertGreaterThan(0, $preClose[0]['count']);
        $posted = $this->postJson('/api/payroll/'.$run.'/post', ['fingerprint' => $hash])->assertOk()->assertJsonPath('totals.net', '734.00');
        $journal = $posted->json('journal_entry_id');
        $this->putJson('/api/accounting/mappings', ['mappings' => ['payroll_salary_expense' => AccountingPostingMapping::where('mapping_key', 'payroll_employer_pension_expense')->value('accounting_account_id')]])->assertUnprocessable();
        $this->postJson('/api/payroll/'.$run.'/post', ['fingerprint' => $hash])->assertOk()->assertJsonPath('journal_entry_id', $journal);
        $j = JournalEntry::findOrFail($journal);
        $this->assertSame('1050.00', $j->total_debit);
        $this->assertSame($j->total_debit, $j->total_credit);
        $this->assertSame('150.00', app(PayrollService::class)->advanceBalance($employee));
        $reconciliation = app(PayrollReadService::class)->reconcile('2026-10-31');
        $this->assertTrue($reconciliation['reconciled']);
        $this->assertCount(6, $reconciliation['rows']);
        $line = PayrollLine::where('payroll_run_id', $run)->firstOrFail();
        $this->assertArrayNotHasKey('private_snapshot', $line->toArray());
        $this->assertStringNotContainsString('SYNTHETIC-ID-001', (string) DB::table('payroll_lines')->where('id', $line->id)->value('private_snapshot'));
        $pay = ['kind' => 'salary', 'payroll_line_id' => $line->id, 'financial_account_id' => $bank, 'amount' => '734', 'paid_on' => '2026-10-31', 'idempotency_key' => 'salary-journey-a'];
        $this->postJson('/api/payroll/'.$run.'/pay', $pay)->assertCreated();
        $this->postJson('/api/payroll/'.$run.'/pay', $pay)->assertCreated();
        $this->getJson('/api/payroll/payslips/'.$line->id)->assertOk()->assertJsonPath('remaining', '0.00')->assertJsonPath('languages', ['en', 'sq']);
        $this->postJson('/api/payroll/'.$run.'/close')->assertUnprocessable();
        foreach (['tax' => '66', 'employee_pension' => '50', 'employer_pension' => '50'] as $kind => $amount) {
            $this->postJson('/api/payroll/'.$run.'/pay', ['kind' => $kind, 'amount' => $amount, 'financial_account_id' => $bank, 'paid_on' => '2026-11-10', 'idempotency_key' => 'statutory-'.$kind])->assertCreated();
        }
        $this->getJson('/api/payroll/'.$run)->assertOk()->assertJsonPath('obligations.reconciled', true);
        $this->postJson('/api/payroll/'.$run.'/close')->assertOk()->assertJsonPath('status', 'closed');
        $this->assertTrue(app(PayrollReadService::class)->reconcile('2026-11-30')['reconciled']);
        $backupProof = app(InstallationBackupService::class)->reconcile();
        $this->assertTrue($backupProof['healthy']);
        $this->assertSame(1, $backupProof['totals'][(string) $this->apiCompany->id]['workforce']['payroll_lines']);
        $this->assertSame(5, FinancialAccountTransaction::whereIn('source_type', ['employee_advance', 'payroll_payment'])->count());
        $salaryExpense = AccountingPostingMapping::where('mapping_key', 'payroll_salary_expense')->value('accounting_account_id');
        $this->assertSame('1000.00', Money::normalize(JournalLine::where('accounting_account_id', $salaryExpense)->sum('debit')));
        $this->getJson('/api/payroll/'.$run.'/export?type=declaration')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_closed_period_posting_is_atomic_and_reversal_preserves_lineage(): void
    {
        $this->setupPayroll();
        $this->employee();
        $run = $this->payrollRun();
        $hash = $this->approve($run);
        $period = AccountingPeriod::create(['company_id' => $this->apiCompany->id, 'name' => 'October', 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31', 'status' => 'closed']);
        $this->postJson('/api/payroll/'.$run.'/post', ['fingerprint' => $hash])->assertUnprocessable();
        $this->assertNull(PayrollRun::find($run)->journal_entry_id);
        $period->update(['status' => 'open']);
        $journal = $this->postJson('/api/payroll/'.$run.'/post', ['fingerprint' => $hash])->assertOk()->json('journal_entry_id');
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'cilat paga nuk jane paguar', 'language' => 'sq'])->assertOk()->assertJsonPath('cards.1.title', 'Arta Test');
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'how much is payroll this month'])->assertOk()->assertJsonPath('cards.0.metrics.0.value', '900.00');
        $this->postJson('/api/payroll/'.$run.'/reverse', ['date' => '2026-11-01', 'reason' => 'Incorrect bonus omitted'])->assertOk()->assertJsonPath('status', 'reversed');
        $reversal = PayrollRun::find($run)->reversal_journal_id;
        $this->assertSame($journal, JournalEntry::find($reversal)->reversal_of_id);
        $this->assertSame('reversed', JournalEntry::find($journal)->status);
        $this->assertSame(1, PayrollLine::where('payroll_run_id', $run)->count());
        $this->postJson('/api/payroll/'.$run.'/correction')->assertCreated()->assertJsonPath('correction_of_id', $run)->assertJsonPath('revision', 2);
        try {
            PayrollLine::where('payroll_run_id', $run)->first()->update(['net' => '1']);
            $this->fail('Posted payroll edited.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_payroll_privacy_in_api_documents_journals_ask_and_own_payslips(): void
    {
        $this->setupPayroll();
        $worker = User::factory()->create(['company_id' => $this->apiCompany->id, 'role' => 'staff', 'api_token' => hash('sha256', 'own-worker'), 'email_verified_at' => now()]);
        $this->employee('900', '2026-01-01', $worker->id);
        $this->employee('1200');
        $run = $this->payrollRun();
        $hash = $this->approve($run);
        $journal = $this->postJson('/api/payroll/'.$run.'/post', ['fingerprint' => $hash])->assertOk()->json('journal_entry_id');
        $own = PayrollLine::whereHas('employee', fn ($q) => $q->where('user_id', $worker->id))->firstOrFail();
        $other = PayrollLine::where('id', '!=', $own->id)->firstOrFail();
        $type = DocumentType::create(['company_id' => $this->apiCompany->id, 'name' => 'Payroll support']);
        $doc = Document::create(['company_id' => $this->apiCompany->id, 'document_type_id' => $type->id, 'uuid' => (string) Str::uuid(), 'title' => 'Private payroll', 'confidentiality' => 'confidential', 'created_by' => Auth::id()]);
        $doc->links()->create(['entity_type' => 'payroll-run', 'entity_id' => $run, 'linked_by' => Auth::id()]);
        $this->withHeader('Authorization', 'Bearer own-worker');
        $this->getJson('/api/payroll/payslips/'.$own->id)->assertOk();
        $this->getJson('/api/payroll/payslips/'.$other->id)->assertNotFound();
        $this->getJson('/api/payroll/summary')->assertForbidden();
        $this->getJson('/api/workforce/employees')->assertForbidden();
        $this->postJson('/api/intelligence-assistant/ask', ['question' => 'sa eshte paga totale kete muaj'])->assertOk()->assertJsonPath('state', 'permission_denied');
        $manager = User::factory()->create(['company_id' => $this->apiCompany->id, 'role' => 'manager', 'api_token' => hash('sha256', 'manager-private'), 'email_verified_at' => now()]);
        $this->withHeader('Authorization', 'Bearer manager-private');
        $this->getJson('/api/payroll/'.$run)->assertForbidden();
        $this->getJson('/api/accounting/journals/'.$journal)->assertForbidden();
        $this->getJson('/api/documents/'.$doc->id)->assertNotFound();
        $this->postJson('/api/payroll/'.$run.'/post', ['fingerprint' => $hash])->assertForbidden();
        $this->getJson('/api/accounting/accounts/'.AccountingPostingMapping::where('mapping_key', 'payroll_salary_expense')->value('accounting_account_id').'/activity')->assertForbidden();
        $this->assertFalse(app(PermissionService::class)->roleHasPermission('manager', 'payroll.view'));
    }

    public function test_two_company_ids_and_payroll_sources_cannot_cross_tenants(): void
    {
        $this->setupPayroll();
        $employee = $this->employee();
        $run = $this->payrollRun();
        $hash = $this->approve($run);
        $company = Company::factory()->create();
        $other = User::factory()->create(['company_id' => $company->id, 'role' => 'admin', 'api_token' => hash('sha256', 'company-two'), 'email_verified_at' => now()]);
        $this->withHeader('Authorization', 'Bearer company-two');
        $this->getJson('/api/workforce/employees/'.$employee)->assertNotFound();
        $this->getJson('/api/payroll/'.$run)->assertNotFound();
        $this->postJson('/api/payroll/'.$run.'/post', ['fingerprint' => $hash])->assertNotFound();
        $this->postJson('/api/workforce/employees/'.$employee.'/terms', $this->term('999', '2026-10-01'))->assertNotFound();
        $this->getJson('/api/payroll/summary')->assertOk()->assertJsonPath('active_employees', 0);
        $this->assertFalse(WorkforcePresence::exists());
        $secondEmployee = Employee::create(['employee_number' => 'SECOND-COMPANY', 'name' => 'Second company employee', 'status' => 'draft', 'start_date' => '2026-01-01', 'payment_method' => 'cash']);
        $this->assertTrue(WorkforcePresence::exists());
        Auth::setUser(User::where('company_id', $this->apiCompany->id)->firstOrFail());
        DB::table('payroll_lines')->where('payroll_run_id', $run)->update(['employee_id' => $secondEmployee->id]);
        $companyLinks = collect(app(FinancialIntegrityChecks::class)->checks())->firstWhere('code', 'company_links');
        $this->assertGreaterThan(0, $companyLinks['count']);
        $this->assertSame('critical', $companyLinks['severity']);
    }

    public function test_leave_attendance_approvals_and_upgrade_permissions_are_preserved(): void
    {
        $this->setupPayroll();
        $employee = $this->employee();
        $this->record($employee, 'entitlement', '0', '2026-01-01', ['policy_basis' => 'Company approved annual allowance']);
        $this->record($employee, 'leave', '0', '2026-10-15', ['leave_type' => 'annual']);
        $this->getJson('/api/workforce/employees/'.$employee)->assertOk()->assertJsonPath('leave_balance.planned', '1.00')->assertJsonPath('leave_balance.remaining', '0.00');
        $role = Role::where('slug', 'admin')->firstOrFail();
        $revoked = Permission::where('slug', 'inventory.view')->value('id');
        $role->permissions()->detach($revoked);
        $this->assertSame(0, Artisan::call('workforce:setup'));
        $this->assertFalse($role->permissions()->whereKey($revoked)->exists());
        $this->postJson('/api/workforce/records', ['employee_id' => $employee, 'kind' => 'overtime', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-10', 'units' => 2, 'amount' => 20, 'reason' => 'Additional authorized work'])->assertUnprocessable();
    }
}
