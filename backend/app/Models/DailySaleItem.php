<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailySaleItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'daily_sale_id',
        'company_id',
        'line_number',
        'product_id',
        'warehouse_id',
        'location_id',
        'trace_allocations',
        'product_name',
        'unit',
        'conversion_mode',
        'conversion_factor',
        'quantity',
        'base_quantity',
        'unit_price',
        'unit_cost',
        'line_total',
        'cost_total',
        'gross_profit',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'warehouse_id' => 'integer',
            'location_id' => 'integer',
            'trace_allocations' => 'array',
            'quantity' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'conversion_factor' => 'decimal:6',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'gross_profit' => 'decimal:2',
        ];
    }

    public function dailySale(): BelongsTo
    {
        return $this->belongsTo(DailySale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }
}
