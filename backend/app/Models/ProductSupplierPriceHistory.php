<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProductSupplierPriceHistory extends Model
{
    use BelongsToCompany;

    protected $table = 'product_supplier_price_history';

    protected $fillable = [
        'company_id', 'product_supplier_id', 'purchase_price', 'currency',
        'exchange_rate_to_base', 'exchange_rate_date', 'base_currency_price', 'effective_at',
        'changed_by', 'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:6',
            'exchange_rate_to_base' => 'decimal:8',
            'exchange_rate_date' => 'date:Y-m-d',
            'base_currency_price' => 'decimal:6',
            'effective_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Supplier price history is immutable.'));
        static::deleting(fn () => throw new LogicException('Supplier price history is immutable.'));
    }

    public function productSupplier(): BelongsTo
    {
        return $this->belongsTo(ProductSupplier::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by')->withTrashed();
    }
}
