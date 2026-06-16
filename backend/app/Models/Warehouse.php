<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'address',
        'length_m',
        'width_m',
        'height_m',
        'floor_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'length_m' => 'float',
            'width_m' => 'float',
            'height_m' => 'float',
            'floor_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function stock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(WarehouseSection::class)->orderBy('sort_order');
    }
}
