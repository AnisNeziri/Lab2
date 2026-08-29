<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'expense_id', 'purchase_order_item_id', 'goods_receipt_item_id',
        'product_id', 'description', 'quantity', 'unit', 'unit_price', 'vat_rate',
        'net_amount', 'vat_amount', 'total_amount', 'variance',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'vat_rate' => 'decimal:3',
            'net_amount' => 'decimal:2', 'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2', 'variance' => 'array',
        ];
    }

    public function expense(): BelongsTo { return $this->belongsTo(Expense::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function purchaseOrderItem(): BelongsTo { return $this->belongsTo(PurchaseOrderItem::class); }
    public function goodsReceiptItem(): BelongsTo { return $this->belongsTo(GoodsReceiptItem::class); }
}
