<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandedCost extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'goods_receipt_id', 'purchase_order_id', 'shipment_id',
        'reference_number', 'cost_type', 'description', 'amount', 'currency',
        'exchange_rate_to_base', 'base_currency_amount', 'allocation_method',
        'status', 'idempotency_key', 'notes', 'created_by', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'exchange_rate_to_base' => 'decimal:8',
            'base_currency_amount' => 'decimal:6',
            'posted_at' => 'datetime',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class);
    }

    public function accountingEntries(): HasMany
    {
        return $this->hasMany(LandedCostAccountingEntry::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
