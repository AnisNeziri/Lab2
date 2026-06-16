<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseSection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'code',
        'name',
        'color',
        'light_color',
        'pos_x',
        'pos_z',
        'width',
        'depth',
        'floor_level',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pos_x' => 'float',
            'pos_z' => 'float',
            'width' => 'float',
            'depth' => 'float',
            'floor_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
