<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class OnboardingRow extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw' => 'array', 'normalized' => 'array', 'overrides' => 'array', 'resolution' => 'array', 'candidates' => 'array', 'errors' => 'array', 'warnings' => 'array', 'posting_result' => 'array', 'posted_at' => 'datetime'];
    }

    public function batch()
    {
        return $this->belongsTo(OnboardingBatch::class, 'onboarding_batch_id');
    }

    public function session()
    {
        return $this->belongsTo(OnboardingSession::class, 'onboarding_session_id');
    }

    public function scopeActive($query)
    {
        return $query->whereHas('batch', fn ($q) => $q->where('is_active', true));
    }
}
