<?php

namespace App\Services\Workforce;

use App\Models\AccountingAccount;
use App\Models\AccountingPostingMapping;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Models\JournalLine;
use App\Models\Notification;
use App\Models\PayrollLine;
use App\Models\PayrollPayment;
use App\Models\PayrollPeriod;
use App\Models\PayrollRule;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkforceRecord;
use App\Services\AccountingService;
use App\Services\FinancialAccountService;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable as Day;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Workforce owns obligations; the existing FinancialAccountService and AccountingService own money and journals. */
class PayrollService
{
    public const ACCOUNTS = [
        'salary_expense' => ['6500', 'Salary Expense', 'expense'], 'employer_pension_expense' => ['6510', 'Employer Pension Expense', 'expense'],
        'salary_payable' => ['2300', 'Salary Payable', 'liability'], 'employee_pension_payable' => ['2310', 'Employee Pension Payable', 'liability'],
        'employer_pension_payable' => ['2320', 'Employer Pension Payable', 'liability'], 'tax_payable' => ['2330', 'Salary Withholding Tax Payable', 'liability'],
        'deductions_payable' => ['2340', 'Authorized Payroll Deductions Payable', 'liability'], 'employee_advances' => ['1500', 'Employee Advances', 'asset'],
    ];

    public const AMOUNTS = ['gross', 'employee_pension', 'employer_pension', 'taxable', 'tax', 'deductions', 'advance_repayment', 'net', 'employer_cost'];

    public function __construct(private WorkforceService $workforce, private AccountingService $accounting, private KosovoPayrollCalculator $calculator) {}

