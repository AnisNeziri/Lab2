<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SupplierQuote extends Model { use BelongsToCompany; protected $fillable=['company_id','rfq_id','supplier_id','revision','status','currency','exchange_rate','exchange_rate_date','exchange_rate_source','payment_terms','shipping_terms','valid_until','notes','created_by']; protected function casts():array{return ['exchange_rate'=>'decimal:6','exchange_rate_date'=>'date:Y-m-d','valid_until'=>'date:Y-m-d'];} public function items():HasMany{return $this->hasMany(SupplierQuoteItem::class);} public function supplier():BelongsTo{return $this->belongsTo(Supplier::class)->withTrashed();} }
