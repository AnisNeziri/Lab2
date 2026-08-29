<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'warehouse_id',
        'location_id',
        'trace_allocations',
        'sku_snapshot',
        'description',
        'unit',
        'conversion_mode',
        'conversion_factor',
        'quantity',
        'base_quantity',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'taxable_amount',
        'vat_rate',
        'vat_amount',
        'tax_treatment',
        'tax_legal_reference',
        'unit_cost',
        'cost_total',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'invoice_id' => 'integer',
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'location_id' => 'integer',
            'trace_allocations' => 'array',
            'quantity' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'conversion_factor' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'cost_total' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
