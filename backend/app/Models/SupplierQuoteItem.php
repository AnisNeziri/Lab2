<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SupplierQuoteItem extends Model { protected $fillable=['supplier_quote_id','purchase_request_item_id','offered_quantity','unit_price','minimum_order_quantity','lead_time_days','notes']; protected function casts():array{return ['offered_quantity'=>'decimal:3','unit_price'=>'decimal:2','minimum_order_quantity'=>'decimal:3'];} public function supplierQuote():BelongsTo{return $this->belongsTo(SupplierQuote::class);} public function requestItem():BelongsTo{return $this->belongsTo(PurchaseRequestItem::class,'purchase_request_item_id');} }
