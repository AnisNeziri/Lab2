<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CorporateTaxReview extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'accounting_period_id', 'fingerprint', 'snapshot', 'approved_by', 'journal_entry_id'];

    protected $casts = ['snapshot' => 'array'];
}
