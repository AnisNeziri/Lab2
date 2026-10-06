<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToCompany;
class SupplierDeliveryRisk extends Model {
 use BelongsToCompany;protected $guarded=['id'];
 protected function casts():array{return ['evidence'=>'array','feedback'=>'array'];}
 public function supplier(){return $this->belongsTo(Supplier::class);}
 public function purchaseOrder(){return $this->belongsTo(PurchaseOrder::class);}
}
