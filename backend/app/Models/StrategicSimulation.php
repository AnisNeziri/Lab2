<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StrategicSimulation extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'baseline' => 'array', 'result' => 'array', 'response_plan' => 'array', 'audit' => 'array', 'baseline_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            foreach (['version', 'engine_version', 'definition', 'baseline', 'baseline_at', 'created_by', 'parent_id', 'result', 'name', 'description', 'response_plan'] as $field) {
                if ($r->getRawOriginal($field) !== null && $r->isDirty($field)) {
                    throw new \LogicException('Simulation evidence is immutable. Create a derived or current-data run.');
                }
            }
        });
    }
}
