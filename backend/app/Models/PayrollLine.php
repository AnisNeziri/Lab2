<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollLine extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['private_snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'private_snapshot' => 'encrypted:array', ...array_fill_keys(['gross', 'employee_pension', 'employer_pension', 'taxable', 'tax', 'deductions', 'advance_repayment', 'net', 'employer_cost'], 'decimal:2')];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payroll line snapshots are immutable; recalculate an unposted run.'));
        static::deleting(function ($m) {
            if (! in_array($m->run->status, ['draft', 'calculated', 'needs_review'], true)) {
                throw new \LogicException('Reviewed and posted payroll lines cannot be deleted.');
            }
        });
    }
}
