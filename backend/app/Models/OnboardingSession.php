<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class OnboardingSession extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected_totals' => 'array', 'review' => 'array', 'verification' => 'array', 'restore_verification' => 'array', 'separate_reviewer_required' => 'boolean',
            'cutover_date' => 'date', 'revision' => 'integer', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'posting_started_at' => 'datetime', 'posted_at' => 'datetime', 'verified_at' => 'datetime', 'locked_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function batches()
    {
        return $this->hasMany(OnboardingBatch::class);
    }

    public function rows()
    {
        return $this->hasMany(OnboardingRow::class);
    }

    public function events()
    {
        return $this->hasMany(OnboardingEvent::class);
    }

    public function backups()
    {
        return $this->hasMany(OnboardingBackup::class);
    }
}
