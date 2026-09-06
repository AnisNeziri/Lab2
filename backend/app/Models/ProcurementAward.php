<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ProcurementAward extends Model { use BelongsToCompany; protected $fillable=['company_id','rfq_id','purchase_request_item_id','supplier_quote_item_id','purchase_order_id','status','selected_by','selected_at','conversion_key']; protected function casts():array{return ['selected_at'=>'datetime'];} public function quoteItem(): BelongsTo{return $this->belongsTo(SupplierQuoteItem::class,'supplier_quote_item_id');} public function requestItem(): BelongsTo{return $this->belongsTo(PurchaseRequestItem::class,'purchase_request_item_id');} }
