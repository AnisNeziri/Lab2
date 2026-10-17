<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class OnboardingEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Onboarding audit evidence is append-only.'));
        static::deleting(fn () => throw new \LogicException('Onboarding audit evidence is append-only.'));
    }
}
