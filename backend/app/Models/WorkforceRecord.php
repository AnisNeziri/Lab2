<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkforceRecord extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'approved_at' => 'datetime', 'details' => 'array', 'amount' => 'decimal:2', 'units' => 'decimal:4'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function booted(): void
    {
        static::updating(function ($m) {
            if (array_diff(array_keys($m->getDirty()), ['status', 'approved_by', 'approved_at', 'updated_at'])) {
                throw new \LogicException('Cancel and replace a workforce record to correct its inputs.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Workforce approval history cannot be deleted.'));
    }
}
