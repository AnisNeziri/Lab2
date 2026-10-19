<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FiscalAttempt extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_evidence', 'response_evidence'];

    protected function casts(): array
    {
        return ['request_evidence' => 'encrypted:array', 'response_evidence' => 'encrypted:array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $attempt) {
            if ($attempt->getOriginal('finished_at') || $attempt->isDirty(['company_id', 'fiscal_receipt_id', 'number', 'request_evidence', 'started_at', 'user_id', 'credential_fingerprint', 'signature_format'])) {
                throw new \LogicException('Fiscal attempts are append-only and may be completed once.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Fiscal attempts cannot be deleted.'));
    }
}
