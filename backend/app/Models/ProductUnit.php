<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'product_id', 'code', 'label', 'allow_purchase', 'allow_sale',
        'conversion_mode', 'factor_to_base', 'is_default_purchase', 'is_default_sale', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'factor_to_base' => 'decimal:6',
            'allow_purchase' => 'boolean',
            'allow_sale' => 'boolean',
            'is_default_purchase' => 'boolean',
            'is_default_sale' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
