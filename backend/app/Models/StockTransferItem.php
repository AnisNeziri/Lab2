<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'product_id', 'quantity', 'picked_quantity', 'received_quantity',
        'damaged_quantity', 'unit_snapshot', 'notes',
        'trace_allocations', 'picked_by', 'picked_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'picked_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3',
            'damaged_quantity' => 'decimal:3', 'trace_allocations' => 'array',
            'picked_at' => 'datetime',
        ];
    }

    public function transfer(): BelongsTo { return $this->belongsTo(StockTransfer::class, 'stock_transfer_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
