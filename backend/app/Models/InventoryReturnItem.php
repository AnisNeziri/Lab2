<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReturnItem extends Model
{
    protected $fillable = [
        'inventory_return_id', 'product_id', 'daily_sale_item_id', 'goods_receipt_item_id',
        'warehouse_id', 'location_id', 'quantity', 'processed_quantity', 'unit',
        'condition', 'stock_state', 'unit_cost', 'line_value', 'trace_data', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'processed_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:6', 'line_value' => 'decimal:2', 'trace_data' => 'array',
        ];
    }

    public function inventoryReturn(): BelongsTo { return $this->belongsTo(InventoryReturn::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function dailySaleItem(): BelongsTo { return $this->belongsTo(DailySaleItem::class); }
    public function goodsReceiptItem(): BelongsTo { return $this->belongsTo(GoodsReceiptItem::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function location(): BelongsTo { return $this->belongsTo(WarehouseLocation::class, 'location_id'); }
}
