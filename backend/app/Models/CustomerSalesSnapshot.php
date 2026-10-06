<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
final class CustomerSalesSnapshot extends Model {
 use BelongsToCompany;
 protected $guarded=['id'];
 protected function casts():array{return ['as_of'=>'date','evidence_cutoff'=>'datetime','evidence'=>'array'];}
 protected static function booted():void {static::updating(fn()=>throw new \LogicException('Customer sales evidence is immutable. Create a new version.'));}
}
