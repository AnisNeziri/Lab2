<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmploymentTerm extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'probation_until' => 'date', 'approved_at' => 'datetime', 'amount' => 'decimal:2', 'employee_pension_rate' => 'decimal:3', 'employer_pension_rate' => 'decimal:3'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule()
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Approved employment terms are immutable. Append an effective-dated revision.'));
        static::deleting(fn () => throw new \LogicException('Employment terms cannot be deleted.'));
    }
}
