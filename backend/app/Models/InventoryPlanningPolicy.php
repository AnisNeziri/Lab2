<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class InventoryPlanningPolicy extends Model {
 use BelongsToCompany;
 protected $guarded=['id'];
 protected function casts():array{return ['settings'=>'array'];}
 protected static function booted():void {static::updating(fn()=>throw new \LogicException('Planning policy versions are immutable. Create a new version.'));}
}
