<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $fillable = [
        'company_id',
        'category_id',
        'supplier_id',
        'default_warehouse_id',
        'tracking_mode',
        'near_expiry_days',
        'fefo_enabled',
        'name',
        'sku',
        'location_code',
        'barcode',
        'description',
        'image_data',
        'image_mime',
        'quantity',
        'unit',
        'min_quantity',
        'safety_stock',
        'reorder_point',
        'replenishment_history_days',
        'replenishment_review_days',
        'high_stock_threshold',
        'price',
        'purchase_price',
        'weighted_average_cost',
        'inventory_value',
        'weight_kg',
        'volume_m3',
        'selling_price',
        'vat_rate',
        'tax_treatment',
        'tax_legal_reference',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'supplier_id' => 'integer',
            'near_expiry_days' => 'integer',
            'fefo_enabled' => 'boolean',
            // Return quantities as numbers in JSON. Laravel's decimal cast
            // serializes values such as 700 as the string "700.000", which
            // is easily mistaken for 700000 in the product screens.
            // Inventory services round values to three decimals before save.
            'quantity' => 'float',
            'min_quantity' => 'float',
            'safety_stock' => 'float',
            'reorder_point' => 'float',
            'replenishment_history_days' => 'integer',
            'replenishment_review_days' => 'integer',
            'high_stock_threshold' => 'float',
            'price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'weighted_average_cost' => 'decimal:6',
            'inventory_value' => 'decimal:6',
            'weight_kg' => 'decimal:6',
            'volume_m3' => 'decimal:6',
            'selling_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
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

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('code');
    }

    public function warehouseStock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function inventoryLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function supplierCatalogue(): HasMany
    {
        return $this->hasMany(ProductSupplier::class)->orderByDesc('is_preferred')->orderBy('id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function getStockStatusAttribute(): string
    {
        if ((float) $this->quantity <= 0) {
            return 'out';
        }

        if ((float) $this->quantity <= (float) $this->min_quantity) {
            return 'low';
        }

        $highThreshold = (float) $this->high_stock_threshold;

        if ($highThreshold > 0 && (float) $this->quantity >= $highThreshold) {
            return 'high';
        }

        return 'normal';
    }

    protected $appends = ['stock_status'];

    protected $hidden = ['image_data'];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_data) {
            return null;
        }

        return 'data:'.($this->image_mime ?: 'image/jpeg').';base64,'.$this->image_data;
    }
}
