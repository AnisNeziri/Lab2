<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JournalLine extends Model {
 protected $fillable=['journal_entry_id','accounting_account_id','line_number','description','debit','credit','foreign_debit','foreign_credit','counterparty_type','counterparty_id'];
 protected function casts():array{return ['debit'=>'decimal:2','credit'=>'decimal:2','foreign_debit'=>'decimal:2','foreign_credit'=>'decimal:2'];}
 protected static function booted():void{$guard=function($m){if($m->journalEntry()->where('status','posted')->exists())throw new \LogicException('Posted journal lines are immutable.');};static::updating($guard);static::deleting($guard);}
 public function journalEntry(){return $this->belongsTo(JournalEntry::class);}
 public function account(){return $this->belongsTo(AccountingAccount::class,'accounting_account_id');}
}
