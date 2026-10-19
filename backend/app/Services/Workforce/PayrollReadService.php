<?php

namespace App\Services\Workforce;

use App\Models\AccountingPostingMapping;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\JournalLine;
use App\Models\PayrollLine;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\WorkforceRecord;
use App\Support\CompanyClock;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class PayrollReadService
{
    public function __construct(private WorkforceService $workforce, private PayrollService $payroll) {}

    public function summary(): array
    {
        $this->workforce->permit('payroll.view');
        $run = PayrollRun::select(['id','company_id','payroll_period_id','revision','status','totals'])->with('period')->latest('id')->first();
        $pending = WorkforceRecord::where('status', 'requested')->selectRaw('kind,COUNT(*) as count')->groupBy('kind')->get();
        $missing = Employee::whereIn('status', ['active', 'on_leave'])->whereDoesntHave('terms', fn ($q) => $q->where('effective_from', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString())))->count();

        return ['currency' => 'EUR', 'latest_run' => $run, 'employees' => $run ? $run->lines()->count() : 0, 'active_employees' => Employee::whereIn('status', ['active', 'on_leave'])->count(),
            'missing_terms' => $missing, 'pending' => $pending, 'unpaid_runs' => PayrollRun::with('period')->whereIn('status', ['posted', 'paid'])->latest('id')->limit(12)->get(['id', 'payroll_period_id', 'status', 'totals']),
            'automatic_edi_filing' => false, 'qualification' => 'Payroll accruals and preparation totals only. EDI filing and payment are performed separately by an authorized person.'];
    }

    public function reconcile(?string $to = null): array
    {
        $to ??= CompanyClock::today()->toDateString();
        $mappings = AccountingPostingMapping::where('mapping_key', 'like', 'payroll_%')->get()->keyBy('mapping_key');
        $runs = PayrollRun::whereIn('status', ['posted', 'paid', 'closed', 'reversed'])->whereHas('period', fn ($q) => $q->where('ends_on', '<=', $to))
            ->where(fn ($q) => $q->where('status', '!=', 'reversed')->orWhereHas('journal', fn ($q) => $q->whereHas('reversal', fn ($q) => $q->where('posting_date', '>', $to))));
        $rows = [];
        foreach (['salary' => ['net', 'salary_payable'], 'tax' => ['tax', 'tax_payable'], 'employee_pension' => ['employee_pension', 'employee_pension_payable'], 'employer_pension' => ['employer_pension', 'employer_pension_payable'], 'deductions' => ['deductions', 'deductions_payable'], 'advance' => ['advance_repayment', 'employee_advances']] as $kind => [$field,$key]) {
            $mapping = $mappings->get('payroll_'.$key);
            if (! $mapping) {
                continue;
            }
            $total = PayrollLine::whereIn('payroll_run_id', (clone $runs)->select('id'))->sum($field);
            if ($kind === 'advance') {
                $operational = Money::subtract(EmployeeAdvance::where('paid_on', '<=', $to)->sum('amount'), $total);
            } else {
                $operational = Money::subtract($total, PayrollPayment::where('kind', $kind)->where('paid_on', '<=', $to)->sum('amount'));
            }
            $aggregate = JournalLine::where('accounting_account_id', $mapping->accounting_account_id)->whereHas('journalEntry', fn ($q) => $q->whereIn('status', ['posted', 'reversed'])->where('posting_date', '<=', $to))->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();
            $gl = $kind === 'advance' ? Money::subtract($aggregate->d, $aggregate->c) : Money::subtract($aggregate->c, $aggregate->d);
            $rows[] = ['control' => $key, 'operational_total' => $operational, 'gl_total' => $gl, 'difference' => Money::subtract($operational, $gl), 'reconciled' => Money::compare($operational, $gl) === 0];
        }

        return ['as_of' => $to, 'rows' => $rows, 'reconciled' => collect($rows)->every('reconciled')];
    }

    public function closeChecks(string $from, string $to): array
    {
        if (! WorkforcePresence::exists()) {
            return [];
        }
        $unposted = PayrollRun::whereIn('status', ['draft', 'calculated', 'needs_review', 'approved'])->whereHas('period', fn ($q) => $q->where('starts_on', '<=', $to)->where('ends_on', '>=', $from))->count();
        $missing = Employee::whereNotIn('status', ['draft', 'archived'])->where('start_date', '<=', $to)->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $from))->whereHas('terms', fn ($q) => $q->where('effective_from', '<=', $to))
            ->whereHas('terms', fn ($q) => $q->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from)))->exists()
            && ! PayrollRun::whereIn('status', ['posted', 'paid', 'closed'])->whereHas('period', fn ($q) => $q->where('starts_on', '<=', $from)->where('ends_on', '>=', $to))->exists();
        $difference = collect($this->reconcile($to)['rows'])->where('reconciled', false)->count();

        return [
            ['code' => 'payroll_unposted', 'label' => 'Payroll obligations are posted for the period', 'count' => $unposted + (int) $missing],
            ['code' => 'payroll_controls', 'label' => 'Salary, pension, tax, deductions and employee advances reconcile with GL', 'count' => $difference],
        ];
    }

    public function attention(): array
    {
        if(!WorkforcePresence::exists()) return [];
        $items = [];
        if ($this->workforce->can('workforce.approve')) {
            foreach (WorkforceRecord::where('status', 'requested')->whereIn('kind', ['leave', 'overtime'])->orderBy('starts_on')->limit(15)->get(['id', 'kind', 'employee_id', 'starts_on']) as $r) {
                $items[] = ['kind' => 'workforce', 'id' => 'request-'.$r->id, 'title' => ucfirst($r->kind).' approval', 'description' => 'A workforce request needs an authorized decision.', 'url' => '/workforce?tab=records&record='.$r->id, 'due_at' => null];
            }
        }
        if ($this->workforce->can('employees.view')) {
            foreach (Employee::whereHas('terms', fn ($q) => $q->whereBetween('effective_to', [today(), today()->addDays(30)]))->orderBy('id')->limit(10)->get(['id', 'employee_number']) as $e) {
                $items[] = ['kind' => 'workforce', 'id' => 'contract-'.$e->id, 'title' => 'Contract expiry review', 'description' => $e->employee_number, 'url' => '/workforce?employee='.$e->id, 'due_at' => null];
            }
        }
        if ($this->workforce->can('payroll.view')) {
            foreach (PayrollRun::with('period')->whereIn('status', ['needs_review', 'calculated', 'posted'])->latest('id')->limit(10)->get() as $r) {
                $items[] = ['kind' => 'payroll', 'id' => $r->id, 'title' => 'Payroll '.str_replace('_', ' ', $r->status), 'description' => $r->period->name, 'url' => '/payroll?run='.$r->id, 'due_at' => null];
            }
        }

        return $items;
    }

    public function payslip(int $id): array
    {
        abort_unless($this->workforce->can('payroll.view') || $this->workforce->can('workforce.self'), 403);
        $line = PayrollLine::with('run.period')->when(! $this->workforce->can('payroll.view'), fn ($q) => $q->whereHas('employee', fn ($q) => $q->where('user_id', Auth::id())))->findOrFail($id);
        abort_unless(in_array($line->run->status, ['posted', 'paid', 'closed', 'reversed'], true), 422, 'Payslips are available only after payroll posting.');
        $paid = Money::normalize($line->run->payments()->where('kind', 'salary')->where('payroll_line_id', $line->id)->sum('amount'));

        return ['company' => Auth::user()->company->only(['name']), 'line' => $line, 'paid' => $paid, 'remaining' => Money::subtract($line->net, $paid), 'confidential' => true, 'languages' => ['en', 'sq']];
    }

    public function export(int $id, string $type)
    {
        $this->workforce->permit('payroll.view');
        if ($type === 'payments') {
            $this->workforce->permit('payroll.pay');
        }
        if ($type === 'declaration') {
            $this->workforce->permit('employees.compensation');
        }
        $run = PayrollRun::with('period')->findOrFail($id);
        abort_unless(in_array($run->status, ['posted', 'paid', 'closed'], true), 422, 'Exports require a posted payroll run.');
        foreach ($run->lines()->lazyById(100) as $line) {
            if ($type === 'declaration' && blank($line->private_snapshot['personal_number'] ?? null)) {
                abort(422, 'A posted employee identifier is missing. Resolve declaration identity through a reviewed correction before preparing the declaration; no upload file was created.');
            }
        }
        $header = $type === 'payments' ? ['Employee number', 'Name', 'Payment method', 'Bank account', 'Net amount', 'Currency', 'Reference', 'Qualification'] : ['Employee number', 'Personal identifier', 'Name', 'Period', 'Employer classification', 'Gross', 'Employee pension', 'Employer pension', 'Taxable', 'Withholding tax', 'Net', 'Rule version', 'Qualification'];

        // Streaming, bounded reads; spreadsheet formula characters cannot become executable cells.
        return response()->streamDownload(function () use ($run, $type, $header) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $header, ',', '"', '');
            foreach ($run->lines()->with('employee')->orderBy('id')->lazyById(100) as $l) {
                $e = $l->snapshot['employee'];
                $terms = $l->snapshot['terms'];
                $term = end($terms);
                $row = $type === 'payments' ? [$e['employee_number'], $e['name'], $e['payment_method'], $l->private_snapshot['bank_account'] ?? null, $l->net, 'EUR', 'PAY-'.$run->id, 'Preparation only; reviewed bank details must be verified before payment. No bank integration.'] :
                    [$e['employee_number'], $l->private_snapshot['personal_number'], $e['name'], $run->period->name, $term['employer_classification'], $l->gross, $l->employee_pension, $l->employer_pension, $l->taxable, $l->tax, $l->net, $l->snapshot['rule']['version'], 'Preparation only. Confirm actual payment period, employee identity and official WM/CM upload format. Not an EDI filing.'];
                fputcsv($stream, array_map(fn ($cell) => preg_match('/^[\s]*[=+@-]/u', (string) $cell) ? "'".$cell : $cell, $row), ',', '"', '');
            } fclose($stream);
        }, 'payroll-'.$run->id.'-'.$type.'-preparation.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
