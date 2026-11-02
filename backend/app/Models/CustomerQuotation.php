<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class CustomerQuotation extends Model {
 use BelongsToCompany; protected $guarded=['id'];
 protected function casts(): array {return ['quote_date'=>'date:Y-m-d','expires_on'=>'date:Y-m-d','total_amount'=>'decimal:2','sent_at'=>'datetime','accepted_at'=>'datetime'];}
 public function items(){return $this->hasMany(CustomerQuotationItem::class,'quotation_id');}
 public function customer(){return $this->belongsTo(Customer::class);}
}
