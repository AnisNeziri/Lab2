<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class FiscalReceipt extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['request_evidence'];

    protected function casts(): array
    {
        return ['request_evidence' => 'encrypted:array', 'policy_snapshot' => 'array', 'configuration_snapshot' => 'array', 'submitted_at' => 'datetime', 'fiscalized_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt) {
            if ($receipt->getOriginal('status') === 'fiscalized' || $receipt->isDirty(['company_id', 'invoice_id', 'configuration_id', 'environment', 'coupon_no', 'verification_no', 'source_fingerprint', 'request_fingerprint', 'request_evidence', 'policy_snapshot', 'configuration_snapshot', 'credential_fingerprint', 'prepared_by'])) {
                throw ValidationException::withMessages(['fiscalization' => 'Prepared fiscal evidence is immutable.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['fiscalization' => 'Fiscal evidence cannot be deleted.']));
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function attempts()
    {
        return $this->hasMany(FiscalAttempt::class);
    }
}
