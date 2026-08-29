<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LandedCostAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'landed_cost_id', 'goods_receipt_item_id', 'product_id',
        'allocation_basis', 'allocated_amount', 'allocated_unit_cost',
        'base_purchase_unit_cost_snapshot', 'final_unit_cost',
        'weighted_average_cost_before', 'weighted_average_cost_after',
        'inventory_value_before', 'inventory_value_after',
    ];

    protected function casts(): array
    {
        return [
            'allocation_basis' => 'decimal:8',
            'allocated_amount' => 'decimal:6',
            'allocated_unit_cost' => 'decimal:6',
            'base_purchase_unit_cost_snapshot' => 'decimal:6',
            'final_unit_cost' => 'decimal:6',
            'weighted_average_cost_before' => 'decimal:6',
            'weighted_average_cost_after' => 'decimal:6',
            'inventory_value_before' => 'decimal:6',
            'inventory_value_after' => 'decimal:6',
        ];
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function accountingEntry(): HasOne
    {
        return $this->hasOne(LandedCostAccountingEntry::class);
    }
}
