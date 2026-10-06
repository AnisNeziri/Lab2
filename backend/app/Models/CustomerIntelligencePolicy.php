<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
final class CustomerIntelligencePolicy extends Model {
 use BelongsToCompany;
 protected $guarded=['id'];
 protected function casts():array{return ['settings'=>'array'];}
 protected static function booted():void {static::updating(fn()=>throw new \LogicException('Model decisions are immutable.'));
 }
}
