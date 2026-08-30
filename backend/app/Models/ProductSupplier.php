<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductSupplier extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'product_id', 'supplier_id', 'supplier_sku', 'purchase_price',
        'currency', 'exchange_rate_to_base', 'exchange_rate_date', 'pack_size', 'minimum_order_quantity',
        'usual_lead_time_days', 'is_preferred', 'is_active', 'supplier_description',
        'last_price_changed_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:6',
            'exchange_rate_to_base' => 'decimal:8',
            'exchange_rate_date' => 'date:Y-m-d',
            'pack_size' => 'decimal:3',
            'minimum_order_quantity' => 'decimal:3',
            'usual_lead_time_days' => 'integer',
            'is_preferred' => 'boolean',
            'is_active' => 'boolean',
            'last_price_changed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductSupplierPriceHistory::class)->latest('effective_at')->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function getBaseCurrencyPriceAttribute(): ?float
    {
        if ($this->purchase_price === null) {
            return null;
        }

        return round((float) $this->purchase_price * (float) $this->exchange_rate_to_base, 6);
    }

    protected $appends = ['base_currency_price'];
}
