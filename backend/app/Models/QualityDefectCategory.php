<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;

class QualityDefectCategory extends Model
{
    use BelongsToCompany, LogsActivity;
    protected $fillable = ['company_id','name','description','is_active'];
    protected function casts(): array { return ['is_active'=>'boolean']; }
}
