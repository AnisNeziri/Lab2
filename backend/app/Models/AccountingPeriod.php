<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class AccountingPeriod extends Model {
 use BelongsToCompany;
 protected $fillable=['company_id','name','starts_at','ends_at','status','closed_by','closed_at','reopened_by','reopened_at','change_reason'];
 protected function casts():array{return ['starts_at'=>'date','ends_at'=>'date','closed_at'=>'datetime','reopened_at'=>'datetime'];}
}
