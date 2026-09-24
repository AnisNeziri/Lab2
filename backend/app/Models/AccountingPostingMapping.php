<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class AccountingPostingMapping extends Model {
 use BelongsToCompany;
 protected $fillable=['company_id','mapping_key','accounting_account_id','updated_by'];
 public function account(){return $this->belongsTo(AccountingAccount::class,'accounting_account_id');}
}
