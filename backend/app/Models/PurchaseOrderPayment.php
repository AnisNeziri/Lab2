<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderPayment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'purchase_order_id', 'expense_id', 'user_id', 'amount', 'currency', 'exchange_rate', 'amount_eur', 'payment_date', 'payment_method', 'reference_number', 'idempotency_key', 'note', 'paid_at', 'financial_account_id', 'financial_account_transaction_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'amount_eur' => 'decimal:2', 'payment_date' => 'date:Y-m-d', 'paid_at' => 'datetime'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierInvoicePaymentAllocation::class);
    }
}
