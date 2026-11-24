<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'category_id', 'asset_number', 'name', 'available_for_use_on', 'residual_value', 'created_by'];

    protected $casts = ['available_for_use_on' => 'date:Y-m-d', 'residual_value' => 'decimal:2'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FixedAssetEntry::class);
    }
}
