<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierClaim extends Model
{
    use BelongsToCompany, LogsActivity;
    protected $fillable = ['company_id','claim_number','supplier_id','purchase_order_id','goods_receipt_id','quality_inspection_id','status','requested_outcome','actual_resolution','claim_date','expected_resolution_date','resolved_at','affected_value','financial_amount','currency','communication_notes','resolution_notes','inventory_return_id','created_by','resolved_by'];
    protected function casts(): array { return ['claim_date'=>'date:Y-m-d','expected_resolution_date'=>'date:Y-m-d','resolved_at'=>'datetime','affected_value'=>'decimal:2','financial_amount'=>'decimal:2']; }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class)->withTrashed(); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class)->withTrashed(); }
    public function goodsReceipt(): BelongsTo { return $this->belongsTo(GoodsReceipt::class); }
    public function inspection(): BelongsTo { return $this->belongsTo(QualityInspection::class, 'quality_inspection_id'); }
    public function inventoryReturn(): BelongsTo { return $this->belongsTo(InventoryReturn::class); }
    public function items(): HasMany { return $this->hasMany(SupplierClaimItem::class); }
    public function defects(): BelongsToMany { return $this->belongsToMany(QualityDefect::class, 'supplier_claim_defects'); }
    public function attachments(): HasMany { return $this->hasMany(QualityAttachment::class); }
}
