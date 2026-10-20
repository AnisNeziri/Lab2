<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'journal_number', 'posting_date', 'reference_number', 'description', 'source_module', 'source_type', 'source_id', 'source_key', 'currency', 'exchange_rate', 'total_debit', 'total_credit', 'status', 'reversal_of_id', 'created_by', 'posted_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_reason', 'revision', 'control_acknowledgement', 'exchange_rate_date', 'exchange_rate_source', 'correction_of_id'];

    protected function casts(): array
    {
        return ['posting_date' => 'date', 'exchange_rate' => 'decimal:8', 'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'revision' => 'integer', 'control_acknowledgement' => 'array', 'exchange_rate_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(function ($m) {
            if (! in_array($m->getOriginal('status'), ['posted', 'reversed'], true)) {
                return;
            }
            $allowed = ['status', 'reversed_by', 'reversed_at', 'reversal_reason', 'updated_at'];
            if ($m->getOriginal('status') !== 'posted' || $m->status !== 'reversed' || ! $m->isDirty('status') || array_diff(array_keys($m->getDirty()), $allowed)) {
                throw new \LogicException('Posted and reversed journal entries are immutable.');
            }
            if (! $m->reversed_by || ! $m->reversed_at || ! $m->reversal_reason || ! $m->reversal()->where('status', 'posted')->exists()) {
                throw new \LogicException('A posted linked reversal and its audit facts are required.');
            }
        });
        static::deleting(fn ($m) => $m->status !== 'draft' ? throw new \LogicException('Posted and reversed journal entries cannot be deleted.') : null);
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_number');
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by')->withTrashed();
    }
}
