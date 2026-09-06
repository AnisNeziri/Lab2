<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PurchaseRequestItem extends Model { protected $fillable=['purchase_request_id','product_id','description','unit','quantity','estimated_unit_price']; protected function casts(): array{return ['quantity'=>'decimal:3','estimated_unit_price'=>'decimal:2'];} public function product(): BelongsTo{return $this->belongsTo(Product::class);} }
