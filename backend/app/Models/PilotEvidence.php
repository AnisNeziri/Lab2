<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PilotEvidence extends Model
{
    use BelongsToCompany;

    protected $table = 'pilot_evidence';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'observed_on' => 'date', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function ($row) {
            if ($row->getOriginal('reviewed_at') || array_diff(array_keys($row->getDirty()), ['reviewed_by', 'reviewed_at', 'updated_at'])) {
                throw new \LogicException('Evidence is append-only; record a correction as a new observation.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Evidence cannot be deleted.'));
    }
}
