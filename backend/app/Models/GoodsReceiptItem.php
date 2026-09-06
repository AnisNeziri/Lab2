<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceiptItem extends Model
{
    protected $fillable = [
        'goods_receipt_id', 'purchase_order_item_id', 'product_id', 'ordered_unit',
        'accepted_quantity', 'damaged_quantity', 'rejected_quantity', 'accepted_base_quantity',
        'damaged_base_quantity', 'inventory_unit', 'conversion_mode', 'conversion_factor',
        'purchase_unit_price', 'purchase_currency', 'purchase_exchange_rate',
        'base_purchase_cost', 'base_purchase_unit_cost', 'landed_cost_allocated',
        'landed_cost_unit', 'final_inventory_unit_cost', 'weighted_average_cost_before',
        'weighted_average_cost_after', 'notes',
        'quality_inspection_mode_snapshot', 'quality_inspection_id',
    ];

    protected function casts(): array
    {
        return [
            'accepted_quantity' => 'decimal:3', 'damaged_quantity' => 'decimal:3',
            'rejected_quantity' => 'decimal:3', 'accepted_base_quantity' => 'decimal:3',
            'damaged_base_quantity' => 'decimal:3', 'conversion_factor' => 'decimal:6',
            'purchase_unit_price' => 'decimal:6', 'purchase_exchange_rate' => 'decimal:8',
            'base_purchase_cost' => 'decimal:6', 'base_purchase_unit_cost' => 'decimal:6',
            'landed_cost_allocated' => 'decimal:6', 'landed_cost_unit' => 'decimal:6',
            'final_inventory_unit_cost' => 'decimal:6',
            'weighted_average_cost_before' => 'decimal:6', 'weighted_average_cost_after' => 'decimal:6',
        ];
    }

    public function receipt(): BelongsTo { return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id'); }
    public function purchaseOrderItem(): BelongsTo { return $this->belongsTo(PurchaseOrderItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function landedCostAllocations(): HasMany { return $this->hasMany(LandedCostAllocation::class); }
    public function qualityInspection(): BelongsTo { return $this->belongsTo(QualityInspection::class); }
}
