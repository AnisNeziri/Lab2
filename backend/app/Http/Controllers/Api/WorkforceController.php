<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Workforce\PayrollService;
use App\Services\Workforce\WorkforceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class WorkforceController extends Controller
{
    public function __construct(private WorkforceService $workforce, private PayrollService $payroll) {}

    public function index(Request $r)
    {
        return $this->workforce->employees($r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|string|max:30']));
    }

    public function show(int $id)
    {
        return $this->workforce->profile($id);
    }

    public function self()
    {
        $this->workforce->permit('workforce.self');
        $employee = Employee::where('user_id', Auth::id())->first();
        abort_unless($employee, 404, 'Your AIMS user is not linked to an employee profile. HR can link your existing account without creating a new login.');

        return $this->workforce->profile($employee->id, true);
    }

    public function store(Request $r)
    {
        return response()->json($this->workforce->saveEmployee($this->employeeData($r)), 201);
    }

    public function update(Request $r, int $id)
    {
        return $this->workforce->saveEmployee($this->employeeData($r), $id);
    }

    private function employeeData(Request $r): array
    {
        $this->workforce->permit('employees.manage');

        return $r->validate(['employee_number' => 'required|string|max:60', 'name' => 'required|string|max:150', 'email' => 'nullable|email|max:150', 'phone' => 'nullable|string|max:60', 'address' => 'nullable|string|max:1000', 'department' => 'nullable|string|max:100', 'position' => 'nullable|string|max:100', 'manager_id' => 'nullable|integer|min:1', 'user_id' => 'nullable|integer|min:1', 'status' => ['required', Rule::in(['draft', 'active', 'on_leave', 'suspended', 'terminated', 'archived'])], 'start_date' => 'required|date_format:Y-m-d', 'termination_date' => 'nullable|date_format:Y-m-d', 'payment_method' => ['required', Rule::in(['bank', 'cash'])], 'bank_account' => 'nullable|string|max:100', 'personal_number' => 'nullable|string|max:60', 'revoke_access' => 'sometimes|boolean', 'lifecycle_reason' => 'nullable|string|min:3|max:2000']);
    }

    public function terms(Request $r, int $id)
    {
        $this->workforce->permit('employees.compensation');
        $data = $r->validate(['effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d', 'supersedes_id' => 'nullable|integer|min:1', 'contract_type' => 'required|string|max:100', 'position' => 'required|string|max:100', 'department' => 'nullable|string|max:100', 'probation_until' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:4000', 'pay_basis' => ['required', Rule::in(['monthly', 'hourly', 'daily'])], 'amount' => 'required|numeric|gt:0|max:10000000', 'currency' => 'required|in:EUR', 'work_schedule_id' => 'nullable|integer|min:1', 'proration_policy' => 'required|in:calendar_days', 'employer_classification' => 'required|in:primary,secondary', 'pension_treatment' => 'required|in:resident_contributor,foreign_temporary_excluded', 'employee_pension_rate' => 'required|numeric|min:0|max:15', 'employer_pension_rate' => 'required|numeric|min:0|max:15', 'pension_basis' => 'nullable|string|max:2000', 'reason' => 'required|string|min:3|max:2000']);

        return response()->json($this->workforce->terms($id, $data), 201);
    }

    public function options()
    {
        abort_unless($this->workforce->can('employees.view') || $this->workforce->can('workforce.manage') || $this->workforce->can('payroll.view'), 403);

        return ['schedules' => WorkSchedule::orderBy('name')->limit(100)->get(),
            'employees' => Employee::orderBy('name')->limit(200)->get(['id', 'employee_number', 'name', 'department', 'position']),
            'users' => $this->workforce->can('employees.manage') ? User::where('company_id', Auth::user()->company_id)->where('is_active', true)->orderBy('name')->limit(200)->get(['id', 'name', 'email']) : [],
            'payment_accounts' => $this->workforce->can('payroll.pay') ? FinancialAccount::where('currency', 'EUR')->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name', 'type', 'currency']) : [],
            'limits' => ['employees' => 200, 'schedules' => 100, 'payment_accounts' => 100]];
    }

    public function privateDetails(Request $r, int $id)
    {
        $this->workforce->permit('employees.compensation');

        return $this->workforce->privateDetails($id, $r->validate(['bank_account' => 'sometimes|nullable|string|max:100', 'personal_number' => 'sometimes|nullable|string|max:60', 'reason' => 'required|string|min:3|max:2000']));
    }

    public function schedule(Request $r)
    {
        $this->workforce->permit('workforce.manage');

        return response()->json($this->workforce->schedule($r->validate(['name' => 'required|string|max:100', 'week' => 'required|array|min:1|max:7', 'week.*' => 'required|array:start,end,paid_hours', 'week.*.start' => 'required|string|max:5', 'week.*.end' => 'required|string|max:5', 'week.*.paid_hours' => 'required|numeric|gt:0|max:24'])), 201);
    }

    public function records(Request $r)
    {
        return $this->workforce->records($r->validate(['employee_id' => 'nullable|integer|min:1', 'kind' => 'nullable|string|max:30', 'status' => 'nullable|string|max:30']), $r->routeIs('workforce.self.records'));
    }

    public function record(Request $r)
    {
        $own = $r->routeIs('workforce.self.record');
        $data = $r->validate(['employee_id' => 'required|integer|min:1', 'kind' => ['required', Rule::in(['attendance', 'leave', 'overtime', 'bonus', 'deduction', 'advance_repayment', 'entitlement', 'shift'])], 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d', 'units' => 'required|numeric|min:0|max:100000', 'amount' => 'required|numeric|min:0|max:10000000', 'reason' => 'required|string|min:3|max:2000', 'details' => 'nullable|array', 'details.leave_type' => 'nullable|in:annual,sick,unpaid,other', 'details.attendance_status' => 'nullable|in:scheduled,present,late,absent,leave,sick,other', 'details.pay_policy_basis' => 'nullable|string|max:2000', 'details.policy_basis' => 'nullable|string|max:2000', 'details.start' => 'nullable|date_format:H:i', 'details.end' => 'nullable|date_format:H:i', 'details.source' => 'nullable|string|max:100']);
        if ($own) {
            $data['employee_id'] = Employee::where('user_id', Auth::id())->firstOrFail()->id;
        }

        return response()->json($this->workforce->record($data, $own), 201);
    }

    public function decide(Request $r, int $id)
    {
        $data = $r->validate(['status' => 'required|in:approved,rejected,cancelled,taken', 'reason' => 'required|string|min:3|max:2000']);

        return $this->workforce->decide($id, $data['status'], $data['reason'], $r->routeIs('workforce.self.decide'));
    }

    public function advance(Request $r, int $id)
    {
        $this->workforce->permit('payroll.pay');

        return response()->json($this->payroll->advance($id, $r->validate(['financial_account_id' => 'required|integer|min:1', 'amount' => 'required|numeric|gt:0|max:10000000', 'paid_on' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:3|max:2000', 'idempotency_key' => 'required|string|min:8|max:100'])), 201);
    }
}
