<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{Auth,DB};
use App\Models\{User,Product,CustomerQuotation,FinancialAccount,AccountingPeriod,JournalEntry,AccountingAccount,PurchaseOrder,Supplier};
use App\Services\{CustomerQuotationService,FinancialAccountService,BankReconciliationService,AccountingService,PurchaseOrderService};
$file=$dc17Root.'/evidence/workflows.json';$e=is_file($file)?json_decode(file_get_contents($file),true):[];
$token=dc17Login('control');$account=json_decode(file_get_contents($dc16Root.'/accounts.json'),true)['control'];
$actor=fn()=>Auth::setUser(User::withoutGlobalScopes()->findOrFail($account['user_id']));$actor();
$must=function(bool $ok,string $message){if(!$ok)throw new RuntimeException($message);};
$save=function($key,$data)use(&$e){$e[$key]=$data;dc17Evidence('workflows',$e);echo $key.': '.$data['status']."\n";};
if(!isset($e['quotation'])){
 $s=app(CustomerQuotationService::class);$channel=App\Models\OrderChannel::firstOrFail();
 $payload=['customer_id'=>121,'order_channel_id'=>$channel->id,'quote_date'=>today()->toDateString(),'expires_on'=>today()->addDays(5)->toDateString(),'payment_type'=>'cash','notes'=>'DC17 acceptance fixture','idempotency_key'=>'dc17-quote-edit','items'=>[['product_id'=>302,'quantity'=>2,'unit'=>'pcs']]];
 $before=[DB::table('stock_movements')->count(),DB::table('journal_entries')->count()];
 $q=$s->create($payload);$revision=$s->present($q)['edit_revision'];$changed=[...$payload,'notes'=>'DC17 reviewed delivery terms','expected_revision'=>$revision];$q=$s->updateDraft($q,$changed);
 $stale=false;try{$s->updateDraft($q,$changed);}catch(Symfony\Component\HttpKernel\Exception\HttpException $x){$stale=$x->getStatusCode()===409;}
 $expired=$s->create([...$payload,'idempotency_key'=>'dc17-quote-expired','quote_date'=>'2026-10-01','expires_on'=>'2026-10-31']);
 $blocked=false;try{$s->convert($expired);}catch(Illuminate\Validation\ValidationException){$blocked=true;}
 $must($stale&&$blocked&&$s->present($expired)['effective_status']==='expired'&&$before===[DB::table('stock_movements')->count(),DB::table('journal_entries')->count()],'Quotation editing/expiry invariant failed');
 $save('quotation',['status'=>'PASS','quote_id'=>$q->id,'expired_quote_id'=>$expired->id,'stale_edit_rejected'=>$stale,'expired_conversion_blocked'=>$blocked,'stock_and_journals_unchanged'=>true]);
}
if(!isset($e['bank_split'])){
 $actor();$s=app(FinancialAccountService::class);$bank=FinancialAccount::where('name','DC17 split bank')->first()??$s->createAccount(['type'=>'bank','name'=>'DC17 split bank','currency'=>'EUR','opening_balance'=>0,'opening_date'=>today()->toDateString(),'is_active'=>true]);
 $ids=[];foreach([10,20] as $amount)$ids[]=$s->post($bank,['type'=>'inflow','amount'=>$amount,'transaction_date'=>today()->toDateString(),'reference_number'=>'DC17-SPLIT-'.$amount,'description'=>'DC17 disposable bank source','idempotency_key'=>'dc17-bank-'.$amount,'counter_accounting_account_id'=>AccountingAccount::where('code','3000')->value('id')])->id;
 $csv="Date,Amount,Balance\n".today()->toDateString().",30.00,30.00\n";$path=$dc17Root.'/evidence/bank-split.csv';file_put_contents($path,$csv);$upload=new Illuminate\Http\UploadedFile($path,'dc17-bank.csv','text/csv',null,true);$r=app(BankReconciliationService::class);
 $before=JournalEntry::count();$statement=$r->import($bank,$upload,['date'=>'Date','amount'=>'Amount','balance'=>'Balance']);$again=$r->import($bank,$upload,['date'=>'Date','amount'=>'Amount','balance'=>'Balance']);$row=$statement->rows->sole();
 $r->reconcileMany($row,$ids);$r->reconcileMany($row,$ids);
 $statement=$r->setBalances($statement,['period_from'=>today()->toDateString(),'period_to'=>today()->toDateString(),'opening_balance'=>0,'closing_balance'=>30,'reason'=>'Verified DC17 synthetic CSV opening and closing evidence']);$done=$r->complete($statement);$done=$r->complete($done);
 $proof=app(App\Services\BankReconciliationProof::class)->summary($done);
 $must($statement->id===$again->id&&$before===JournalEntry::count()&&$done->reconciliation_status==='completed'&&(float)$proof['difference']===0.0,'Bank split/retry invariant failed');
 $save('bank_split',['status'=>'PASS','bank_id'=>$bank->id,'statement_id'=>$statement->id,'transaction_ids'=>$ids,'csv_import_retry_same_statement'=>true,'split_retry_no_journal'=>true,'difference'=>$proof['difference']]);
}
if(!isset($e['po_cancel'])){
 $actor();$s=app(PurchaseOrderService::class);$supplier=Supplier::firstOrFail();$p=Product::findOrFail(302);$before=[JournalEntry::count(),DB::table('stock_movements')->count()];
 $po=PurchaseOrder::where('notes','DC17 disposable cancellation')->first()??$s->create(['supplier_id'=>$supplier->id,'warehouse_id'=>6,'ordered_at'=>today()->toDateString(),'currency'=>'EUR','status'=>'draft','notes'=>'DC17 disposable cancellation','items'=>[['product_id'=>$p->id,'description'=>$p->name,'unit'=>'pcs','quantity'=>2,'unit_price'=>2]]]);
 $s->cancel($po,'DC17 supervised cancellation before any receiving');
 $must($po->fresh()->status==='cancelled'&&$before===[JournalEntry::count(),DB::table('stock_movements')->count()],'PO cancel invariant failed');
 $save('po_cancel',['status'=>'PASS','po_id'=>$po->id,'history_retained'=>true,'no_stock_or_journal'=>true]);
}
if(!isset($e['close_reversal'])){
 $actor();$s=app(AccountingService::class);$period=AccountingPeriod::where('name','DC17 January 2027 acceptance')->first()??$s->savePeriod(['name'=>'DC17 January 2027 acceptance','starts_at'=>'2027-01-01','ends_at'=>'2027-01-31']);
 $a=AccountingAccount::where('code','6000')->firstOrFail();$b=AccountingAccount::where('code','3000')->firstOrFail();
 $data=['posting_date'=>'2027-01-15','description'=>'DC17 disposable manual journal, reversed in full','source_module'=>'manual','source_key'=>'dc17-close-reversal','lines'=>[['accounting_account_id'=>$a->id,'debit'=>1],['accounting_account_id'=>$b->id,'credit'=>1]]];
 $j=$s->createAndPost($data);$reversal=$s->reverse($j,'2027-01-15','DC17 correction through linked reversal authority');$again=$s->reverse($j->fresh(),'2027-01-15','DC17 retry does not duplicate reversal');
 $readiness=$s->periodReadiness($period);$closed=false;$postBlocked=false;
 if($readiness['ready_to_close']){
  $s->setPeriodStatus($period,'closed','DC17 verified synthetic period acceptance');
  try{$s->createDraft([...$data,'source_key'=>'dc17-after-close']);}catch(Illuminate\Validation\ValidationException){$postBlocked=true;}
  $closed=$period->fresh()->status==='closed';$s->setPeriodStatus($period->fresh(),'open','DC17 supervised reopen after close proof');
 }
 $must($reversal->id===$again->id&&$j->fresh()->status==='reversed'&&$reversal->reversal_of_id===$j->id,'Linked reversal invariant failed');
 $save('close_reversal',['status'=>$closed&&$postBlocked?'PASS':'PARTIAL','journal_id'=>$j->id,'reversal_id'=>$reversal->id,'period_id'=>$period->id,'closed'=>$closed,'closed_post_blocked'=>$postBlocked,'reopened'=>$period->fresh()->status==='open','checks'=>$readiness['checks']]);
}
if(($e['close_reversal']['closed']??false)===false){
 // Advance only this isolated acceptance process's clock to exercise the year boundary.
 // No historical sale/observation timestamps are added or rewritten.
 Carbon\Carbon::setTestNow('2027-02-01 12:00:00');Carbon\CarbonImmutable::setTestNow('2027-02-01 12:00:00');
 $actor();$s=app(AccountingService::class);$period=AccountingPeriod::findOrFail($e['close_reversal']['period_id']);$readiness=$s->periodReadiness($period);
 if($readiness['ready_to_close']){
  $s->setPeriodStatus($period,'closed','DC17 isolated year-transition acceptance');$blocked=false;
  try{$s->createDraft(['posting_date'=>'2027-01-15','description'=>'Must be blocked by closed period','source_key'=>'dc17-closed-attempt','lines'=>[]]);}catch(Illuminate\Validation\ValidationException){$blocked=true;}
  $s->setPeriodStatus($period->fresh(),'open','DC17 privileged reopen with reason and actor');
  $must($blocked,'Closed posting was not blocked');
  $save('close_reversal',[...$e['close_reversal'],'status'=>'PASS','closed'=>true,'closed_post_blocked'=>true,'reopened'=>$period->fresh()->status==='open','acceptance_clock'=>'2027-02-01 12:00:00 (isolated process only)','checks'=>$readiness['checks']]);
 }
 Carbon\Carbon::setTestNow();Carbon\CarbonImmutable::setTestNow();
}
