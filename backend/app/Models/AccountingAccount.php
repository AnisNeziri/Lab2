<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo,HasMany};
class AccountingAccount extends Model {
 use BelongsToCompany;
 protected $fillable=['company_id','code','name','type','parent_id','normal_balance','is_posting','is_system','is_active','cash_flow_class','description'];
 protected function casts():array{return ['is_posting'=>'boolean','is_system'=>'boolean','is_active'=>'boolean'];}
 public function parent():BelongsTo{return $this->belongsTo(self::class,'parent_id');}
 public function children():HasMany{return $this->hasMany(self::class,'parent_id')->orderBy('code');}
 public function lines():HasMany{return $this->hasMany(JournalLine::class);}
}
