<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'transfer_number', 'source_warehouse_id', 'destination_warehouse_id',
        'source_location_id', 'destination_location_id', 'status', 'notes', 'dispatched_at',
        'received_at', 'cancelled_at', 'created_by', 'dispatched_by', 'received_by',
        'cancelled_by', 'cancellation_reason', 'dispatch_idempotency_key', 'receive_idempotency_key',
    ];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function sourceWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'source_warehouse_id'); }
    public function destinationWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class, 'destination_warehouse_id'); }
    public function sourceLocation(): BelongsTo { return $this->belongsTo(WarehouseLocation::class, 'source_location_id'); }
    public function destinationLocation(): BelongsTo { return $this->belongsTo(WarehouseLocation::class, 'destination_location_id'); }
    public function items(): HasMany { return $this->hasMany(StockTransferItem::class); }
}
