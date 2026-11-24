<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FxRevaluation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'accounting_period_id', 'accounting_date', 'fingerprint', 'snapshot',
        'journal_entry_id', 'reversal_journal_id', 'approved_by', 'reason',
    ];

    protected $casts = ['accounting_date' => 'date:Y-m-d', 'snapshot' => 'array'];
}
