<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->string('workforce_scope')->nullable()->index();
        });
        Schema::create('work_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('name');
            $t->json('week');
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('employee_number');
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->string('department')->nullable();
            $t->string('position')->nullable();
            $t->foreignId('manager_id')->nullable()->constrained('employees');
            $t->string('status')->default('draft');
            $t->date('start_date');
            $t->date('termination_date')->nullable();
            $t->string('payment_method')->default('bank');
            $t->text('bank_account')->nullable();
            $t->text('personal_number')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'employee_number']);
            $t->unique(['company_id', 'user_id']);
            $t->index(['company_id', 'status']);
        });
        Schema::create('employment_terms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('supersedes_id')->nullable()->constrained('employment_terms');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('contract_type');
            $t->string('position');
            $t->string('department')->nullable();
            $t->date('probation_until')->nullable();
            $t->text('notes')->nullable();
            $t->string('pay_basis');
            $t->decimal('amount', 18, 2);
            $t->string('currency', 3)->default('EUR');
            $t->foreignId('work_schedule_id')->nullable()->constrained();
            $t->string('proration_policy')->default('calendar_days');
            $t->string('employer_classification');
            $t->string('pension_treatment');
            $t->decimal('employee_pension_rate', 6, 3);
            $t->decimal('employer_pension_rate', 6, 3);
            $t->text('pension_basis')->nullable();
            $t->text('reason');
            $t->foreignId('approved_by')->constrained('users');
            $t->timestamp('approved_at');
            $t->timestamps();
            $t->index(['company_id', 'employee_id', 'effective_from']);
        });
        Schema::create('workforce_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->string('kind');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->decimal('units', 12, 4)->default(0);
            $t->decimal('amount', 18, 2)->default(0);
            $t->string('status')->default('requested');
            $t->text('reason');
            $t->json('details')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'kind', 'status', 'starts_on']);
            $t->index(['employee_id', 'starts_on', 'ends_on']);
        });
        Schema::create('payroll_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('version');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->json('configuration');
            $t->json('sources');
            $t->foreignId('approved_by')->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'version']);
            $t->index(['company_id', 'effective_from']);
        });
        Schema::create('payroll_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('name');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->date('pay_date');
            $t->string('status')->default('open');
            $t->foreignId('closed_by')->nullable()->constrained('users');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'starts_on', 'ends_on']);
        });
        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('payroll_period_id')->constrained();
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('correction_of_id')->nullable()->constrained('payroll_runs');
            $t->string('status')->default('draft');
            $t->string('fingerprint', 64)->nullable();
            $t->json('totals')->nullable();
            $t->json('warnings')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users');
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('journal_entry_id')->nullable()->constrained();
            $t->foreignId('reversal_journal_id')->nullable()->constrained('journal_entries');
            $t->foreignId('reversed_by')->nullable()->constrained('users');
            $t->timestamp('reversed_at')->nullable();
            $t->text('reversal_reason')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'payroll_period_id', 'revision']);
            $t->index(['company_id', 'status']);
        });
        Schema::create('payroll_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('payroll_run_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->foreignId('payroll_rule_id')->constrained('payroll_rules');
            $t->json('snapshot');
            foreach (['gross', 'employee_pension', 'employer_pension', 'taxable', 'tax', 'deductions', 'advance_repayment', 'net', 'employer_cost'] as $column) {
                $t->decimal($column, 18, 2);
            }
            $t->timestamps();
            $t->unique(['payroll_run_id', 'employee_id']);
            $t->index(['company_id', 'employee_id']);
        });
        Schema::create('employee_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('employee_id')->constrained();
            $t->date('paid_on');
            $t->decimal('amount', 18, 2);
            $t->text('reason');
            $t->string('idempotency_key');
            $t->foreignId('financial_account_transaction_id')->constrained();
            $t->foreignId('journal_entry_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key']);
        });
        Schema::create('payroll_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('payroll_run_id')->constrained();
            $t->foreignId('payroll_line_id')->nullable()->constrained();
            $t->string('kind');
            $t->date('paid_on');
            $t->decimal('amount', 18, 2);
            $t->string('idempotency_key');
            $t->foreignId('financial_account_transaction_id')->constrained();
            $t->foreignId('journal_entry_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        if (DB::table('payroll_runs')->whereIn('status', ['posted', 'paid', 'closed', 'reversed'])->exists()) {
            throw new RuntimeException('Posted payroll history prevents destructive workforce rollback.');
        }
        // Only unused development schemas may be rolled back; production upgrades are additive.
        foreach (['payroll_payments', 'employee_advances', 'payroll_lines', 'payroll_runs', 'payroll_periods', 'payroll_rules', 'workforce_records', 'employment_terms', 'employees', 'work_schedules'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('workforce_scope'));
    }
};
