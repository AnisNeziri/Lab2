<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'tracking_number',
        'bill_of_lading',
        'commercial_invoice_number',
        'incoterm',
        'transport_mode',
        'carrier',
        'purchase_order_id',
        'warehouse_id',
        'supplier_id',
        'vessel_name',
        'mmsi',
        'imo',
        'call_sign',
        'vessel_type',
        'flag_country',
        'year_built',
        'vessel_details',
        'details_provider',
        'details_updated_at',
        'tracking_reference',
        'origin_port',
        'transshipment_port',
        'origin_lat',
        'origin_lng',
        'destination_port',
        'destination_lat',
        'destination_lng',
        'status',
        'departed_at',
        'eta',
        'arrival_date',
        'previous_eta',
        'current_lat',
        'current_lng',
        'last_location_label',
        'distance_to_port_km',
        'tracking_mode',
        'tracking_provider',
        'is_saved',
        'is_favorite',
        'archived_at',
        'risk_level',
        'notification_state',
        'route_waypoints',
        'last_refreshed_at',
        'position_updated_at',
        'speed_knots',
        'course',
        'heading',
        'navigation_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'origin_lat' => 'float',
            'origin_lng' => 'float',
            'destination_lat' => 'float',
            'destination_lng' => 'float',
            'current_lat' => 'float',
            'current_lng' => 'float',
            'departed_at' => 'datetime',
            'eta' => 'datetime',
            'arrival_date' => 'datetime',
            'previous_eta' => 'datetime',
            'archived_at' => 'datetime',
            'distance_to_port_km' => 'integer',
            'is_saved' => 'boolean',
            'is_favorite' => 'boolean',
            'last_refreshed_at' => 'datetime',
            'position_updated_at' => 'datetime',
            'speed_knots' => 'float',
            'course' => 'float',
            'heading' => 'float',
            'navigation_status' => 'integer',
            'notification_state' => 'array',
            'route_waypoints' => 'array',
            'vessel_details' => 'array',
            'details_updated_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ShipmentHistory::class)->latest('created_at');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(ShipmentContainer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShipmentDocument::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeFavorites($query)
    {
        return $query->where('is_favorite', true);
    }

    public function scopeSaved($query)
    {
        return $query->where('is_saved', true);
    }
}
