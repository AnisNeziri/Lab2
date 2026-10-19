<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workforce\KosovoPayrollCalculator;
use App\Services\Workforce\PayrollReadService;
use App\Services\Workforce\PayrollService;
use App\Services\Workforce\WorkforceService;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function __construct(private PayrollService $payroll, private WorkforceService $workforce, private PayrollReadService $read) {}

    public function index(Request $r)
    {
        return $this->payroll->runs($r->validate(['status' => 'nullable|string|max:30']));
    }

    public function summary()
    {
        return $this->read->summary();
    }

    public function show(Request $r, int $id)
    {
        return $this->payroll->detail($id, $r->validate(['employee_id' => 'nullable|integer|min:1']));
    }

    public function store(Request $r)
    {
        return response()->json($this->payroll->createPeriod($r->validate(['name' => 'required|string|max:100', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d', 'pay_date' => 'required|date_format:Y-m-d'])), 201);
    }

    public function configuration()
    {
        return $this->payroll->configuration();
    }

    public function initialize()
    {
        return $this->payroll->initialize();
    }

    public function mappings(Request $r)
    {
        return $this->payroll->mappings($r->validate(['mappings' => 'required|array|min:1', 'mappings.*' => 'required|integer|min:1'])['mappings']);
    }

    public function rule(Request $r)
    {
        return response()->json($this->payroll->rule($r->validate(['version' => 'required|string|max:100', 'effective_from' => 'required|date_format:Y-m-d', 'sources' => 'required|array|min:1|max:10', 'sources.*' => 'required|url|max:1000'])), 201);
    }

    public function calculate(int $id)
    {
        return $this->payroll->calculate($id);
    }

    public function approve(Request $r, int $id)
    {
        $v = $r->validate(['fingerprint' => 'required|string|size:64', 'acknowledge_warnings' => 'required|boolean']);

        return $this->payroll->approve($id, $v['fingerprint'], $v['acknowledge_warnings']);
    }

    public function post(Request $r, int $id)
    {
        return $this->payroll->post($id, $r->validate(['fingerprint' => 'required|string|size:64'])['fingerprint']);
    }

    public function pay(Request $r, int $id)
    {
        $this->workforce->permit('payroll.pay');
        $data = $r->validate(['kind' => 'required|in:salary,tax,employee_pension,employer_pension,deductions', 'payroll_line_id' => 'nullable|integer|min:1', 'amount' => 'required|numeric|gt:0|max:10000000', 'paid_on' => 'required|date_format:Y-m-d', 'financial_account_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|min:8|max:100']);

        return response()->json($this->payroll->pay($id, $data), 201);
    }

    public function close(int $id)
    {
        return $this->payroll->close($id);
    }

    public function reverse(Request $r, int $id)
    {
        $v = $r->validate(['date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:3|max:2000']);

        return $this->payroll->reverse($id, $v['date'], $v['reason']);
    }

    public function correction(int $id)
    {
        return $this->payroll->correction($id);
    }

    public function payslip(int $id)
    {
        return $this->read->payslip($id);
    }

    public function export(Request $r, int $id)
    {
        return $this->read->export($id, $r->validate(['type' => 'required|in:declaration,payments'])['type']);
    }

    public function estimate(Request $r)
    {
        $this->workforce->permit('payroll.manage');
        $v = $r->validate(['period' => 'required|date_format:Y-m-d', 'desired_net' => 'required|numeric|gt:0|max:1000000', 'employer_classification' => 'required|in:primary,secondary', 'pension_treatment' => 'required|in:resident_contributor,foreign_temporary_excluded', 'employee_pension_rate' => 'required|numeric|min:0|max:15', 'employer_pension_rate' => 'required|numeric|min:0|max:15', 'pension_basis' => 'nullable|string|max:2000']);

        return app(KosovoPayrollCalculator::class)->estimateGross($this->payroll->ruleFor($v['period'])->configuration, $v, $v['desired_net']);
    }
}
