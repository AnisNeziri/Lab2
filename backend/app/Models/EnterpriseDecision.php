<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class EnterpriseDecision extends Model {
 use BelongsToCompany;
 protected $guarded=['id'];
 protected function casts():array{return ['evidence'=>'array','alternatives'=>'array','reasoning'=>'array','history'=>'array','outcome'=>'array','generated_at'=>'datetime','evidence_cutoff_at'=>'datetime','last_checked_at'=>'datetime'];}
 protected static function booted():void {static::updating(function($d){if($d->isDirty(['product_id','warehouse_id','logical_key','version','decision_type','source_fingerprint','evidence','alternatives','reasoning','generated_at','evidence_cutoff_at']))throw new \LogicException('Decision evidence is immutable. Supersede it with a new version.');});}
 public function product(){return $this->belongsTo(Product::class);}
 public function warehouse(){return $this->belongsTo(Warehouse::class);}
 public function purchaseRequest(){return $this->belongsTo(PurchaseRequest::class);}
 public function stockTransfer(){return $this->belongsTo(StockTransfer::class);}
}
