<?php
namespace App\Models;
final class CustomerSalesPrediction extends AnalyticsPrediction {
 protected $table='analytics_predictions';
 protected static function booted():void {static::updating(function($r){if($r->isDirty(['entity_id','entity_type','model_key','model_version','value','generated_at','valid_until','analytics_snapshot_id']))throw new \LogicException('Customer predictions are frozen. Only outcomes can be appended.');});}
 public function scopeV8($q){return $q->where('model_key','customer-sales-v8')->where('entity_type','customer');}
}
