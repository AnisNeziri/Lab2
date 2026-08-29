<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockMovement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'location_id',
        'source_warehouse_id',
        'destination_warehouse_id',
        'stock_state',
        'type',
        'quantity',
        'quantity_before',
        'quantity_after',
        'warehouse_quantity_before',
        'warehouse_quantity_after',
        'location_quantity_before',
        'location_quantity_after',
        'affects_company_quantity',
        'reason',
        'movement_code',
        'unit_snapshot',
        'source_type',
        'source_id',
        'performed_by',
        'idempotency_key',
        'occurred_at',
        'metadata',
        'base_purchase_unit_cost',
        'landed_cost_unit',
        'final_unit_cost',
        'cost_total',
        'weighted_average_cost_before',
        'weighted_average_cost_after',
        'inventory_value_before',
        'inventory_value_after',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'quantity' => 'decimal:3',
            'quantity_before' => 'decimal:3',
            'quantity_after' => 'decimal:3',
            'warehouse_quantity_before' => 'decimal:3',
            'warehouse_quantity_after' => 'decimal:3',
            'location_quantity_before' => 'decimal:3',
            'location_quantity_after' => 'decimal:3',
            'affects_company_quantity' => 'boolean',
            'source_id' => 'integer',
            'performed_by' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
            'base_purchase_unit_cost' => 'decimal:6',
            'landed_cost_unit' => 'decimal:6',
            'final_unit_cost' => 'decimal:6',
            'cost_total' => 'decimal:6',
            'weighted_average_cost_before' => 'decimal:6',
            'weighted_average_cost_after' => 'decimal:6',
            'inventory_value_before' => 'decimal:6',
            'inventory_value_after' => 'decimal:6',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function traceLines(): HasMany
    {
        return $this->hasMany(StockMovementTrace::class);
    }
}
