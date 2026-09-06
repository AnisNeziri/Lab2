<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxReferenceRate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'integration_provider_id', 'base_currency', 'quote_currency',
        'rate_date', 'rate', 'source',
    ];

    protected function casts(): array
    {
        return ['rate_date' => 'date:Y-m-d', 'rate' => 'decimal:10'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'integration_provider_id');
    }
}
