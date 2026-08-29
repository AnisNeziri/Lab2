<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InvoiceSequence extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'document_type',
        'year',
        'next_number',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }
}
