<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Traits\LogsActivity;

class Product extends Model
{
    use LogsActivity;

    protected $fillable = [
        'category_id',
        'supplier_id',
        'name',
        'sku',
        'description',
        'quantity',
        'min_quantity',
        'price',
        'purchase_price',
        'selling_price',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'supplier_id' => 'integer',
            'quantity' => 'integer',
            'min_quantity' => 'integer',
            'price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->quantity <= $this->min_quantity) {
            return 'low';
        }

        $highThreshold = max($this->min_quantity * 2, $this->min_quantity + 1);

        if ($this->quantity >= $highThreshold) {
            return 'high';
        }

        return 'normal';
    }

    protected $appends = ['stock_status'];
}
