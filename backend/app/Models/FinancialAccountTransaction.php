<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccountTransaction extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'financial_account_id', 'type', 'amount', 'currency',
        'transaction_date', 'source_type', 'source_id', 'related_transaction_id',
        'counterparty', 'reference_number', 'description', 'status', 'idempotency_key',
        'created_by', 'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'transaction_date' => 'date:Y-m-d',
            'source_id' => 'integer', 'reversed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_transaction_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by')->withTrashed();
    }
}
