<?php
require dirname(__DIR__).'/dc17/bootstrap.php';
$dc17Root='C:/Users/anisi/.codex/recovery/dc18-20261104';
use Illuminate\Support\Facades\DB;
$file=dirname(__DIR__).'/dc16/ask-corpus.json';$cases=json_decode(file_get_contents($file),true);
$old=json_decode(file_get_contents($dc16Root.'/storage/evidence/ask-evaluation.json'),true);$oracles=$old['independent_oracles'];
$oracles['meters_available']=round((float)DB::table('warehouse_stock as w')->join('products as p','p.id','=','w.product_id')->where('w.company_id',2)->where('p.unit','m')->sum('w.available_quantity'),3);
$from=date('Y-m-01',strtotime('first day of last month'));$to=date('Y-m-d',strtotime('last day of last month'));
$oracles['last_month_posted_sales']=round((float)DB::table('journal_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')->join('accounting_accounts as a','a.id','=','l.accounting_account_id')->where('j.company_id',2)->whereIn('j.status',['posted','reversed'])->where('a.code','4000')->whereBetween('j.posting_date',[$from,$to])->sum(DB::raw('l.credit-l.debit')),2);
$protected=['stock_movements','journal_entries','journal_lines','customer_debt_transactions','purchase_orders','purchase_requests','sales_orders','invoices','payroll_runs','payroll_lines'];
$digest=function()use($protected){$h=hash_init('sha256');foreach($protected as $table){hash_update($h,$table);foreach(DB::table($table)->orderBy('id')->cursor() as $row)hash_update($h,json_encode($row));}return hash_final($h);};
$selected=array_filter(explode(',',getenv('DC17_ASK_CASES')?:''));
$priorPath=$dc17Root.'/evidence/ask-evaluation.json';
$prior=$selected&&is_file($priorPath)?json_decode(file_get_contents($priorPath),true):null;
$before=$digest();$tokens=[];$results=$prior['results']??[];
$ask=function($payload,$token){for($n=0;$n<4;$n++){$r=dc17Request('POST','intelligence-assistant/ask',$payload,$token);if($r['status']!==429)return $r;sleep(min(60,max(1,(int)($r['retry_after']??60))));}throw new RuntimeException('Assistant limiter remained active');};
foreach($cases as $case){
 if($selected&&!in_array($case['id'],$selected,true))continue;
 if(!isset($tokens[$case['role']]))$tokens[$case['role']]=dc17Login($case['role']);
 $payload=['question'=>$case['question'],'language'=>$case['language']];
 if(($case['entity']??null)==='milano04')$payload['entity']=['type'=>'product','id'=>4];
 if(($case['entity']??null)==='first_customer')$payload['entity']=['type'=>'customer','id'=>1];
 $r=$ask($payload,$tokens[$case['role']]);$entry=['case'=>$case,'http'=>$r['status'],'ms'=>$r['ms'],'response'=>$r['body']];
 if(isset($case['followup'],$r['body']['conversation_id']))$entry['followup']=$ask(['question'=>$case['followup'],'language'=>$case['language'],'conversation_id'=>$r['body']['conversation_id']],$tokens[$case['role']]);
 $results=array_values(array_filter($results,fn($r)=>$r['case']['id']!==$case['id']));$results[]=$entry;dc17Evidence('ask-progress',['completed'=>count($results),'total'=>count($cases)]);
}
$order=array_flip(array_column($cases,'id'));usort($results,fn($a,$b)=>$order[$a['case']['id']]<=>$order[$b['case']['id']]);
$after=$digest();dc17Evidence('ask-evaluation',['corpus_sha256'=>hash_file('sha256',$file),'cases'=>count($cases),'oracles'=>$oracles,'oracle_period'=>['from'=>$from,'to'=>$to],'business_before'=>$before,'business_after'=>$after,'business_unchanged'=>hash_equals($before,$after),'results'=>$results,'grade'=>'Explicit source/intent review required; successful HTTP alone is not a PASS']);
echo "Unchanged 40-case corpus executed; protected business state ".(hash_equals($before,$after)?'unchanged':'CHANGED').".\n";if(!hash_equals($before,$after))exit(2);
