<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\{Auth,DB};
if((int)DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')!==364)throw new RuntimeException('Complete 365 historical days before evaluating the final Ask corpus');
$lock=DB::selectOne("SELECT GET_LOCK('aims_dc16_replay',0) acquired");if(!$lock->acquired)throw new RuntimeException('Replay still active');
try{
    $accounts=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);
    $company=$accounts['primary']['company_id'];
    $product=DB::table('products')->where('company_id',$company)->where('sku','DC16-P004')->first();
    $customer=DB::table('customers')->where('company_id',$company)->orderBy('id')->first();
    $ar=DB::table('journal_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')->join('accounting_accounts as a','a.id','=','l.accounting_account_id')->where('j.company_id',$company)->whereIn('j.status',['posted','reversed'])->where('a.code','1100')->sum(DB::raw('l.debit-l.credit'));
    $cashIds=DB::table('accounting_accounts')->where('company_id',$company)->whereIn('code',['1000','1010'])->pluck('id');
    do{$old=count($cashIds);$cashIds=$cashIds->merge(DB::table('accounting_accounts')->where('company_id',$company)->whereIn('parent_id',$cashIds)->pluck('id'))->unique()->values();}while(count($cashIds)>$old);
    $cash=DB::table('journal_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')->where('j.company_id',$company)->whereIn('j.status',['posted','reversed'])->whereIn('l.accounting_account_id',$cashIds)->sum(DB::raw('l.debit-l.credit'));
    $available=DB::table('warehouse_stock')->where('company_id',$company)->where('product_id',$product->id)->sum(DB::raw('quantity-reserved_quantity-damaged_quantity-quarantine_quantity-blocked_quantity'));
    $oracles=['ar_gl'=>number_format((float)$ar,2,'.',''),'cash_gl'=>number_format((float)$cash,2,'.',''),'product_available'=>number_format((float)$available,3,'.','')];
    $tables=['products','warehouse_stock','stock_movements','customer_debt_transactions','purchase_orders','sales_orders','invoices','invoice_items','payment_transactions','journal_entries','journal_lines','financial_account_transactions','expenses','payroll_runs','payroll_lines','employee_advances','accounting_periods','customer_quotations','purchase_requests'];
    $hash=function()use($tables){$h=hash_init('sha256');foreach($tables as $table){hash_update($h,$table);foreach(DB::table($table)->orderBy('id')->cursor() as $row)hash_update($h,json_encode($row));}return hash_final($h);};
    // This digest spans both isolated tenants. Run without concurrent fixture writers.
    $before=$hash();$results=[];$tokens=[];
    foreach(json_decode(file_get_contents(__DIR__.'/ask-corpus.json'),true) as $case){
        $role=$case['role'];if(!isset($tokens[$role]))$tokens[$role]=dc16Login($role);
        $payload=['question'=>$case['question'],'language'=>$case['language']];
        if(($case['entity']??null)==='milano04')$payload['entity']=['type'=>'product','id'=>$product->id];
        if(($case['entity']??null)==='first_customer')$payload['entity']=['type'=>'customer','id'=>$customer->id];
        $start=microtime(true);$response=dc16Request('POST','intelligence-assistant/ask',$payload,$tokens[$role],[],[200,403]);
        $elapsed=round((microtime(true)-$start)*1000,2);
        $responseText=json_encode($response,JSON_UNESCAPED_UNICODE);
        $expected=isset($case['oracle'])?$oracles[$case['oracle']]:null;
        $numericCandidate=$expected!==null && (str_contains($responseText,$expected)||str_contains($responseText,number_format((float)$expected,2,'.',',')));
        $row=['case'=>$case,'ms'=>$elapsed,'response'=>$response,'independent_expected_value'=>$expected,'oracle_basis'=>$case['oracle']??null,'numeric_token_agreement'=>$expected!==null?$numericCandidate:null,'intent_agreement'=>isset($case['intent'])?in_array($response['intent']??null,$case['intent'],true):null,'read_only_flag'=>$response['read_only']??null,'semantic_grade'=>'REVIEW REQUIRED — not inferred from fluent text or numeric token agreement'];
        if(isset($case['followup']) && isset($response['conversation_id']))$row['followup_response']=dc16Request('POST','intelligence-assistant/ask',['question'=>$case['followup'],'language'=>$case['language'],'conversation_id'=>$response['conversation_id']],$tokens[$role],[],[200,403]);
        $results[]=$row;dc16Evidence('ask-evaluation-progress',['results'=>$results,'completed'=>count($results),'total'=>40]);
    }
    $after=$hash();
    dc16Evidence('ask-evaluation',['as_of'=>date(DATE_ATOM),'questions'=>count($results),'independent_oracles'=>$oracles,'business_state_before'=>$before,'business_state_after'=>$after,'business_state_unchanged'=>hash_equals($before,$after),'results'=>$results,'provider'=>'existing deterministic/local AIMS; no paid or external model','grading_limit'=>'Numeric-token agreement is only a review aid. Source/period/currency/role/bilingual usefulness require explicit review before PASS.']);
    if(!hash_equals($before,$after))throw new RuntimeException('Ask corpus changed protected business state');
    echo "Ask corpus executed with independent oracles; semantic review remains required.\n";
}finally{DB::select("SELECT RELEASE_LOCK('aims_dc16_replay')");}
