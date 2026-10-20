<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'tax_code_id', 'tax_snapshot', 'pricing_mode', 'pricing_discount_amount', 'sef_category_id',
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
            'pricing_discount_amount' => 'decimal:2', 'tax_snapshot' => 'array', 'tax_code_id' => 'integer',            'invoice_id' => 'integer',
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

    protected static function booted(): void
    {
        static::deleting(function (self $line) {
            if ($line->invoice()->whereNotNull('issued_at')->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['tax'=>'Issued tax lines cannot be deleted. Use a linked correction.']);
        });
        static::updating(function (self $line) {
            if ($line->isDirty(['tax_code_id','tax_snapshot','pricing_mode','pricing_discount_amount','vat_rate','vat_amount','tax_treatment','tax_legal_reference','taxable_amount','line_total'])
                && $line->invoice()->whereNotNull('issued_at')->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['tax'=>'Issued tax lines are immutable. Use a linked credit/correction document.']);
            }
        });
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
