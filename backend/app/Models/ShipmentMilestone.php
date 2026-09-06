<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentMilestone extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'shipment_id', 'shipment_container_id', 'scope_key',
        'milestone_type', 'status', 'planned_at', 'estimated_at', 'actual_at',
        'source', 'notes', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['planned_at' => 'datetime', 'estimated_at' => 'datetime', 'actual_at' => 'datetime'];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function container(): BelongsTo { return $this->belongsTo(ShipmentContainer::class, 'shipment_container_id'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by')->withTrashed(); }
}
