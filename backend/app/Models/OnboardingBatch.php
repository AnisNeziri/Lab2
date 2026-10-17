<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class OnboardingBatch extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['source_base64'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'options' => 'array', 'is_active' => 'boolean'];
    }

    public function rows()
    {
        return $this->hasMany(OnboardingRow::class);
    }

    public function session()
    {
        return $this->belongsTo(OnboardingSession::class, 'onboarding_session_id');
    }
}
