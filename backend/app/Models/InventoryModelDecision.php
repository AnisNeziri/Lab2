<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class InventoryModelDecision extends Model {
    use BelongsToCompany;
    protected $guarded=['id'];
    protected function casts():array{return ['evidence'=>'array'];}
    protected static function booted():void {
        static::creating(fn($m)=>$m->decision_key??=(string)\Illuminate\Support\Str::uuid());
        static::updating(fn()=>throw new \LogicException('Model decisions are immutable.'));
        static::deleting(fn()=>throw new \LogicException('Model decisions are immutable.'));
    }
}
