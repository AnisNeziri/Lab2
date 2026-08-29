<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTraceBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'inventory_lot_id', 'warehouse_id', 'location_id',
        'location_key', 'stock_state', 'quantity',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'location_key' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $balance): void {
            $balance->location_key = (int) ($balance->location_id ?? 0);
        });
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }
}
