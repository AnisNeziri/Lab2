<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationOperationLog extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'integration_provider_id', 'operation', 'status',
        'request_reference', 'started_at', 'completed_at', 'error', 'attempts',
        'next_retry_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'completed_at' => 'datetime',
            'next_retry_at' => 'datetime', 'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'integration_provider_id');
    }
}
