<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityDefect extends Model
{
    use BelongsToCompany, LogsActivity;
    protected $fillable = ['company_id','quality_inspection_id','quality_defect_category_id','supplier_id','goods_receipt_id','product_id','inventory_lot_id','severity','affected_quantity','description','discovered_at','discovered_by'];
    protected function casts(): array { return ['affected_quantity'=>'decimal:3','discovered_at'=>'datetime']; }
    public function inspection(): BelongsTo { return $this->belongsTo(QualityInspection::class, 'quality_inspection_id'); }
    public function category(): BelongsTo { return $this->belongsTo(QualityDefectCategory::class, 'quality_defect_category_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
