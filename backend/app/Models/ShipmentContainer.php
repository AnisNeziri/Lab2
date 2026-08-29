<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentContainer extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'shipment_id', 'container_number', 'seal_number', 'container_type',
        'gross_weight_kg', 'volume_m3', 'notes',
    ];

    protected function casts(): array
    {
        return ['gross_weight_kg' => 'decimal:3', 'volume_m3' => 'decimal:3'];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function items(): HasMany { return $this->hasMany(ShipmentItem::class); }
}
