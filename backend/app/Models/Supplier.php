<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'is_active',
        'created_by',
        'updated_by',
        'quality_inspection_mode',
        'quality_inspection_template_id',
    ];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'is_active' => 'boolean'];
    }

    public function qualityInspectionTemplate(): BelongsTo
    {
        return $this->belongsTo(QualityInspectionTemplate::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function catalogueItems(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }
}
