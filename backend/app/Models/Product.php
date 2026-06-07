<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Traits\LogsActivity;

class Product extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $fillable = [
        'company_id',
        'category_id',
        'supplier_id',
        'name',
        'sku',
        'location_code',
        'barcode',
        'description',
        'quantity',
        'unit',
        'min_quantity',
        'high_stock_threshold',
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
            'high_stock_threshold' => 'integer',
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

        $highThreshold = $this->high_stock_threshold > 0
            ? $this->high_stock_threshold
            : max($this->min_quantity * 2, $this->min_quantity + 1);

        if ($this->quantity >= $highThreshold) {
            return 'high';
        }

        return 'normal';
    }

    protected $appends = ['stock_status'];
}
