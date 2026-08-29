<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'financial_account_id', 'file_name', 'file_hash', 'column_mapping',
        'period_from', 'period_to', 'closing_balance', 'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array', 'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d', 'closing_balance' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(BankStatementRow::class);
    }
}
