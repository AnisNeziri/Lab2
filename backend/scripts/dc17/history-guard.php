<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
$path=$dc17Root.'/evidence/history-fingerprint.json';$existing=is_file($path)?json_decode(file_get_contents($path),true):null;$tables=[];
foreach(['journal_entries','journal_lines','stock_movements','daily_sales','invoices','invoice_items','payroll_runs','payroll_lines'] as $table){
 $max=$existing['tables'][$table]['max_id']??DB::table($table)->max('id');$hash=hash_init('sha256');$count=0;
 foreach(DB::table($table)->where('id','<=',$max)->orderBy('id')->cursor() as $row){hash_update($hash,json_encode($row));$count++;}
 $tables[$table]=['max_id'=>$max,'rows'=>$count,'sha256'=>hash_final($hash)];
}
if(!$existing){dc17Evidence('history-fingerprint',['tables'=>$tables]);echo "Protected history fingerprint recorded.\n";}
else{$same=$tables===$existing['tables'];dc17Evidence('history-preservation',['unchanged'=>$same,'tables'=>$tables]);echo $same?"Protected history unchanged.\n":"FAIL: protected history changed.\n";if(!$same)exit(2);}
