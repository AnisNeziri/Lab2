<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DailySalesDay extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'sale_date', 'notes'];

    protected function casts(): array
    {
        return ['sale_date' => 'date'];
    }
}
