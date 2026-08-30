<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCostAccountingEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'landed_cost_id', 'landed_cost_allocation_id',
        'goods_receipt_item_id', 'product_id', 'receipt_quantity',
        'remaining_quantity_at_post', 'quantity_recognized_in_cogs',
        'inventory_adjustment_amount', 'cogs_adjustment_amount',
        'calculation_method', 'calculation_evidence', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'receipt_quantity' => 'decimal:3',
            'remaining_quantity_at_post' => 'decimal:3',
            'quantity_recognized_in_cogs' => 'decimal:3',
            'inventory_adjustment_amount' => 'decimal:6',
            'cogs_adjustment_amount' => 'decimal:6',
            'calculation_evidence' => 'array',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(LandedCostAllocation::class, 'landed_cost_allocation_id');
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }
}
