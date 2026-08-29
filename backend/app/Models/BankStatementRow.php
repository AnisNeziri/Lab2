<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementRow extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'bank_statement_id', 'transaction_date', 'description',
        'reference_number', 'amount', 'statement_balance', 'status',
        'matched_transaction_id', 'match_score', 'ignore_reason', 'reviewed_by',
        'reviewed_at', 'row_hash', 'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d', 'amount' => 'decimal:2',
            'statement_balance' => 'decimal:2', 'match_score' => 'decimal:2',
            'reviewed_at' => 'datetime', 'raw_data' => 'array',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialAccountTransaction::class, 'matched_transaction_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BankReconciliationEvent::class);
    }
}
