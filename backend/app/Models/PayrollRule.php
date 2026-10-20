<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollRule extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'configuration' => 'array', 'sources' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payroll rules are versioned and immutable. Create a later effective version.'));
        static::deleting(fn () => throw new \LogicException('Payroll rules cannot be deleted.'));
    }
}
