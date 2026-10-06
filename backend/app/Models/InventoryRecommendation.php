<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class InventoryRecommendation extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    protected static function booted():void {static::updating(function($r){if($r->planning_key&&$r->isDirty(['explanation','planning_policy_id','planning_key']))throw new \LogicException('Frozen planning evidence cannot be rewritten.');});}
    protected function casts():array{return ['explanation'=>'array','feedback'=>'array','outcome'=>'array','outcome_updated_at'=>'datetime','viewed_at'=>'datetime'];}
    public function product(){return $this->belongsTo(Product::class);}
    public function prediction(){return $this->belongsTo(AnalyticsPrediction::class,'analytics_prediction_id');}
    public function purchaseRequest(){return $this->belongsTo(PurchaseRequest::class);}
}
