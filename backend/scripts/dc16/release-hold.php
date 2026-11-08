<?php
require __DIR__.'/bootstrap.php';
$report=json_decode(file_get_contents($dc16Root.'/storage/evidence/integrity-latest.json'),true);
if(!$report['reconciliation']['all_reconciled'] || !$report['trial_balance']['balanced'] || !$report['balance_sheet']['balanced'] || $report['inventory_issues'] || $report['warehouse_state_issues'] || $report['issued_document_issues'] || $report['reference_violations'])throw new RuntimeException('Integrity still blocked');
$checkpoint=Illuminate\Support\Facades\DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day');
if($checkpoint+1!==$report['completed_days'])throw new RuntimeException('Verification does not match the durable checkpoint');
if(!is_file($dc16Root.'/storage/evidence/replay-blocked.json'))throw new RuntimeException('No replay hold to release');
rename($dc16Root.'/storage/evidence/replay-blocked.json',$dc16Root.'/storage/evidence/replay-hold-resolved-cash.json');
dc16Evidence('replay-release',['checkpoint'=>$checkpoint,'days'=>$report['completed_days'],'finding'=>'DC16-FIN-001','reason'=>'Existing cash classification fixed and verified without changing source/posted records','financial_controls'=>'PASS','posted_journals'=>$report['posted_journals_checked'],'stock'=>'PASS','references'=>'PASS']);
echo "Replay hold released against matching verified checkpoint.\n";
