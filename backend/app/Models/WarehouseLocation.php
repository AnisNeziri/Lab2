<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WarehouseLocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'warehouse_id', 'parent_id', 'type', 'code', 'name', 'path', 'floor_level', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['floor_level' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function section(): HasOne
    {
        return $this->hasOne(WarehouseSection::class);
    }

    public function stock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'location_id');
    }
}
