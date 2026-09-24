<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class AccountingException extends Model {
 use BelongsToCompany;
 protected $fillable=['company_id','exception_key','type','severity','message','details','status','resolved_by','resolved_at','resolution'];
 protected function casts():array{return ['details'=>'array','resolved_at'=>'datetime'];}
 public function attempts(){return $this->hasMany(AccountingRecoveryAttempt::class)->latest('attempted_at');}
}
