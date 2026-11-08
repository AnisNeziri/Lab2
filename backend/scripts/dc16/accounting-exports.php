<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
$path=$dc16Root.'/storage/evidence/accounting-exports.json';
$token=dc16Login('primary');$results=is_file($path)?json_decode(file_get_contents($path),true):[];
foreach(['trial','pl','balance','cash','ar','ap','gl'] as $report){
 if(isset($results[$report]))continue;
 Auth::forgetGuards();$r=Request::create('/api/accounting/exports/'.$report.'?from=2025-11-01&to=2026-10-31','GET',[],[],[],['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token]);$k=app(Illuminate\Contracts\Http\Kernel::class);$s=$k->handle($r);ob_start();$s->sendContent();$bytes=ob_get_clean();$k->terminate($r,$s);
 file_put_contents($dc16Root.'/storage/evidence/print/'.$report.'.csv',$bytes);
 $stream=fopen('php://temp','w+');fwrite($stream,$bytes);rewind($stream);$header=fgetcsv($stream);$rows=[];while(($line=fgetcsv($stream))!==false)$rows[]=$line;fclose($stream);
 $check=['http'=>$s->getStatusCode(),'bytes'=>strlen($bytes),'rows'=>count($rows),'header'=>$header,'status'=>$s->getStatusCode()===200&&count($rows)>0?'PARTIAL':'BLOCKED','basis'=>'CSV endpoint executed; report totals require semantic review.'];
 if($report==='trial'&&$s->getStatusCode()===200){$debit=0;$credit=0;foreach($rows as $row){$debit+=(float)($row[6]??0);$credit+=(float)($row[7]??0);}$check['closing_debit']=$debit;$check['closing_credit']=$credit;$check['status']=abs($debit-$credit)<0.005?'PASS':'FAIL';$check['basis']='Exported closing debit and credit independently summed; balanced within 0.005.';}
 $results[$report]=$check;
}
dc16Evidence('accounting-exports',$results);echo "Accounting CSV evidence retained with independent trial sums.\n";
