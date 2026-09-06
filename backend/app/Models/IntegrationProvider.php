<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationProvider extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'category', 'provider_key', 'display_name', 'enabled',
        'configuration', 'credentials', 'credential_reference', 'health_state',
        'last_success_at', 'last_failure_at', 'last_data_received_at', 'last_error',
    ];

    protected $hidden = ['configuration', 'credentials', 'credential_reference'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'configuration' => 'encrypted:array',
            'credentials' => 'encrypted:array',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'last_data_received_at' => 'datetime',
        ];
    }

    public function operations(): HasMany
    {
        return $this->hasMany(IntegrationOperationLog::class);
    }
}
