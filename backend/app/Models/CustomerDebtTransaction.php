<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerDebtTransaction extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'customer_id', 'user_id', 'daily_sale_id', 'invoice_id', 'payment_transaction_id', 'reversed_transaction_id', 'type', 'source', 'amount', 'balance_before', 'balance_after', 'credit_before', 'credit_after', 'transaction_date', 'due_date', 'payment_method', 'reference_number', 'idempotency_key', 'note', 'metadata', 'financial_account_id', 'financial_account_transaction_id'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'customer_id' => 'integer',
            'user_id' => 'integer',
            'daily_sale_id' => 'integer',
            'invoice_id' => 'integer',
            'payment_transaction_id' => 'integer',
            'reversed_transaction_id' => 'integer',
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'credit_before' => 'decimal:2',
            'credit_after' => 'decimal:2',
            'transaction_date' => 'date',
            'due_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_transaction_id');
    }
}
