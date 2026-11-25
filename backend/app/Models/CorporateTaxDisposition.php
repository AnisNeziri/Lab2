<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CorporateTaxDisposition extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'accounting_period_id', 'approved_by', 'policy', 'basis', 'historical'];

    protected $casts = ['policy' => 'array', 'historical' => 'boolean'];
}
