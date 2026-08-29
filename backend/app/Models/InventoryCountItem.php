<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCountItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'inventory_count_session_id', 'company_id', 'product_id', 'location_id',
        'location_key', 'inventory_lot_id', 'lot_key', 'stock_state',
        'expected_quantity', 'counted_quantity', 'variance_quantity', 'count_round',
        'requires_recount', 'last_counted_by', 'last_counted_at', 'adjustment_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'decimal:3', 'counted_quantity' => 'decimal:3',
            'variance_quantity' => 'decimal:3', 'count_round' => 'integer',
            'requires_recount' => 'boolean', 'last_counted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->location_key = (int) ($item->location_id ?? 0);
            $item->lot_key = (int) ($item->inventory_lot_id ?? 0);
        });
    }

    public function session(): BelongsTo { return $this->belongsTo(InventoryCountSession::class, 'inventory_count_session_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function location(): BelongsTo { return $this->belongsTo(WarehouseLocation::class); }
    public function lot(): BelongsTo { return $this->belongsTo(InventoryLot::class, 'inventory_lot_id'); }
    public function adjustmentMovement(): BelongsTo { return $this->belongsTo(StockMovement::class, 'adjustment_movement_id'); }
    public function entries(): HasMany { return $this->hasMany(InventoryCountEntry::class); }
}
