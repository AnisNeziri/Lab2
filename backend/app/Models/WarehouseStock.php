<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStock extends Model
{
    use BelongsToCompany;

    protected $table = 'warehouse_stock';

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'product_id',
        'location_id',
        'location_key',
        'quantity',
        'available_quantity',
        'reserved_quantity',
        'damaged_quantity',
        'quarantine_quantity',
        'blocked_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'available_quantity' => 'decimal:3',
            'reserved_quantity' => 'decimal:3',
            'damaged_quantity' => 'decimal:3',
            'quarantine_quantity' => 'decimal:3',
            'blocked_quantity' => 'decimal:3',
            'location_key' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $balance): void {
            $balance->location_key = (int) ($balance->location_id ?? 0);
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }
}
