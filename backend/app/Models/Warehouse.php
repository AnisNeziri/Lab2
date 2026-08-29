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
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'length_m' => 'float',
            'width_m' => 'float',
            'height_m' => 'float',
            'floor_count' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function stock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function inventoryCounts(): HasMany
    {
        return $this->hasMany(InventoryCountSession::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(WarehouseSection::class)->orderBy('sort_order');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class)->orderBy('path');
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'source_warehouse_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'destination_warehouse_id');
    }
}
