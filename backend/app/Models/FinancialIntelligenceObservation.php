<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
final class FinancialIntelligenceObservation extends Model {
 use BelongsToCompany;
 protected $guarded=['id'];
 protected function casts():array{return ['observation_date'=>'date:Y-m-d','observed_at'=>'datetime','facts'=>'array'];}
 protected static function booted():void {static::updating(fn()=>throw new \LogicException('Point-in-time observations are immutable.'));}
}
