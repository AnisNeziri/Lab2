<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryReturn extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'return_number', 'type', 'status', 'customer_id', 'supplier_id',
        'daily_sale_id', 'purchase_order_id', 'goods_receipt_id', 'financial_resolution',
        'financial_amount', 'currency', 'payment_method', 'reference_number', 'reason',
        'notes', 'idempotency_key', 'request_fingerprint', 'financial_account_id',
        'financial_account_transaction_id', 'customer_debt_transaction_id', 'supplier_credit_expense_id', 'created_by',
        'submitted_by', 'approved_by', 'completed_by', 'cancelled_by', 'submitted_at',
        'approved_at', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'financial_amount' => 'decimal:2', 'submitted_at' => 'datetime',
            'approved_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany { return $this->hasMany(InventoryReturnItem::class); }
    public function events(): HasMany { return $this->hasMany(InventoryReturnEvent::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class)->withTrashed(); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class)->withTrashed(); }
    public function dailySale(): BelongsTo { return $this->belongsTo(DailySale::class)->withTrashed(); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class)->withTrashed(); }
    public function goodsReceipt(): BelongsTo { return $this->belongsTo(GoodsReceipt::class); }
    public function financialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class); }
    public function supplierCreditExpense(): BelongsTo { return $this->belongsTo(Expense::class, 'supplier_credit_expense_id'); }
}
