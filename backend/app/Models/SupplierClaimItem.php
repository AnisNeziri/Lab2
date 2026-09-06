<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierClaimItem extends Model
{
    protected $fillable = ['supplier_claim_id','product_id','goods_receipt_item_id','affected_quantity','unit_value','line_value','notes'];
    protected function casts(): array { return ['affected_quantity'=>'decimal:3','unit_value'=>'decimal:6','line_value'=>'decimal:2']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
