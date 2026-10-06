<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class SupplyOptimizationPlan extends Model {
 use BelongsToCompany;
 protected $guarded=['id'];
 protected function casts():array {return ['scope'=>'array','input'=>'array','result'=>'array','approved'=>'array','drafts'=>'array','execution'=>'array','evidence_cutoff'=>'datetime'];}
 protected static function booted():void {static::updating(function(self $p){
  foreach(['version','scope','evidence_cutoff','created_by','input','result','approved','drafts'] as $key)if($p->getRawOriginal($key)!==null&&$p->isDirty($key))throw new \LogicException('Frozen optimization evidence cannot be overwritten. Create a new plan.');
 });}
}
