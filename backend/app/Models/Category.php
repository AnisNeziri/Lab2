<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'quality_inspection_mode',
        'quality_inspection_template_id',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function qualityInspectionTemplate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(QualityInspectionTemplate::class);
    }
}
