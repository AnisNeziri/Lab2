<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AccountingRecoveryAttempt extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'accounting_exception_id', 'attempted_by', 'status', 'source_type',
        'source_id', 'journal_entry_id', 'message', 'context', 'attempted_at',
    ];

    protected function casts(): array
    {
        return ['context' => 'array', 'attempted_at' => 'datetime'];
    }

    public function exception() { return $this->belongsTo(AccountingException::class, 'accounting_exception_id'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
    public function user() { return $this->belongsTo(User::class, 'attempted_by')->withTrashed(); }
}
