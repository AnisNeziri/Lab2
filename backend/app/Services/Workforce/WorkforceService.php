<?php

namespace App\Services\Workforce;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmploymentTerm;
use App\Models\Notification;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Models\WorkforceRecord;
use App\Models\WorkSchedule;
use App\Services\PermissionService;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkforceService
{
    public function can(string $permission): bool
    {
        return Auth::user()?->company_id && app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission);
    }

    public function permit(string $permission): void
    {
        abort_unless($this->can($permission), 403);
    }

    public function lock(): void
    {
        abort_unless(Auth::user()?->company_id, 403);
        DB::table('companies')->where('id', Auth::user()->company_id)->lockForUpdate()->firstOrFail();
    }

    public function audit(string $action, $record, array $facts = []): void
    {
        // IDs, reasons and versions only: ordinary Activity logs never contain salaries, IDs or bank details.
        ActivityLog::create(['company_id' => Auth::user()->company_id, 'user_id' => Auth::id(), 'action' => 'workforce.'.$action, 'entity' => class_basename($record), 'entity_id' => $record->id, 'description' => str_replace('_', ' ', $action), 'new_value' => $facts]);
    }

    public function invalidate(): void
    {
        PayrollRun::whereIn('status', ['calculated', 'approved'])->update(['status' => 'needs_review', 'approved_by' => null, 'approved_at' => null]);
    }

    public function employees(array $f)
    {
        $this->permit('employees.view');

        return Employee::when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('name', 'like', '%'.mb_substr($v, 0, 100).'%')->orWhere('employee_number', 'like', '%'.mb_substr($v, 0, 100).'%')->orWhere('department', 'like', '%'.mb_substr($v, 0, 100).'%')->orWhere('position', 'like', '%'.mb_substr($v, 0, 100).'%')))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderBy('name')->paginate(30);
    }

    public function saveEmployee(array $data, ?int $id = null): Employee
    {
        $this->permit('employees.manage');

        return DB::transaction(function () use ($data, $id) {
            $this->lock();
            $e = $id ? Employee::lockForUpdate()->findOrFail($id) : new Employee(['company_id' => Auth::user()->company_id]);
            foreach (['bank_account', 'personal_number'] as $key) {
                if (array_key_exists($key, $data)) {
                    $this->permit('employees.compensation');
                }
            }
            if (! empty($data['user_id'])) {
                User::where('company_id', Auth::user()->company_id)->findOrFail($data['user_id']);
                if (Employee::where('user_id', $data['user_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                    $this->fail('This user is already linked to an employee.');
                }
            }
            if (! empty($data['manager_id'])) {
                Employee::findOrFail($data['manager_id']);
                $seen = $id ? [$id] : [];
                $manager = $data['manager_id'];
                while ($manager) {
                    if (in_array((int) $manager, $seen, true)) {
                        $this->fail('Manager relationships cannot contain a cycle.');
                    } $seen[] = (int) $manager;
                    $manager = Employee::findOrFail($manager)->manager_id;
                }
            }
            if (Employee::where('employee_number', $data['employee_number'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                $this->fail('Employee number is already in use.');
            }
            if (in_array($data['status'], ['terminated', 'archived'], true) && empty($data['termination_date'])) {
                $this->fail('A termination date is required before ending employment.');
            }
            $lifecycleReason = $data['lifecycle_reason'] ?? null;
            unset($data['lifecycle_reason']);
            if ($e->exists && $e->status !== $data['status'] && in_array($data['status'], ['terminated', 'archived', 'suspended'], true) && blank($lifecycleReason)) {
                $this->fail('A reason is required for termination, archival or suspension.');
            }
            if (! empty($data['termination_date']) && $data['termination_date'] < $data['start_date']) {
                $this->fail('Termination must not precede employment start.');
            }
            $revoke = (bool) ($data['revoke_access'] ?? false);
            unset($data['revoke_access']);
            $e->fill($data)->save();
            if ($revoke) {
                $this->permit('users.manage');
                if (! $e->termination_date || ! in_array($e->status, ['terminated', 'archived'], true)) {
                    $this->fail('Access revocation is available only when ending employment.');
                }
                if ($e->user_id === Auth::id()) {
                    $this->fail('You cannot revoke your own access here.');
                }
                if ($e->user_id) {
                    User::where('company_id', Auth::user()->company_id)->whereKey($e->user_id)->update(['is_active' => false, 'api_token' => null]);
                    // Existing authentication middleware checks is_active for every request.
                }
            }
            $this->invalidate();
            $this->audit('employee_saved', $e, ['status' => $e->status, 'reason' => $lifecycleReason]);

            return $e->fresh();
        });
    }

    public function profile(int $id, bool $own = false): array
    {
        if ($own) {
            $this->permit('workforce.self');
        } else {
            $this->permit('employees.view');
        }
        $e = Employee::when($own, fn ($q) => $q->where('user_id', Auth::id()))->findOrFail($id);
        $financial = $own || $this->can('employees.compensation');
        $records = $e->records()->whereIn('kind', ['leave', 'attendance', 'shift', 'entitlement'])->orderByDesc('starts_on')->limit(100)->get();
        if (! $financial) {
            $records->each(fn ($r) => $r->makeHidden(['amount']));
        }

        return ['employee' => $e, 'payment_details' => $financial ? ['bank_account_masked' => $e->bank_account ? '•••• '.substr($e->bank_account, -4) : null, 'personal_number_present' => filled($e->personal_number)] : null, 'terms' => $financial ? $e->terms()->with('schedule')->get() : $e->terms()->get(['id', 'employee_id', 'effective_from', 'effective_to', 'contract_type', 'position', 'department', 'work_schedule_id', 'probation_until']),
            'records' => $records, 'leave_balance' => $this->leaveBalance($e), 'advance_balance' => $financial ? app(PayrollService::class)->advanceBalance($e->id) : null,
            'payroll' => $own || $this->can('payroll.view') ? PayrollLine::where('employee_id', $e->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['posted', 'paid', 'closed', 'reversed']))->with('run.period')->latest('id')->limit(24)->get() : [],
            'activity' => ActivityLog::where('entity', 'Employee')->where('entity_id', $e->id)->latest('created_at')->limit(30)->get()];
    }

    public function terms(int $employeeId, array $data): EmploymentTerm
    {
        $this->permit('employees.compensation');

        return DB::transaction(function () use ($employeeId, $data) {
            $this->lock();
            $e = Employee::findOrFail($employeeId);
            $previous = $e->terms()->orderByDesc('effective_from')->first();
            if ($previous) {
                if ((int) ($data['supersedes_id'] ?? 0) !== $previous->id || $data['effective_from'] <= $previous->effective_from->toDateString()) {
                    $this->fail('Append a revision after the latest approved terms, explicitly referencing that version. Overlapping independent contracts are not supported.');
                }
            } elseif (! empty($data['supersedes_id'])) {
                $this->fail('No previous terms exist for this employee.');
            }
            if ($data['effective_from'] < $e->start_date->toDateString()) {
                $this->fail('Terms cannot precede employment start.');
            }
            if (! empty($data['effective_to']) && $data['effective_to'] < $data['effective_from']) {
                $this->fail('Terms end before they start.');
            }
            $this->assertUnused($employeeId, $data['effective_from'], $data['effective_to'] ?? '9999-12-31');
            if (! empty($data['work_schedule_id'])) {
                WorkSchedule::findOrFail($data['work_schedule_id']);
            }
            if (($data['currency'] ?? 'EUR') !== 'EUR' || CompanyCurrency::forCompanyId(Auth::user()->company_id) !== 'EUR') {
                $this->fail('The verified Kosovo payroll rule currently supports EUR companies and EUR compensation only.');
            }
            app(KosovoPayrollCalculator::class)->calculate(KosovoPayrollCalculator::CURRENT, [...$data, 'gross' => '1000']);
            $term = EmploymentTerm::create([...$data, 'employee_id' => $employeeId, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            if ($term->effective_from->lte(today())) {
                $e->update(['position' => $term->position, 'department' => $term->department]);
            }
            $this->invalidate();
            $this->audit('terms_approved', $e, ['term_id' => $term->id, 'supersedes_id' => $previous?->id, 'effective_from' => $data['effective_from'], 'reason' => $data['reason']]);

            return $term;
        });
    }

    public function privateDetails(int $employeeId, array $data): Employee
    {
        $this->permit('employees.compensation');

        return DB::transaction(function () use ($employeeId, $data) {
            $this->lock();
            $e = Employee::lockForUpdate()->findOrFail($employeeId);
            $reason = $data['reason'];
            unset($data['reason']);
            $e->update($data);
            $this->invalidate();
            $this->audit('private_payment_details_changed', $e, ['reason' => $reason]);

            return $e;
        });
    }

    public function schedule(array $data): WorkSchedule
    {
        $this->permit('workforce.manage');

        return DB::transaction(function () use ($data) {
            $this->lock();
            foreach ($data['week'] as $day => $slot) {
                if (! in_array((string) $day, ['1', '2', '3', '4', '5', '6', '7'], true) || ! preg_match('/^\d{2}:\d{2}$/', $slot['start'] ?? '') || ! preg_match('/^\d{2}:\d{2}$/', $slot['end'] ?? '')) {
                    $this->fail('A schedule needs ISO weekdays 1–7 and valid start/end times.');
                }
                foreach (['start', 'end'] as $field) {
                    if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $slot[$field])) {
                        $this->fail('Use a valid 24-hour schedule time.');
                    }
                }
                if (Money::compareDecimal($slot['paid_hours'] ?? 0, 0) <= 0 || Money::compareDecimal($slot['paid_hours'], 24) > 0) {
                    $this->fail('Configure paid hours per working day, including the chosen break policy.');
                }
            }
            $schedule = WorkSchedule::create($data);
            $this->audit('schedule_created', $schedule);

            return $schedule;
        });
    }

    public function records(array $f, bool $own = false)
    {
        if ($own) {
            $this->permit('workforce.self');
        } else {
            $this->permit('workforce.manage');
        }

        return WorkforceRecord::with('employee:id,employee_number,name')->when($own, fn ($q) => $q->whereHas('employee', fn ($q) => $q->where('user_id', Auth::id())))
            ->when(! $own && ! $this->can('payroll.view'), fn ($q) => $q->whereNotIn('kind', ['bonus', 'deduction', 'advance_repayment']))
            ->when($f['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->latest('starts_on')->latest('id')->paginate(30)
            ->through(fn ($r) => ! $own && ! $this->can('payroll.view') ? $r->makeHidden(['amount']) : $r);
    }

    public function record(array $data, bool $own = false): WorkforceRecord
    {
        if ($own) {
            $this->permit('workforce.self');
        } else {
            $this->permit('workforce.manage');
        }

        return DB::transaction(function () use ($data, $own) {
            $this->lock();
            $e = Employee::when($own, fn ($q) => $q->where('user_id', Auth::id()))->findOrFail($data['employee_id']);
            if ($own && $data['kind'] !== 'leave') {
                abort(403);
            }
            if (in_array($data['kind'], ['bonus', 'deduction', 'advance_repayment', 'overtime'], true)) {
                $this->permit('payroll.manage');
            }
            if ($data['ends_on'] < $data['starts_on']) {
                $this->fail('Record end must follow its start.');
            }
            $this->assertUnused($e->id, $data['starts_on'], $data['ends_on']);
            if (in_array($data['kind'], ['bonus', 'deduction', 'advance_repayment', 'overtime'], true) || ($data['kind'] === 'leave' && ($data['details']['leave_type'] ?? '') === 'unpaid')) {
                if (substr($data['starts_on'], 0, 7) !== substr($data['ends_on'], 0, 7)) {
                    $this->fail('Payroll amounts must belong to one month. Split an unpaid leave spanning months into separately reviewed amounts.');
                }
            }
            if ($data['kind'] === 'leave') {
                $type = $data['details']['leave_type'] ?? '';
                if (! in_array($type, ['annual', 'sick', 'unpaid', 'other'], true)) {
                    $this->fail('Select a leave type.');
                }
                if ($type === 'annual' && substr($data['starts_on'], 0, 4) !== substr($data['ends_on'], 0, 4)) {
                    $this->fail('Split annual leave across entitlement years so each year’s allowance remains explicit.');
                }
                if ($own || $type !== 'unpaid') {
                    $data['amount'] = '0.00';
                }
                if ($type === 'unpaid' && ! $own && blank($data['details']['pay_policy_basis'] ?? null)) {
                    $this->fail('Unpaid leave needs an explicit reviewed gross-pay reduction and policy basis.');
                }
                if (WorkforceRecord::where('employee_id', $e->id)->where('kind', 'leave')->whereNotIn('status', ['rejected', 'cancelled'])->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                    $this->fail('This leave overlaps an existing request.');
                }
            }
            if ($data['kind'] === 'overtime' && (Money::compare($data['amount'], 0) <= 0 || Money::compareDecimal($data['units'], 0) <= 0 || blank($data['details']['pay_policy_basis'] ?? null))) {
                $this->fail('Overtime needs approved hours, an explicit total gross amount and a compensation-policy basis. No legal premium is assumed.');
            }
            if ($data['kind'] === 'attendance' && ! in_array($data['details']['attendance_status'] ?? '', ['scheduled', 'present', 'late', 'absent', 'leave', 'sick', 'other'], true)) {
                $this->fail('Select an attendance status.');
            }
            if ($data['kind'] === 'shift' && (blank($data['details']['start'] ?? null) || blank($data['details']['end'] ?? null))) {
                $this->fail('A shift needs start and end times.');
            }
            if ($data['kind'] === 'entitlement' && ($own || blank($data['details']['policy_basis'] ?? null))) {
                $this->fail('Leave entitlement requires an authorized policy basis.');
            }
            $r = WorkforceRecord::create([...$data, 'status' => 'requested', 'created_by' => Auth::id()]);
            $this->invalidate();
            $this->audit('record_requested', $e, ['record_id' => $r->id, 'kind' => $r->kind]);

            return $r;
        });
    }

    public function decide(int $id, string $status, string $reason, bool $own = false): WorkforceRecord
    {
        if ($own) {
            $this->permit('workforce.self');
        } else {
            $this->permit('workforce.approve');
        }

        return DB::transaction(function () use ($id, $status, $reason, $own) {
            $this->lock();
            $r = WorkforceRecord::when($own, fn ($q) => $q->where('kind', 'leave')->whereHas('employee', fn ($q) => $q->where('user_id', Auth::id())))->lockForUpdate()->findOrFail($id);
            if ($own && ! ($status === 'cancelled' && $r->status === 'requested')) {
                abort(403);
            }
            if (in_array($r->kind, ['bonus', 'deduction', 'advance_repayment', 'overtime'], true)) {
                $this->permit('payroll.approve');
            }
            if (! ($r->status === 'requested' && in_array($status, ['rejected', 'cancelled'], true))) {
                $this->assertUnused($r->employee_id, $r->starts_on->toDateString(), $r->ends_on->toDateString());
            }
            $valid = ($r->status === 'requested' && in_array($status, ['approved', 'rejected', 'cancelled'], true)) || ($r->status === 'approved' && in_array($status, ['cancelled', 'taken'], true) && $r->kind === 'leave');
            if (! $valid) {
                $this->fail('This approval transition is not allowed.');
            }
            if ($status === 'taken' && $r->ends_on->isFuture()) {
                $this->fail('Future leave cannot be marked taken.');
            }
            if ($status === 'approved' && $r->kind === 'leave' && ($r->details['leave_type'] ?? '') === 'unpaid' && blank($r->details['pay_policy_basis'] ?? null)) {
                $this->fail('HR must replace this unpaid leave request with an explicit reviewed payroll reduction and policy basis before approval.');
            }
            if ($status === 'approved' && $r->kind === 'leave' && ($r->details['leave_type'] ?? '') === 'annual') {
                $balance = $this->leaveBalance(Employee::findOrFail($r->employee_id), (int) $r->starts_on->format('Y'));
                if (! $balance['configured'] || Money::compareDecimal($r->units, $balance['remaining']) > 0) {
                    $this->fail('Configure sufficient annual leave entitlement before approving this request.');
                }
            }
            $r->update(['status' => $status, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            $this->invalidate();
            $this->audit('record_'.$status, Employee::findOrFail($r->employee_id), ['record_id' => $r->id, 'kind' => $r->kind, 'reason' => $reason]);
            if ($r->kind === 'leave' && $r->employee->user_id) {
                Notification::create(['company_id' => Auth::user()->company_id, 'user_id' => $r->employee->user_id, 'type' => 'workforce.leave', 'title' => 'Leave request updated / Kërkesa për pushim', 'message' => 'Your leave request is '.$status.'.', 'data' => ['url' => '/my-workforce', 'record_id' => $r->id]]);
            }

            return $r->fresh();
        });
    }

    public function leaveBalance(Employee $e, ?int $year = null): array
    {
        $year ??= (int) now()->format('Y');
        $base = $e->records()->where('starts_on', '>=', "$year-01-01")->where('starts_on', '<=', "$year-12-31");
        $entitled = (clone $base)->where('kind', 'entitlement')->where('status', 'approved')->sum('units');
        $leave = (clone $base)->where('kind', 'leave')->where('details->leave_type', 'annual');
        $used = (clone $leave)->where('status', 'taken')->sum('units');
        $planned = (clone $leave)->where('status', 'approved')->sum('units');

        return ['year' => $year, 'configured' => (clone $base)->where('kind', 'entitlement')->where('status', 'approved')->exists(), 'entitled' => Money::normalize($entitled), 'used' => Money::normalize($used), 'planned' => Money::normalize($planned), 'remaining' => Money::subtract(Money::subtract($entitled, $used), $planned)];
    }

    public function assertUnused(int $employeeId, string $from, string $to): void
    {
        if (PayrollLine::where('employee_id', $employeeId)->whereHas('run', fn ($q) => $q->whereIn('status', ['posted', 'paid', 'closed'])->whereHas('period', fn ($q) => $q->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)))->exists()) {
            $this->fail('This employee has posted payroll for the affected dates. Reverse/correct the payroll before changing its source inputs.');
        }
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['workforce' => $message]);
    }
}
