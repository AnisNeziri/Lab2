<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'purchase_order_id', 'warehouse_id', 'location_id', 'receipt_number',
        'supplier_document_number', 'received_at', 'status', 'notes', 'received_by', 'idempotency_key',
        'request_fingerprint',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class)->withTrashed(); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function location(): BelongsTo { return $this->belongsTo(WarehouseLocation::class); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by')->withTrashed(); }
    public function items(): HasMany { return $this->hasMany(GoodsReceiptItem::class); }
    public function landedCosts(): HasMany { return $this->hasMany(LandedCost::class); }
    public function qualityInspections(): HasMany { return $this->hasMany(QualityInspection::class); }
}
