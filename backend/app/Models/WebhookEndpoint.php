<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'endpoint_url', 'secret', 'enabled',
        'subscribed_event_types', 'last_success_at', 'last_failure_at', 'last_error',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted', 'enabled' => 'boolean',
            'subscribed_event_types' => 'array', 'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany { return $this->hasMany(WebhookDelivery::class); }
}
