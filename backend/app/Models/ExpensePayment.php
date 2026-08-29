<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePayment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'expense_id', 'amount', 'amount_eur', 'exchange_rate', 'exchange_rate_date',
        'exchange_rate_source', 'status', 'payment_date',
        'payment_method', 'reference_number', 'idempotency_key', 'note', 'created_by',
        'reversed_at', 'reversed_by', 'reversal_reason', 'financial_account_id',
        'financial_account_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'expense_id' => 'integer', 'created_by' => 'integer',
            'amount' => 'decimal:2', 'amount_eur' => 'decimal:2', 'exchange_rate' => 'decimal:6',
            'exchange_rate_date' => 'date', 'payment_date' => 'date',
            'reversed_at' => 'datetime', 'reversed_by' => 'integer',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
