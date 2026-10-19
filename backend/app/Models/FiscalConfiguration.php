<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FiscalConfiguration extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'enabled' => 'boolean', 'certificate_valid_from' => 'immutable_datetime', 'certificate_valid_to' => 'immutable_datetime'];
    }
}
