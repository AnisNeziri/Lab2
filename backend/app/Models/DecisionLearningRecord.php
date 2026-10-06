<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
/** Append-only derived evidence/policy/experiment journal, not a second ML registry. */
class DecisionLearningRecord extends Model {
 use BelongsToCompany;
 protected $guarded=['id'];
 protected function casts():array {return ['payload'=>'array','evidence_cutoff'=>'datetime'];}
 protected static function booted():void {
  static::updating(fn()=>throw new \LogicException('Learning evidence is immutable. Append a new version.'));
  static::deleting(fn()=>throw new \LogicException('Learning history is immutable.'));
 }
}
