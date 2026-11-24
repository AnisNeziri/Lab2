<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FixedAssetCategory extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'minimum_cost', 'useful_life_months', 'policy_reference', 'approved_by'];

    protected $casts = ['minimum_cost' => 'decimal:2', 'useful_life_months' => 'integer'];
}
