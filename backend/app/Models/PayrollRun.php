<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['totals' => 'array', 'warnings' => 'array', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function period()
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function lines()
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function payments()
    {
        return $this->hasMany(PayrollPayment::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    protected static function booted(): void
    {
        static::updating(function ($m) {
            if (! in_array($m->getOriginal('status'), ['posted', 'paid', 'closed', 'reversed'], true)) {
                return;
            }
            if ($m->getOriginal('status') === 'reversed' || array_diff(array_keys($m->getDirty()), ['status', 'reversal_journal_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'updated_at'])) {
                throw new \LogicException('Posted payroll calculations are immutable.');
            }
            if (! in_array($m->status, ['posted', 'paid', 'closed', 'reversed'], true)) {
                throw new \LogicException('Reverse posted payroll before correction.');
            }
            if ($m->status === 'reversed' && (! $m->reversal_journal_id || ! $m->reversal_reason || ! $m->reversed_by)) {
                throw new \LogicException('A linked audited reversal is required.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Payroll history cannot be deleted.'));
    }
}
