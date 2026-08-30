<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Money;

class PurchaseOrderPayment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'purchase_order_id', 'expense_id', 'user_id', 'amount', 'currency', 'exchange_rate', 'amount_eur', 'status', 'payment_date', 'payment_method', 'reference_number', 'idempotency_key', 'note', 'paid_at', 'reversed_at', 'reversed_by', 'reversal_reason', 'financial_account_id', 'financial_account_transaction_id'];

    protected $appends = ['allocated_amount', 'unallocated_amount', 'allocation_status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'amount_eur' => 'decimal:2', 'payment_date' => 'date:Y-m-d', 'paid_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierInvoicePaymentAllocation::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by')->withTrashed();
    }

    public function getAllocatedAmountAttribute(): string
    {
        $amount = $this->relationLoaded('allocations')
            ? $this->allocations->sum('amount')
            : $this->allocations()->sum('amount');

        return Money::normalize($amount);
    }

    public function getUnallocatedAmountAttribute(): string
    {
        if ($this->status === 'reversed') {
            return '0.00';
        }

        return Money::maximum('0.00', Money::subtract($this->amount, $this->allocated_amount));
    }

    public function getAllocationStatusAttribute(): string
    {
        if ($this->status === 'reversed') {
            return 'reversed';
        }
        if (Money::compare($this->unallocated_amount, $this->amount) === 0) {
            return 'unallocated_advance';
        }

        return Money::compare($this->unallocated_amount, '0.00') === 0 ? 'fully_allocated' : 'partially_allocated';
    }
}
