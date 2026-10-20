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
        'period_from', 'period_to', 'closing_balance', 'imported_by', 'opening_balance', 'reconciliation_status', 'reconciled_at', 'reconciled_by', 'reconciliation_proof', 'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array', 'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d', 'closing_balance' => 'decimal:2', 'opening_balance' => 'decimal:2', 'reconciled_at' => 'datetime', 'reconciliation_proof' => 'array', 'reopened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function ($m) {
            if ($m->getOriginal('reconciliation_status') === 'completed' && ($m->reconciliation_status !== 'open' || array_diff(array_keys($m->getDirty()), ['reconciliation_status', 'reopened_by', 'reopened_at', 'reopen_reason', 'updated_at']) || ! $m->reopened_by || ! $m->reopened_at || ! filled($m->reopen_reason))) {
                throw new \LogicException('Completed reconciliation is immutable. Reopen it with permission and a reason first.');
            }
        });
        static::deleting(function ($m) {
            if ($m->reconciliation_status === 'completed') {
                throw new \LogicException('Completed reconciliation history cannot be deleted.');
            }
        });
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
