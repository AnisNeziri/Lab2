<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentContainer extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'shipment_id', 'container_number', 'seal_number', 'container_type',
        'booking_reference', 'bill_of_lading', 'forwarder', 'vessel_name', 'voyage',
        'origin_port', 'destination_port', 'etd', 'eta', 'actual_departure',
        'actual_arrival', 'status', 'gross_weight_kg', 'volume_m3', 'capacity_cbm',
        'capacity_weight_kg', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'gross_weight_kg' => 'decimal:3', 'volume_m3' => 'decimal:3',
            'capacity_cbm' => 'decimal:3', 'capacity_weight_kg' => 'decimal:3',
            'etd' => 'datetime', 'eta' => 'datetime', 'actual_departure' => 'datetime',
            'actual_arrival' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function items(): HasMany { return $this->hasMany(ShipmentItem::class); }
    public function purchaseOrders(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseOrder::class, 'shipment_container_purchase_orders')
            ->withPivot('company_id')
            ->withTimestamps();
    }
    public function milestones(): HasMany { return $this->hasMany(ShipmentMilestone::class); }
}
