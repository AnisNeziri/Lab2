<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class QualityInspection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id','inspection_number','supplier_id','purchase_order_id','purchase_order_item_id',
        'goods_receipt_id','goods_receipt_item_id','product_id','inventory_lot_id','warehouse_id','location_id',
        'quality_inspection_template_id','inspector_id','inspection_date','inspection_scope','source_stock_state',
        'received_quantity','inspected_quantity','accepted_quantity','rejected_quantity','quarantine_quantity',
        'damaged_quantity','status','decision','notes','trace_allocations','revision_of_id','created_by',
        'finalized_by','finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date'=>'datetime','finalized_at'=>'datetime','trace_allocations'=>'array',
            'received_quantity'=>'decimal:3','inspected_quantity'=>'decimal:3','accepted_quantity'=>'decimal:3',
            'rejected_quantity'=>'decimal:3','quarantine_quantity'=>'decimal:3','damaged_quantity'=>'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $inspection): void {
            if ($inspection->getOriginal('finalized_at') !== null) {
                throw new LogicException('Finalized inspections are immutable. Create a correction revision instead.');
            }
        });
        static::deleting(function (self $inspection): void {
            if ($inspection->finalized_at !== null) throw new LogicException('Finalized inspections cannot be deleted.');
        });
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class)->withTrashed(); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class)->withTrashed(); }
    public function purchaseOrderItem(): BelongsTo { return $this->belongsTo(PurchaseOrderItem::class); }
    public function goodsReceipt(): BelongsTo { return $this->belongsTo(GoodsReceipt::class); }
    public function goodsReceiptItem(): BelongsTo { return $this->belongsTo(GoodsReceiptItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function inventoryLot(): BelongsTo { return $this->belongsTo(InventoryLot::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function location(): BelongsTo { return $this->belongsTo(WarehouseLocation::class); }
    public function template(): BelongsTo { return $this->belongsTo(QualityInspectionTemplate::class, 'quality_inspection_template_id'); }
    public function inspector(): BelongsTo { return $this->belongsTo(User::class, 'inspector_id')->withTrashed(); }
    public function finalizer(): BelongsTo { return $this->belongsTo(User::class, 'finalized_by')->withTrashed(); }
    public function revisionOf(): BelongsTo { return $this->belongsTo(self::class, 'revision_of_id'); }
    public function results(): HasMany { return $this->hasMany(QualityInspectionResult::class); }
    public function defects(): HasMany { return $this->hasMany(QualityDefect::class); }
    public function attachments(): HasMany { return $this->hasMany(QualityAttachment::class); }
}
