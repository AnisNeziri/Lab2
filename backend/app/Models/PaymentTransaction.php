<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTransaction extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'invoice_id',
        'user_id',
        'amount',
        'status',
        'payment_method',
        'transaction_ref',
        'reference_number',
        'idempotency_key',
        'note',
        'payment_date',
        'paid_at',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'financial_account_id',
        'financial_account_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'invoice_id' => 'integer',
            'user_id' => 'integer',
            'reversed_by' => 'integer',
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function debtTransactions(): HasMany
    {
        return $this->hasMany(CustomerDebtTransaction::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
