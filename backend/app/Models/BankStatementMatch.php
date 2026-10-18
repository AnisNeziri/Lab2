<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankStatementMatch extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['signed_amount' => 'decimal:2', 'matched_at' => 'datetime', 'released_at' => 'datetime', 'legacy_match' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function ($m) {
            if ((int) $m->active_transaction_id !== (int) $m->financial_account_transaction_id) {
                throw new \LogicException('A live match must own its original transaction.');
            }
            $row = BankStatementRow::query()->findOrFail($m->bank_statement_row_id);
            $transaction = FinancialAccountTransaction::query()->findOrFail($m->financial_account_transaction_id);
            if ((int) $row->statement->financial_account_id !== (int) $transaction->financial_account_id) {
                throw new \LogicException('Bank match sources must belong to the same account and company.');
            }
        });
        static::updating(function ($m) {
            if ($m->getOriginal('released_at') || $m->active_transaction_id !== null || ! $m->released_at || ! $m->released_by || ! filled($m->release_reason)
                || array_diff(array_keys($m->getDirty()), ['active_transaction_id', 'released_at', 'released_by', 'release_reason'])) {
                throw new \LogicException('Match history is immutable. Release ownership with a reason.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Match history cannot be deleted.'));
    }

    public function transaction()
    {
        return $this->belongsTo(FinancialAccountTransaction::class, 'financial_account_transaction_id');
    }

    public function row()
    {
        return $this->belongsTo(BankStatementRow::class, 'bank_statement_row_id');
    }
}
