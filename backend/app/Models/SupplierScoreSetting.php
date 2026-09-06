<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SupplierScoreSetting extends Model
{
    use BelongsToCompany;
    protected $fillable = ['company_id','quality_weight','delivery_weight','commercial_weight','reliability_weight','updated_by'];
    protected function casts(): array { return ['quality_weight'=>'float','delivery_weight'=>'float','commercial_weight'=>'float','reliability_weight'=>'float']; }
}
