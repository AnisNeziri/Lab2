<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'webhook_endpoint_id', 'business_event_id', 'status',
        'attempts', 'response_status', 'last_error', 'last_attempt_at',
        'delivered_at', 'next_retry_at',
    ];

    protected function casts(): array
    {
        return ['last_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'next_retry_at' => 'datetime'];
    }

    public function endpoint(): BelongsTo { return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id'); }
    public function event(): BelongsTo { return $this->belongsTo(BusinessEvent::class, 'business_event_id'); }
}
