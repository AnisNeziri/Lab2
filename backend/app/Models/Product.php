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
        'name',
        'sku',
        'description',
        'quantity',
        'min_quantity',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'quantity' => 'integer',
            'min_quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
