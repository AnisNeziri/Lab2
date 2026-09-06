<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalException extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'exception_key', 'exception_type', 'severity', 'entity_type',
        'entity_id', 'shipment_id', 'shipment_container_id', 'purchase_order_id',
        'status', 'detected_at', 'resolved_at', 'resolved_by', 'description',
        'relevant_values', 'next_action',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime', 'resolved_at' => 'datetime',
            'relevant_values' => 'array', 'next_action' => 'array',
        ];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function container(): BelongsTo { return $this->belongsTo(ShipmentContainer::class, 'shipment_container_id'); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class)->withTrashed(); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by')->withTrashed(); }
}
