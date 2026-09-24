<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class JournalEntry extends Model {
 use BelongsToCompany;
 protected $fillable=['company_id','journal_number','posting_date','reference_number','description','source_module','source_type','source_id','source_key','currency','exchange_rate','total_debit','total_credit','status','reversal_of_id','created_by','posted_by','posted_at','reversed_by','reversed_at','reversal_reason'];
 protected function casts():array{return ['posting_date'=>'date','exchange_rate'=>'decimal:8','total_debit'=>'decimal:2','total_credit'=>'decimal:2','posted_at'=>'datetime','reversed_at'=>'datetime'];}
 protected static function booted():void{static::updating(function($m){if($m->getOriginal('status')==='posted'&&!$m->isDirty(['status','reversed_by','reversed_at','reversal_reason']))throw new \LogicException('Posted journal entries are immutable.');});static::deleting(fn($m)=>$m->status==='posted'?throw new \LogicException('Posted journal entries cannot be deleted.'):null);}
 public function lines(){return $this->hasMany(JournalLine::class)->orderBy('line_number');}
 public function reversalOf(){return $this->belongsTo(self::class,'reversal_of_id');}
 public function reversal(){return $this->hasOne(self::class,'reversal_of_id');}
 public function creator(){return $this->belongsTo(User::class,'created_by')->withTrashed();}
 public function poster(){return $this->belongsTo(User::class,'posted_by')->withTrashed();}
}
