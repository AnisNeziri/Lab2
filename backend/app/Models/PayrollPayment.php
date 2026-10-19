<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollPayment extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Posted payroll payments cannot be edited.'));
        static::deleting(fn () => throw new \LogicException('Posted payroll payments cannot be deleted.'));
    }
}
