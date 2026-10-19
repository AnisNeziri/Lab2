<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'pay_date' => 'date', 'closed_at' => 'datetime'];
    }

    public function runs()
    {
        return $this->hasMany(PayrollRun::class);
    }

    protected static function booted(): void
    {
        static::updating(function ($m) {
            if (array_diff(array_keys($m->getDirty()), ['status', 'closed_by', 'closed_at', 'updated_at'])) {
                throw new \LogicException('Payroll period dates are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Payroll period history cannot be deleted.'));
    }
}
