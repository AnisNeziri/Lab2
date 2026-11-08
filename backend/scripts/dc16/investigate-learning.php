<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\DB;
$a=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);$company=$a['primary']['company_id'];
$q=DB::table('daily_sales')->where('company_id',$company)->where('status','finalized');
$result=['company_id'=>$company,'finalized_sales'=>$q->count(),'missing_finalized_at'=>(clone $q)->whereNull('finalized_at')->count(),'with_inventory_applied_at'=>(clone $q)->whereNotNull('inventory_applied_at')->count(),'samples'=>(clone $q)->whereNull('finalized_at')->orderBy('id')->limit(5)->get(['id','sale_date','status','inventory_applied_at','finalized_at']),'timestamps_rewritten'=>false,'model_evidence_gates_changed'=>false];
dc16Evidence('learning-evidence-investigation',$result);echo json_encode($result)."\n";
