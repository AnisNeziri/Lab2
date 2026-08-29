<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'product_id', 'product_supplier_id', 'description', 'unit', 'inventory_unit', 'conversion_mode', 'conversion_factor', 'quantity', 'base_quantity', 'received_quantity', 'received_base_quantity', 'unit_price', 'line_total'];

    protected $appends = ['remaining_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'base_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'received_base_quantity' => 'decimal:3', 'conversion_factor' => 'decimal:6', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function productSupplier(): BelongsTo
    {
        return $this->belongsTo(ProductSupplier::class);
    }

    public function getRemainingQuantityAttribute(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->received_quantity, 3));
    }

    public function receiptItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
