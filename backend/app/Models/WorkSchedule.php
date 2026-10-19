<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkSchedule extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['week' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Create a new schedule version to preserve historic working terms.'));
        static::deleting(fn () => throw new \LogicException('Referenced schedules remain in history.'));
    }
}
