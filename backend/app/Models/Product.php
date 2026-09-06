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
        'expiration_controlled',
        'default_shelf_life_days',
        'shelf_life_basis',
        'near_expiry_days',
        'fefo_enabled',
        'name',
        'brand',
        'sku',
        'location_code',
        'barcode',
        'barcode_normalized',
        'description',
        'attributes',
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
        'length_cm',
        'width_cm',
        'height_cm',
        'volume_m3',
        'country_of_origin',
        'hs_code',
        'lifecycle_status',
        'discontinued_at',
        'archived_at',
        'selling_price',
        'vat_rate',
        'tax_treatment',
        'tax_legal_reference',
        'quality_inspection_mode',
        'quality_inspection_template_id',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'supplier_id' => 'integer',
            'near_expiry_days' => 'integer',
            'expiration_controlled' => 'boolean',
            'default_shelf_life_days' => 'integer',
            'fefo_enabled' => 'boolean',
            'attributes' => 'array',
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
            'length_cm' => 'decimal:3',
            'width_cm' => 'decimal:3',
            'height_cm' => 'decimal:3',
            'volume_m3' => 'decimal:6',
            'discontinued_at' => 'datetime',
            'archived_at' => 'datetime',
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
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function qualityInspectionTemplate(): BelongsTo
    {
        return $this->belongsTo(QualityInspectionTemplate::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('code');
    }

    public function alternativeBarcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class)->orderBy('id');
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
        $available = (float) $this->available_quantity;
        if ($available <= 0) {
            return 'out';
        }

        if ($available <= (float) $this->min_quantity) {
            return 'low';
        }

        $highThreshold = (float) $this->high_stock_threshold;

        if ($highThreshold > 0 && $available >= $highThreshold) {
            return 'high';
        }

        return 'normal';
    }

    public function getAvailableQuantityAttribute(): float
    {
        if ($this->relationLoaded('warehouseStock')) {
            return $this->warehouseStock->isNotEmpty()
                ? round((float) $this->warehouseStock->sum('available_quantity'), 3)
                : round((float) $this->quantity, 3);
        }

        // Some service responses serialize an individual product without the
        // warehouse relation. Query the authoritative balance once; use the
        // legacy aggregate only for records that truly have no balance rows.
        $balance = WarehouseStock::withoutGlobalScopes()
            ->where('company_id', $this->company_id)
            ->where('product_id', $this->getKey())
            ->selectRaw('COUNT(*) AS balance_count, COALESCE(SUM(available_quantity), 0) AS available_total')
            ->first();
        if ((int) ($balance?->balance_count ?? 0) > 0) {
            return round((float) $balance->available_total, 3);
        }

        return round((float) $this->quantity, 3);
    }

    protected $appends = ['stock_status', 'available_quantity'];

    protected $hidden = ['image_data'];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_data) {
            return null;
        }

        return 'data:'.($this->image_mime ?: 'image/jpeg').';base64,'.$this->image_data;
    }
}
