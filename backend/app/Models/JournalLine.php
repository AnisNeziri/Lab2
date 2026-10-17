<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $fillable = ['journal_entry_id', 'accounting_account_id', 'line_number', 'description', 'debit', 'credit', 'foreign_debit', 'foreign_credit', 'counterparty_type', 'counterparty_id', 'dimensions'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'foreign_debit' => 'decimal:2', 'foreign_credit' => 'decimal:2', 'dimensions' => 'array'];
    }

    protected static function booted(): void
    {
        $guard = function ($m) {
            $ids = array_filter([$m->journal_entry_id, $m->getOriginal('journal_entry_id')]);
            if (JournalEntry::withoutGlobalScopes()->whereIn('id', $ids)->whereIn('status', ['posted', 'reversed'])->exists()) {
                throw new \LogicException('Posted and reversed journal lines are immutable.');
            }
            if (! $m->journalEntry()->where('status', 'draft')->exists()) {
                throw new \LogicException('Journal lines require a visible draft in this company.');
            }
            if (! AccountingAccount::query()->whereKey($m->accounting_account_id)->where('company_id', $m->journalEntry->company_id)->exists()) {
                throw new \LogicException('Journal account and source company must agree.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account()
    {
        return $this->belongsTo(AccountingAccount::class, 'accounting_account_id');
    }
}