    public function initialize(): array
    {
        $this->workforce->permit('payroll.configure');

        return DB::transaction(function () {
            $this->workforce->lock();
            $this->accounting->initialize();
            $mapping = [];
            foreach (self::ACCOUNTS as $key => [$code,$name,$type]) {
                if (AccountingPostingMapping::where('mapping_key', 'payroll_'.$key)->exists()) {
                    continue;
                }
                $account = AccountingAccount::where('code', $code)->first();
                if ($account && ($account->name !== $name || $account->type !== $type || ! $account->is_posting || ! $account->is_active)) {
                    $this->workforce->fail("Account $code is already used. Select an explicit payroll mapping instead of replacing it.");
                }
                $account ??= $this->accounting->saveAccount(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $type === 'liability' ? 'credit' : 'debit', 'is_posting' => true, 'is_system' => true, 'is_active' => true, 'cash_flow_class' => 'operating', 'statement_group' => $type === 'expense' ? 'operating_expense' : null]);
                $mapping['payroll_'.$key] = $account->id;
            }
            if ($mapping) {
                $this->accounting->saveMappings($mapping);
            }
            PayrollRule::firstOrCreate(['company_id' => Auth::user()->company_id, 'version' => 'XK-08L142-2024-08-v1'], [
                'effective_from' => '2024-08-01', 'configuration' => KosovoPayrollCalculator::CURRENT, 'approved_by' => Auth::id(),
                'sources' => [
                    'https://www.atk-ks.org/njoftim-shtese-lidhur-me-aplikimin-e-shkalleve-te-reja-per-tatimin-ne-te-ardhurat-personale/',
                    'https://crmm.atk-ks.org/sq/Public/CalculatorDetail?id=21',
                    'https://www.atk-ks.org/wp-content/uploads/2017/11/Law-No.-04-L-101.pdf',
                    'https://www.atk-ks.org/portfolio/informata-te-pergjithshme-per-tatimet-ne-kosove/',
                ],
            ]);
            $this->workforce->invalidate();
            $this->workforce->audit('payroll_configuration_initialized', Auth::user()->company);

            return $this->configuration();
        });
    }

    public function configuration(): array
    {
        $this->workforce->permit('payroll.configure');

        return ['mappings' => AccountingPostingMapping::where('mapping_key', 'like', 'payroll_%')->with('account:id,code,name,type,is_active,is_posting')->get(),
            'accounts' => AccountingAccount::where('is_active', true)->where('is_posting', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'rules' => PayrollRule::orderByDesc('effective_from')->get(), 'required_mappings' => array_keys(self::ACCOUNTS),
            'automatic_edi_filing' => false, 'statutory_due_day' => 15, 'due_date_qualification' => '1–15 of the following month; verify official non-working-day extensions. Declaration is payment based, not an accrual filing.'];
    }

    public function mappings(array $data): array
    {
        $this->workforce->permit('payroll.configure');

        return DB::transaction(function () use ($data) {
            $this->workforce->lock();
            $rows = [];
            foreach ($data as $key => $id) {
                if (! isset(self::ACCOUNTS[$key])) {
                    $this->workforce->fail('Unknown payroll mapping.');
                }
                $a = AccountingAccount::findOrFail($id);
                if ($a->type !== self::ACCOUNTS[$key][2] || ! $a->is_posting || ! $a->is_active) {
                    $this->workforce->fail('Each payroll mapping must use an active posting account of the correct type.');
                }
                if ($a->lines()->exists() && AccountingPostingMapping::where('accounting_account_id', $id)->where('mapping_key', 'not like', 'payroll_%')->exists()) {
                    $this->workforce->fail('Use dedicated payroll control accounts, not an existing customer/supplier/inventory account.');
                }
                $rows['payroll_'.$key] = $id;
            }
            if (count(array_unique(array_values($rows))) !== count($rows)) {
                $this->workforce->fail('Keep payroll controls separate so each obligation can reconcile.');
            }
            if (PayrollRun::whereIn('status', ['posted', 'paid', 'closed'])->exists()) {
                $this->workforce->fail('Payroll control remapping after posting requires an explicit migration of outstanding balances; it is not supported here.');
            }
            $this->accounting->saveMappings($rows);
            $this->workforce->invalidate();

            return $this->configuration();
        });
    }

    public function rule(array $data): PayrollRule
    {
        $this->workforce->permit('payroll.configure');

        return DB::transaction(function () use ($data) {
            $this->workforce->lock();
            $latest = PayrollRule::orderByDesc('effective_from')->first();
            if ($latest && $data['effective_from'] <= $latest->effective_from->toDateString()) {
                $this->workforce->fail('A statutory rule revision must start after the latest version. Historic versions are immutable.');
            }
            if (PayrollRun::whereIn('status', ['posted', 'paid', 'closed'])->whereHas('period', fn ($q) => $q->where('ends_on', '>=', $data['effective_from']))->exists()) {
                $this->workforce->fail('A new rule cannot reinterpret already posted payroll.');
            }
            // New legislation needs a reviewed code/configuration update, not arbitrary formulas from an API client.
            $r = PayrollRule::create([...$data, 'configuration' => KosovoPayrollCalculator::CURRENT, 'approved_by' => Auth::id()]);
            $this->workforce->invalidate();
            $this->workforce->audit('statutory_rule_version_created', $r, ['version' => $r->version, 'effective_from' => $data['effective_from']]);

            return $r;
        });
    }

    public function ruleFor(string $period): PayrollRule
    {
        $rule = PayrollRule::where('effective_from', '<=', $period)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $period))->orderByDesc('effective_from')->first();
        if (! $rule) {
            $this->workforce->fail('No applicable verified statutory payroll rule. Configure a reviewed rule version for this period.');
        }

        return $rule;
    }

    private function mappingIds(): array
    {
        $rows = AccountingPostingMapping::where('mapping_key', 'like', 'payroll_%')->with('account')->get()->keyBy('mapping_key');
        $ids = [];
        foreach (self::ACCOUNTS as $key => $definition) {
            $a = $rows->get('payroll_'.$key)?->account;
            if (! $a || ! $a->is_active || ! $a->is_posting || $a->type !== $definition[2]) {
                $this->workforce->fail("Payroll accounting mapping '$key' is missing or invalid.");
            }
            $ids[$key] = $a->id;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            $this->workforce->fail('Payroll control accounts must be separate.');
        }

        return $ids;
    }

    public function createPeriod(array $data): PayrollRun
    {
        $this->workforce->permit('payroll.manage');

        return DB::transaction(function () use ($data) {
            $this->workforce->lock();
            $from = Day::parse($data['starts_on']);
            $to = Day::parse($data['ends_on']);
            if (! $from->isStartOfMonth() || ! $to->isLastOfMonth() || $from->format('Y-m') !== $to->format('Y-m')) {
                $this->workforce->fail('This payroll core supports one complete calendar month per payroll period.');
            }
            if ($data['pay_date'] < $data['ends_on']) {
                $this->workforce->fail('Scheduled salary payment must be on or after the period end. Employee advances use their own workflow.');
            }
            if (PayrollPeriod::where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                $this->workforce->fail('A payroll period already covers these dates. Use its existing run or an explicit correction.');
            }
            $p = PayrollPeriod::create($data);
            $run = PayrollRun::create(['payroll_period_id' => $p->id, 'revision' => 1, 'created_by' => Auth::id()]);
            $this->workforce->audit('period_created', $p);

            return $run->load('period');
        });
    }

    public function runs(array $f)
    {
        $this->workforce->permit('payroll.view');

        return PayrollRun::select(['id','company_id','payroll_period_id','revision','correction_of_id','status','totals','posted_at'])->with('period')->withCount('lines')->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->latest('id')->paginate(24);
    }

    private function prepared(PayrollRun $run): array
    {
        $p = $run->period;
        $ids = $this->mappingIds();
        $rule = $this->ruleFor($p->starts_on->toDateString());
        if (CompanyCurrency::forCompanyId(Auth::user()->company_id) !== 'EUR') {
            $this->workforce->fail('The verified payroll engine supports EUR companies only.');
        }
        $employees = Employee::whereNotIn('status', ['draft', 'archived'])->where('start_date', '<=', $p->ends_on)->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $p->starts_on))->with(['terms' => fn ($q) => $q->where('effective_from', '<=', $p->ends_on)->where(fn ($q) => $q->where('effective_from', '>=', $p->starts_on)->orWhereRaw('effective_from = (SELECT MAX(prior_terms.effective_from) FROM employment_terms prior_terms WHERE prior_terms.employee_id = employment_terms.employee_id AND prior_terms.company_id = employment_terms.company_id AND prior_terms.effective_from <= ?)', [$p->starts_on->toDateString()])), 'terms.schedule'])->orderBy('id')->limit(1001)->get();
        if ($employees->isEmpty()) {
            $this->workforce->fail('No eligible employees have been configured for this period.');
        }
        if ($employees->count() > 1000) {
            $this->workforce->fail('This preview supports at most 1,000 employees per monthly run. Use a reviewed batch-calculation extension before larger payrolls.');
        }
        $payMonth = Day::parse($p->pay_date)->startOfMonth();
        if (PayrollLine::whereIn('employee_id', $employees->pluck('id'))->where('payroll_run_id', '!=', $run->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['calculated', 'approved', 'posted', 'paid', 'closed'])->whereHas('period', fn ($q) => $q->whereBetween('pay_date', [$payMonth->toDateString(), $payMonth->endOfMonth()->toDateString()])))->exists()) {
            $this->workforce->fail('An employee already has another payroll scheduled in this payment month. Combined/arrears withholding needs a reviewed extension; monthly tax bands must not be applied twice.');
        }
        $records = WorkforceRecord::whereIn('employee_id', $employees->pluck('id'))->where('starts_on', '<=', $p->ends_on)->where('ends_on', '>=', $p->starts_on)->orderBy('id')->get()->groupBy('employee_id');
        $advanceTotals = EmployeeAdvance::whereIn('employee_id', $employees->pluck('id'))->where('paid_on', '<=', $p->ends_on)->selectRaw('employee_id, SUM(amount) as total')->groupBy('employee_id')->get()->keyBy('employee_id');
        $repaymentTotals = PayrollLine::whereIn('employee_id', $employees->pluck('id'))->whereHas('run', fn ($q) => $q->whereIn('status', ['posted', 'paid', 'closed'])->whereHas('period', fn ($q) => $q->where('ends_on', '<=', $p->ends_on)))->selectRaw('employee_id, SUM(advance_repayment) as total')->groupBy('employee_id')->get()->keyBy('employee_id');
        $rows = [];
        $warnings = [];
        foreach ($employees as $e) {
            if ($e->status === 'suspended') {
                $this->workforce->fail('Resolve the pay treatment for suspended employee '.$e->employee_number.' before calculation.');
            }
            $all = $records->get($e->id, collect());
            $approved = $all->whereIn('status', ['approved', 'taken']);
            if ($all->where('status', 'requested')->whereIn('kind', ['bonus', 'deduction', 'advance_repayment', 'overtime', 'leave', 'attendance'])->isNotEmpty()) {
                $warnings[] = ['employee_id' => $e->id, 'code' => 'pending_inputs', 'message' => 'Unapproved records are excluded; resolve or explicitly review before approval.'];
            }
            $parts = [];
            $base = '0.00';
            $usedTerms = [];
            $last = $p->ends_on;
            $start = $p->starts_on;
            for ($day = Day::parse($start); $day->lte($last); $day = $day->addDay()) {
                if ($day->lt($e->start_date) || ($e->termination_date && $day->gt($e->termination_date))) {
                    continue;
                }
                $term = $e->terms->filter(fn ($t) => $t->effective_from->lte($day))->sortByDesc('effective_from')->first();
                if (! $term || ($term->effective_to && $term->effective_to->lt($day))) {
                    $this->workforce->fail('Missing effective compensation for '.$e->employee_number.' on '.$day->toDateString().'.');
                }
                if ($term->currency !== 'EUR') {
                    $this->workforce->fail('The verified payroll engine supports EUR only.');
                }
                $usedTerms[$term->id] = $term;
                $parts[$term->id] ??= ['term_id' => $term->id, 'pay_basis' => $term->pay_basis, 'rate' => $term->amount, 'days' => 0, 'units' => '0.0000'];
                $parts[$term->id]['days']++;
                if ($term->pay_basis !== 'monthly') {
                    $attendance = $approved->where('kind', 'attendance')->filter(fn ($r) => $r->starts_on->lte($day) && $r->ends_on->gte($day));
                    if ($attendance->count() > 1) {
                        $this->workforce->fail('Overlapping attendance for '.$e->employee_number.'.');
                    }
                    foreach ($attendance as $a) {
                        if (in_array($a->details['attendance_status'] ?? '', ['present', 'late'], true)) {
                            if ($a->starts_on->ne($a->ends_on)) {
                                $this->workforce->fail('Hourly/daily attendance must be recorded per day.');
                            }
                            $units = $term->pay_basis === 'daily' ? '1' : $a->units;
                            $parts[$term->id]['units'] = Money::normalizeDecimal((string) BigDecimal::of($parts[$term->id]['units'])->plus($units), 4);
                        }
                    }
                }
            }
            if (count($usedTerms) > 1) {
                $warnings[] = ['employee_id' => $e->id, 'code' => 'changed_salary', 'message' => 'Compensation changed within this month; inspect the dated proration.'];
            }
            foreach ($parts as &$part) {
                $term = $usedTerms[$part['term_id']];
                $part['amount'] = $term->pay_basis === 'monthly' ? Money::product([$term->amount, Money::divide($part['days'], Day::parse($start)->daysInMonth, 12)]) : Money::multiply($term->amount, $part['units']);
                $part['proration_policy'] = $term->proration_policy;
                $base = Money::add($base, $part['amount']);
            } unset($part);
            $term = end($usedTerms);
            if (! $term) {
                $this->workforce->fail('No eligible compensation days for '.$e->employee_number.'.');
            }
            if ($term->pay_basis !== 'monthly' && $approved->where('kind', 'leave')->filter(fn ($r) => ($r->details['leave_type'] ?? '') !== 'unpaid')->isNotEmpty()) {
                $this->workforce->fail('Paid leave for hourly/daily compensation needs a reviewed earnings-policy extension. This core preserves monthly salary during paid leave; it does not silently assume an hourly leave rate.');
            }
            foreach ($usedTerms as $t) {
                if ($t->employer_classification !== $term->employer_classification || $t->pension_treatment !== $term->pension_treatment || $t->employee_pension_rate !== $term->employee_pension_rate || $t->employer_pension_rate !== $term->employer_pension_rate) {
                    $this->workforce->fail('Pension/employer classification changes within a month require a reviewed split calculation; this core blocks rather than averaging statutory treatment.');
                }
            }
            $sum = fn ($kind) => Money::decimal($approved->where('kind', $kind)->sum(fn ($r) => Money::minor($r->amount)));
            $bonus = $sum('bonus');
            $overtime = $sum('overtime');
            $deductions = $sum('deduction');
            $advance = $sum('advance_repayment');
            $unpaid = Money::decimal($approved->where('kind', 'leave')->filter(fn ($r) => ($r->details['leave_type'] ?? '') === 'unpaid')->sum(fn ($r) => Money::minor($r->amount)));
            $advanceBalance = Money::subtract($advanceTotals->get($e->id)?->total ?? 0, $repaymentTotals->get($e->id)?->total ?? 0);
            if (Money::compare($advance, $advanceBalance) > 0) {
                $this->workforce->fail('Advance repayment exceeds the employee’s outstanding advance as of the payroll period end.');
            }
            $calculation = $this->calculator->calculate($rule->configuration, [...$term->toArray(), 'gross' => Money::subtract(Money::add($base, $bonus, $overtime), $unpaid), 'deductions' => $deductions, 'advance_repayment' => $advance]);
            if (Money::compare($calculation['gross'], Money::multiply($base, 2)) > 0 || Money::compare($calculation['net'], 0) === 0) {
                $warnings[] = ['employee_id' => $e->id, 'code' => 'unusual_values', 'message' => 'Review heuristic: gross exceeds twice base earnings, or net pay is zero. This is a review notice, not a statutory rule.'];
            }
            $snapshot = ['employee' => ['id' => $e->id, 'employee_number' => $e->employee_number, 'name' => $e->name, 'department' => $term->department, 'position' => $term->position, 'start_date' => $e->start_date->toDateString(), 'termination_date' => $e->termination_date?->toDateString(), 'payment_method' => $e->payment_method],
                'terms' => array_values(array_map(fn ($t) => $t->toArray(), $usedTerms)), 'components' => ['base' => $base, 'bonus' => $bonus, 'overtime' => $overtime, 'unpaid_leave_reduction' => $unpaid, 'parts' => array_values($parts)],
                'records' => $approved->map(fn ($r) => $r->only(['id', 'kind', 'starts_on', 'ends_on', 'units', 'amount', 'details', 'approved_by', 'approved_at', 'status']))->values()->all(),
                'rule' => ['id' => $rule->id, 'version' => $rule->version, 'configuration' => $rule->configuration, 'sources' => $rule->sources], 'mappings' => $ids, 'calculation' => $calculation];
            $rows[] = ['employee_id' => $e->id, 'payroll_rule_id' => $rule->id, 'snapshot' => $snapshot, 'private_snapshot' => ['personal_number' => $e->personal_number, 'bank_account' => $e->bank_account], ...array_intersect_key($calculation, array_flip(self::AMOUNTS))];
        }

        return ['rows' => $rows, 'warnings' => $warnings, 'fingerprint' => hash('sha256', json_encode(['period' => $p->only(['id', 'starts_on', 'ends_on', 'pay_date']), 'rows' => $rows, 'warnings' => $warnings], JSON_THROW_ON_ERROR))];
    }

    public function calculate(int $id): PayrollRun
    {
        $this->workforce->permit('payroll.manage');

        return DB::transaction(function () use ($id) {
            $this->workforce->lock();
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if (! in_array($run->status, ['draft', 'calculated', 'needs_review', 'approved'], true)) {
                $this->workforce->fail('Only an unposted run may be recalculated.');
            }
            if ($run->status === 'approved') {
                $run->update(['status' => 'needs_review', 'approved_by' => null, 'approved_at' => null]);
                $this->workforce->audit('payroll_approval_revoked_for_recalculation', $run);
            }
            $prepared = $this->prepared($run);
            $run->lines()->get()->each->delete();
            $totals = array_fill_keys(self::AMOUNTS, '0.00');
            foreach ($prepared['rows'] as $row) {
                $run->lines()->create($row);
                foreach (self::AMOUNTS as $key) {
                    $totals[$key] = Money::add($totals[$key], $row[$key]);
                }
            }
            $run->update(['status' => 'calculated', 'fingerprint' => $prepared['fingerprint'], 'totals' => $totals, 'warnings' => $prepared['warnings'], 'approved_by' => null, 'approved_at' => null]);
            $this->workforce->audit('payroll_calculated', $run, ['revision' => $run->revision]);
            $this->notify('payroll.approve', 'Payroll ready for review / Pagat janë gati për shqyrtim', $run);

            return $run->fresh('period');
        });
    }

    public function approve(int $id, string $fingerprint, bool $acknowledgeWarnings): PayrollRun
    {
        $this->workforce->permit('payroll.approve');

        return DB::transaction(function () use ($id, $fingerprint, $acknowledgeWarnings) {
            $this->workforce->lock();
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'calculated') {
                $this->workforce->fail('Calculate and review the current payroll before approval.');
            }
            $this->assertFingerprint($run, $fingerprint);
            if ($run->warnings && ! $acknowledgeWarnings) {
                $this->workforce->fail('Review and explicitly acknowledge the payroll warnings.');
            }
            $run->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);
            $this->workforce->audit('payroll_approved', $run, ['fingerprint' => $fingerprint, 'warnings_acknowledged' => $acknowledgeWarnings]);

            return $run;
        });
    }

    private function assertFingerprint(PayrollRun $run, string $fingerprint): void
    {
        if (! hash_equals($run->fingerprint ?? '', $fingerprint) || ! hash_equals($run->fingerprint ?? '', $this->prepared($run)['fingerprint'])) {
            $this->workforce->fail('Payroll inputs changed after review. Recalculate and approve the current dataset.');
        }
    }

    public function post(int $id, string $fingerprint): PayrollRun
    {
        $this->workforce->permit('payroll.post');

        return DB::transaction(function () use ($id, $fingerprint) {
            $this->workforce->lock();
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if (in_array($run->status, ['posted', 'paid', 'closed'], true)) {
                if (! hash_equals($run->fingerprint, $fingerprint)) {
                    $this->workforce->fail('This retry does not identify the posted calculation.');
                }

                return $run;
            }
            if ($run->status !== 'approved') {
                $this->workforce->fail('Payroll must be calculated, reviewed and approved before posting.');
            }
            $this->assertFingerprint($run, $fingerprint);
            $this->accounting->assertDateOpen($run->period->ends_on->toDateString());
            $lines = [];
            foreach ($run->lines as $line) {
                $m = $line->snapshot['mappings'];
                $department = $line->snapshot['employee']['department'];
                foreach (['salary_expense' => ['gross', 'debit'], 'employer_pension_expense' => ['employer_pension', 'debit'], 'salary_payable' => ['net', 'credit'], 'employee_pension_payable' => ['employee_pension', 'credit'], 'employer_pension_payable' => ['employer_pension', 'credit'], 'tax_payable' => ['tax', 'credit'], 'deductions_payable' => ['deductions', 'credit'], 'employee_advances' => ['advance_repayment', 'credit']] as $key => [$amount,$side]) {
                    if (Money::compare($line->$amount, 0) > 0) {
                        $lines[] = ['accounting_account_id' => $m[$key], $side => $line->$amount, 'description' => 'Payroll obligation', 'dimensions' => $department ? ['department' => $department] : null];
                    }
                }
            }
            $j = $this->accounting->createAndPost(['posting_date' => $run->period->ends_on->toDateString(), 'description' => 'Payroll '.$run->period->name, 'reference_number' => 'PAY-'.$run->id, 'source_module' => 'payroll', 'source_type' => 'payroll_run', 'source_id' => $run->id, 'source_key' => 'payroll:'.$run->id, 'currency' => 'EUR', 'lines' => $lines]);
            $run->update(['status' => 'posted', 'journal_entry_id' => $j->id, 'posted_by' => Auth::id(), 'posted_at' => now()]);
            $this->workforce->audit('payroll_posted', $run, ['journal_entry_id' => $j->id]);

            return $run->fresh('period');
        });
    }

    public function advanceBalance(int $employeeId, ?string $asOf = null): string
    {
        $paid = EmployeeAdvance::where('employee_id', $employeeId)->when($asOf, fn ($q) => $q->where('paid_on', '<=', $asOf))->sum('amount');
        $repaid = PayrollLine::where('employee_id', $employeeId)->whereHas('run', fn ($q) => $q->whereIn('status', ['posted', 'paid', 'closed'])->when($asOf, fn ($q) => $q->whereHas('period', fn ($q) => $q->where('ends_on', '<=', $asOf))))->sum('advance_repayment');

        return Money::subtract($paid, $repaid);
    }

    public function advance(int $employeeId, array $data): EmployeeAdvance
    {
        $this->workforce->permit('payroll.pay');

        return DB::transaction(function () use ($employeeId, $data) {
            $this->workforce->lock();
            $e = Employee::findOrFail($employeeId);
            if ($old = EmployeeAdvance::where('idempotency_key', $data['idempotency_key'])->first()) {
                $transaction = FinancialAccountTransaction::findOrFail($old->financial_account_transaction_id);
                if ($old->employee_id !== $e->id || Money::compare($old->amount, $data['amount']) !== 0 || $old->paid_on->toDateString() !== $data['paid_on'] || $transaction->financial_account_id !== (int) $data['financial_account_id'] || $old->reason !== $data['reason']) {
                    $this->workforce->fail('This idempotency key identifies a different advance.');
                }

                return $old;
            }
            if (! in_array($e->status, ['active', 'on_leave'], true)) {
                $this->workforce->fail('Employee advances require active employment.');
            }
            $m = $this->mappingIds();
            $account = FinancialAccount::findOrFail($data['financial_account_id']);
            if ($account->currency !== 'EUR') {
                $this->workforce->fail('Payroll payments currently require a EUR cash/bank account.');
            }
            $movement = app(FinancialAccountService::class)->post($account, ['type' => 'outflow', 'amount' => $data['amount'], 'transaction_date' => $data['paid_on'], 'source_type' => 'employee_advance', 'source_id' => $e->id, 'idempotency_key' => 'employee-advance:'.$data['idempotency_key'], 'description' => 'Employee advance', 'reference_number' => $data['idempotency_key']]);
            $cash = $this->accounting->ensureFinancialAccount($account);
            $j = $this->accounting->createAndPost(['posting_date' => $data['paid_on'], 'description' => 'Employee advance', 'source_module' => 'payroll', 'source_type' => 'employee_advance', 'source_id' => $e->id, 'source_key' => 'employee-advance:'.$data['idempotency_key'], 'currency' => 'EUR', 'lines' => [['accounting_account_id' => $m['employee_advances'], 'debit' => $data['amount']], ['accounting_account_id' => $cash->id, 'credit' => $data['amount']]]]);
            $movement->update(['journal_entry_id' => $j->id]);
            $advance = EmployeeAdvance::create([...array_diff_key($data, ['financial_account_id' => true]), 'employee_id' => $e->id, 'financial_account_transaction_id' => $movement->id, 'journal_entry_id' => $j->id, 'created_by' => Auth::id()]);
            // financial_account_id is an input to the existing money service, not a second balance.
            $this->workforce->invalidate();
            $this->workforce->audit('employee_advance_paid', $e, ['advance_id' => $advance->id, 'journal_entry_id' => $j->id, 'reason' => $data['reason']]);

            return $advance;
        });
    }

    public function pay(int $runId, array $data): PayrollPayment
    {
        $this->workforce->permit('payroll.pay');

        return DB::transaction(function () use ($runId, $data) {
            $this->workforce->lock();
            $run = PayrollRun::lockForUpdate()->findOrFail($runId);
            if ($old = PayrollPayment::where('idempotency_key', $data['idempotency_key'])->first()) {
                $movement = FinancialAccountTransaction::findOrFail($old->financial_account_transaction_id);
                if ($old->payroll_run_id !== $run->id || $old->kind !== $data['kind'] || (int) $old->payroll_line_id !== (int) ($data['payroll_line_id'] ?? 0) || Money::compare($old->amount, $data['amount']) !== 0 || $old->paid_on->toDateString() !== $data['paid_on'] || $movement->financial_account_id !== (int) $data['financial_account_id']) {
                    $this->workforce->fail('This payment key is already used for another settlement.');
                }

                return $old;
            }
            if (! in_array($run->status, ['posted', 'paid'], true)) {
                $this->workforce->fail('Only posted payroll obligations may be paid.');
            }
            if ($data['paid_on'] < $run->period->ends_on->toDateString()) {
                $this->workforce->fail('Salary settlement cannot precede the payroll obligation.');
            }
            if ($data['kind'] === 'salary' && substr($data['paid_on'], 0, 7) !== $run->period->pay_date->format('Y-m')) {
                $this->workforce->fail('Payment is outside the reviewed payment month. A delayed/cross-month withholding recalculation needs a controlled correction before payment.');
            }
            $line = null;
            $field = match ($data['kind']) {
                'salary' => 'net','tax' => 'tax','employee_pension' => 'employee_pension','employer_pension' => 'employer_pension','deductions' => 'deductions',default => $this->workforce->fail('Unknown payroll payment obligation.')
            };
            if ($data['kind'] === 'salary') {
                $line = $run->lines()->findOrFail($data['payroll_line_id'] ?? 0);
            } elseif (! empty($data['payroll_line_id'])) {
                $this->workforce->fail('Statutory liability payments are made at run level.');
            }
            $due = $line?->$field ?? $run->totals[$field];
            $already = $run->payments()->where('kind', $data['kind'])->when($line, fn ($q) => $q->where('payroll_line_id', $line->id))->sum('amount');
            if (Money::compare($data['amount'], 0) <= 0 || Money::compare($data['amount'], Money::subtract($due, $already)) > 0) {
                $this->workforce->fail('Payment exceeds the remaining payroll obligation.');
            }
            $account = FinancialAccount::findOrFail($data['financial_account_id']);
            if ($account->currency !== 'EUR') {
                $this->workforce->fail('Use a EUR payment account.');
            }
            $m = $line?->snapshot['mappings'] ?? $run->lines()->firstOrFail()->snapshot['mappings'];
            $key = match ($data['kind']) {
                'salary' => 'salary_payable','tax' => 'tax_payable','employee_pension' => 'employee_pension_payable','employer_pension' => 'employer_pension_payable','deductions' => 'deductions_payable'
            };
            $movement = app(FinancialAccountService::class)->post($account, ['type' => 'outflow', 'amount' => $data['amount'], 'transaction_date' => $data['paid_on'], 'source_type' => 'payroll_payment', 'source_id' => $run->id, 'idempotency_key' => 'payroll-payment:'.$data['idempotency_key'], 'description' => 'Payroll settlement', 'reference_number' => 'PAY-'.$run->id]);
            $cash = $this->accounting->ensureFinancialAccount($account);
            $j = $this->accounting->createAndPost(['posting_date' => $data['paid_on'], 'description' => 'Payroll settlement', 'source_module' => 'payroll', 'source_type' => 'payroll_run', 'source_id' => $run->id, 'source_key' => 'payroll-payment:'.$data['idempotency_key'], 'currency' => 'EUR', 'lines' => [['accounting_account_id' => $m[$key], 'debit' => $data['amount']], ['accounting_account_id' => $cash->id, 'credit' => $data['amount']]]]);
            $movement->update(['journal_entry_id' => $j->id]);
            $payment = PayrollPayment::create(array_intersect_key([...$data, 'payroll_run_id' => $run->id, 'financial_account_transaction_id' => $movement->id, 'journal_entry_id' => $j->id, 'created_by' => Auth::id()], array_flip(['kind', 'paid_on', 'amount', 'idempotency_key', 'payroll_line_id', 'payroll_run_id', 'financial_account_transaction_id', 'journal_entry_id', 'created_by'])));
            if (Money::compare($run->payments()->where('kind', 'salary')->sum('amount'), $run->totals['net']) === 0) {
                $run->update(['status' => 'paid']);
            }
            $this->workforce->audit('payroll_paid', $run, ['payment_id' => $payment->id, 'kind' => $payment->kind, 'journal_entry_id' => $j->id]);

            return $payment;
        });
    }

    public function obligations(PayrollRun $run): array
    {
        $rows = [];
        $mapping = $run->lines()->first()?->snapshot['mappings'] ?? [];
        foreach (['salary' => ['net', 'salary_payable'], 'tax' => ['tax', 'tax_payable'], 'employee_pension' => ['employee_pension', 'employee_pension_payable'], 'employer_pension' => ['employer_pension', 'employer_pension_payable'], 'deductions' => ['deductions', 'deductions_payable']] as $kind => [$field,$control]) {
            $accrued = in_array($run->status, ['posted', 'paid', 'closed'], true) ? ($run->totals[$field] ?? '0.00') : '0.00';
            $paid = Money::normalize($run->payments()->where('kind', $kind)->sum('amount'));
            $remaining = Money::subtract($accrued, $paid);
            $journalIds = collect([$run->journal_entry_id, $run->reversal_journal_id])->filter()->merge($run->payments()->pluck('journal_entry_id'));
            $gl = isset($mapping[$control]) ? JournalLine::where('accounting_account_id', $mapping[$control])->whereIn('journal_entry_id', $journalIds)->selectRaw('COALESCE(SUM(credit - debit),0) as balance')->first()->balance : '0.00';
            $rows[] = ['kind' => $kind, 'accrued' => $accrued, 'paid' => $paid, 'remaining' => $remaining, 'gl' => Money::normalize($gl), 'difference' => Money::subtract($remaining, $gl)];
        }

        return ['rows' => $rows, 'reconciled' => collect($rows)->every(fn ($r) => Money::compare($r['difference'], 0) === 0),
            'declaration_period' => $run->period->pay_date->format('Y-m'), 'nominal_due_date' => $run->period->pay_date->toImmutable()->addMonthNoOverflow()->startOfMonth()->addDays(14)->toDateString(),
            'automatic_edi_filing' => false, 'declaration_readiness' => in_array($run->status, ['paid', 'closed'], true) ? 'PREPARATION ONLY — verify actual payment month and official EDI requirements' : 'NOT READY — salaries not fully settled',
            'qualification' => 'Accrued obligations reconcile to GL. WM/CM preparation is based on the reviewed salary payment month; unpaid payroll is not represented as a filed declaration. Non-working-day deadline extensions require review.'];
    }

    public function close(int $id): PayrollRun
    {
        $this->workforce->permit('payroll.approve');

        return DB::transaction(function () use ($id) {
            $this->workforce->lock();
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if ($run->status === 'closed') {
                return $run;
            }
            if ($run->status !== 'paid') {
                $this->workforce->fail('Pay every employee before closing payroll.');
            }
            $obligations = $this->obligations($run);
            if (! $obligations['reconciled'] || collect($obligations['rows'])->contains(fn ($r) => Money::compare($r['remaining'], 0) !== 0)) {
                $this->workforce->fail('Resolve unpaid salary/tax/pension/deduction obligations and reconciliation differences before closing payroll.');
            }
            $run->update(['status' => 'closed']);
            $run->period->update(['status' => 'closed', 'closed_by' => Auth::id(), 'closed_at' => now()]);
            $this->workforce->audit('payroll_closed', $run);

            return $run;
        });
    }

    public function reverse(int $id, string $date, string $reason): PayrollRun
    {
        $this->workforce->permit('payroll.reverse');

        return DB::transaction(function () use ($id, $date, $reason) {
            $this->workforce->lock();
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            if ($run->status === 'reversed') {
                return $run;
            }
            if ($run->status !== 'posted' || $run->payments()->exists()) {
                $this->workforce->fail('Only unpaid posted payroll can be reversed. Settled payroll requires a reviewed refund/correction workflow.');
            }
            if ($date < $run->period->ends_on->toDateString()) {
                $this->workforce->fail('A reversal cannot precede its original payroll posting.');
            }
            $j = $this->accounting->reverse($run->journal, $date, $reason);
            $run->update(['status' => 'reversed', 'reversal_journal_id' => $j->id, 'reversed_by' => Auth::id(), 'reversed_at' => now(), 'reversal_reason' => $reason]);
            $this->workforce->audit('payroll_reversed', $run, ['journal_entry_id' => $j->id, 'reason' => $reason]);

            return $run;
        });
    }

    public function correction(int $id): PayrollRun
    {
        $this->workforce->permit('payroll.manage');

        return DB::transaction(function () use ($id) {
            $this->workforce->lock();
            $original = PayrollRun::findOrFail($id);
            if ($original->status !== 'reversed') {
                $this->workforce->fail('Reverse an unpaid payroll before preparing its correction.');
            }
            if ($existing = PayrollRun::where('correction_of_id', $id)->first()) {
                return $existing;
            }
            $revision = PayrollRun::where('payroll_period_id', $original->payroll_period_id)->max('revision') + 1;
            $run = PayrollRun::create(['payroll_period_id' => $original->payroll_period_id, 'correction_of_id' => $original->id, 'revision' => $revision, 'created_by' => Auth::id()]);
            $this->workforce->audit('payroll_correction_created', $run, ['correction_of_id' => $id]);

            return $run;
        });
    }

    public function detail(int $id, array $filters = []): array
    {
        $this->workforce->permit('payroll.view');
        $run = PayrollRun::with('period')->findOrFail($id);
        $lines = $run->lines()->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))->orderBy('id')->paginate(40);

        return ['run' => $run, 'lines' => $lines, 'obligations' => $this->obligations($run), 'payments' => $run->payments()->latest('id')->limit(100)->get(),
            'department_costs' => $run->lines()->selectRaw("json_extract(snapshot, '$.employee.department') as department, SUM(gross) as gross, SUM(employer_cost) as employer_cost")->groupByRaw("json_extract(snapshot, '$.employee.department')")->get()->map(function ($r) {
                if (is_string($r->department) && str_starts_with($r->department, '"')) {
                    $r->department = json_decode($r->department, true);
                }

                return $r;
            })];
    }

    private function notify(string $permission, string $title, PayrollRun $run): void
    {
        $roles = Role::whereHas('permissions', fn ($q) => $q->where('slug', $permission))->pluck('slug');
        foreach (User::where('company_id', Auth::user()->company_id)->where('is_active', true)->whereIn('role', $roles)->limit(100)->get() as $user) {
            Notification::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'type' => 'workforce.payroll', 'title' => $title, 'message' => 'Review the payroll in its confidential workspace.', 'data' => ['url' => '/payroll?run='.$run->id]]);
        }
    }
}
