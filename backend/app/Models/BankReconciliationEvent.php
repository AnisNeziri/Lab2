<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'bank_statement_row_id', 'financial_account_transaction_id',
        'action', 'reason', 'old_values', 'new_values', 'user_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(BankStatementRow::class, 'bank_statement_row_id');
    }
}
