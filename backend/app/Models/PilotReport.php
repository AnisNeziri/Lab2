<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PilotReport extends Model
{
    use BelongsToCompany;

    protected $table = 'pilot_reports';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['report' => 'array', 'period_start' => 'date', 'period_end' => 'date', 'captured_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Pilot report snapshots are append-only.'));
        static::deleting(fn () => throw new \LogicException('Pilot report snapshots are append-only.'));
    }
}
