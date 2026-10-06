<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
final class FinancialIntelligenceSnapshot extends Model {
 use BelongsToCompany;
 protected $guarded=['id'];
 protected function casts():array{return ['as_of'=>'date:Y-m-d','evidence_cutoff'=>'datetime','evidence'=>'array','forecast'=>'array','evaluation'=>'array'];}
 protected static function booted():void {static::updating(function($r){foreach(['fingerprint','version','as_of','evidence_cutoff','evidence','forecast'] as $k)if($r->isDirty($k))throw new \LogicException('Frozen financial evidence cannot be rewritten.');});}
}
