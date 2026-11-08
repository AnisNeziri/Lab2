<?php
require __DIR__.'/api.php';

use App\Models\OnboardingSession;
use App\Services\Onboarding\OnboardingSchema;
use Illuminate\Http\UploadedFile;

$owner = dc16Login('primary');
$reviewer = dc16Login('accountant');
$accounts = json_decode(file_get_contents($dc16Root.'/accounts.json'), true);
$companyId = $accounts['primary']['company_id'];
dc16Clock('2025-11-01 06:00:00');
$rows = array_fill_keys(array_keys(OnboardingSchema::DOMAINS), []);
$cities = ['Prishtina','Ferizaj','Prizren','Peja'];
foreach ($cities as $i => $city) {
    $w = 'W'.($i+1);
    $rows['warehouses'][] = [$w, 'DC16-'.$w, $city.' synthetic distribution', 'Test industrial district'];
    $rows['locations'][] = [$w.'Z',$w,'ZONE-A','Zone A','zone',''];
    $rows['locations'][] = [$w.'R',$w,'RACK-A','Rack A','rack',$w.'Z'];
    $rows['locations'][] = [$w.'S',$w,'SHELF-A','Shelf A','shelf',$w.'R'];
    for ($b=1; $b<=3; $b++) $rows['locations'][] = [$w.'B'.$b, $w, 'A0'.$b, 'Aisle A / bin '.$b, 'bin', $w.'S'];
}
$inventoryCents = 0; $units = [];
for ($i=1; $i<=300; $i++) {
    $ref = sprintf('P%03d', $i); $w = 'W'.(1+($i-1)%4); $bin = $w.'B'.(1+($i-1)%3);
    $unit = $i<=240 ? 'm' : ($i<=285 ? 'pcs' : 'kg');
    $costCents = 150 + ($i*37 % 1350);
    $qty = $unit==='m' ? 100 + ($i%5)*25 : ($unit==='pcs' ? 80 : 20);
    $name = $i<=240 ? ['Milano','Verona','Oslo','Linen','Velvet','Oxford'][intdiv($i-1,40)].' '.sprintf('%02d',1+($i-1)%40) : ($i<=285 ? 'Furniture fitting '.$i : 'Adhesive '.$i);
    $rows['products'][] = [$ref,'DC16-'.$ref,$name,$unit,'DC16BC'.$i,$i<=240?'Upholstery fabrics':($i<=285?'Furniture fittings':'Consumables'),number_format($costCents/100,2,'.',''),number_format(round($costCents*1.65)/100,2,'.',''),'','','none'];
    $valueCents = $qty*$costCents; $inventoryCents += $valueCents; $units[$unit] = ($units[$unit]??0)+$qty;
    $rows['inventory'][] = ['I'.$ref,$ref,$w,$bin,(string)$qty,$unit,number_format($costCents/100,6,'.',''),number_format($valueCents/100,2,'.',''),'available','Synthetic signed count 2025-11-01 / '.$ref];
}
for ($i=1; $i<=120; $i++) $rows['customers'][] = [sprintf('C%03d',$i),'Synthetic Furniture '.sprintf('%03d',$i),'Synthetic Furniture '.sprintf('%03d',$i),'customer'.$i.'@dc16.example.test','','',$cities[($i-1)%4].' test address'];
for ($i=1; $i<=25; $i++) $rows['suppliers'][] = [sprintf('S%03d',$i),'Synthetic Textile Supplier '.sprintf('%02d',$i),'supplier'.$i.'@dc16.example.test','','Synthetic '.['Istanbul','Bursa','Guangzhou','Milan','Prishtina'][($i-1)%5].' source'];
for ($i=1; $i<=10; $i++) $rows['receivables'][] = ['AR'.$i,sprintf('C%03d',$i),(string)($i*100),'EUR','Synthetic customer opening statement '.$i,'2025-11-'.sprintf('%02d',5+$i)];
$rows['customer_advances'][] = ['CA1','C011','500.00','EUR','Synthetic prepaid customer statement'];
$rows['payables'][] = ['AP1','S001','10000.00','EUR','Synthetic supplier opening statement','2025-11-25'];
$rows['supplier_advances'][] = ['SA1','S002','6000.00','EUR','Synthetic prepaid supplier statement'];
$rows['cash_bank'][] = ['B1','DC16 Operating Bank','bank','EUR','500000.00','Synthetic opening bank statement'];
$rows['cash_bank'][] = ['B2','DC16 Cash Register','cashbox','EUR','5000.00','Synthetic opening cash count'];
$totals = ['inventory_value'=>number_format($inventoryCents/100,2,'.',''),'receivables'=>'5500.00','customer_advances'=>'500.00','payables'=>'10000.00','supplier_advances'=>'6000.00','cash'=>'5000.00','bank'=>'500000.00','equity'=>number_format(($inventoryCents+550000+600000+500000+50000000-1000000-50000)/100,2,'.',''),'inventory_units'=>array_map(fn($v)=>(string)$v,$units)];
$hashes = [];
foreach ($rows as $domain => $records) {
    $path = $dc16Root.'/storage/sources/'.$domain.'.csv';
    $h = fopen($path,'w'); fputcsv($h,OnboardingSchema::DOMAINS[$domain]); foreach ($records as $record) fputcsv($h,$record); fclose($h);
    $hashes[$domain] = hash_file('sha256',$path);
}
dc16Evidence('opening-source-manifest',['seed'=>20261102,'synthetic_only'=>true,'cutoff'=>'2025-10-31','counts'=>array_map('count',$rows),'totals'=>$totals,'sha256'=>$hashes]);
$settings=['name'=>'DC16 documented synthetic opening','source_period'=>'Synthetic closing statements 2025-10-31','cutover_date'=>'2025-10-31','currency'=>'EUR','timezone'=>'Europe/Tirane','expected_totals'=>$totals,'separate_reviewer_required'=>true];
$s = OnboardingSession::withoutGlobalScopes()->where('company_id',$companyId)->first();
if (!$s) {
    $response = dc16Request('POST','onboarding',$settings,$owner);
    $s = OnboardingSession::withoutGlobalScopes()->findOrFail($response['session']['id']);
}
$id = $s->id;
if (!$s->posting_started_at && $s->cutover_date->toDateString()!==$settings['cutover_date']) {
    dc16Request('PUT',"onboarding/{$id}",$settings+['revision'=>$s->revision],$owner);
    $s->refresh();
}
if (!$s->posting_started_at && !$s->approved_at) {
    foreach ($rows as $domain=>$records) {
        $s->refresh();
        if ($s->batches()->where('domain',$domain)->where('checksum',$hashes[$domain])->where('status','staged')->exists()) continue;
        foreach ($s->batches()->where('domain',$domain)->where('is_active',true)->where('checksum','!=',$hashes[$domain])->get() as $old) {
            dc16Request('POST',"onboarding/{$id}/batches/{$old->id}/exclude",['revision'=>$s->fresh()->revision,'reason'=>'Test harness source corrected to supported cashbox and location hierarchy; previous source retained.'],$owner);
        }
        $s->refresh();
        dc16Request('POST',"onboarding/{$id}/sources",['domain'=>$domain,'revision'=>$s->revision],$owner,['file'=>new UploadedFile($dc16Root.'/storage/sources/'.$domain.'.csv',$domain.'.csv','text/csv',null,true)]);
        echo "Staged {$domain}: ".count($records)." source rows.\n";
    }
    $s->refresh();
    $validation=dc16Request('POST',"onboarding/{$id}/validate",['revision'=>$s->revision],$owner);
    // Same rack/bin names in distinct warehouses are intentional. Resolve through
    // the normal explicit review action, retaining the source candidate evidence.
    $locationReviews=$s->rows()->active()->where('domain','locations')->where('status','review')->get();
    if ($locationReviews->isNotEmpty()) {
        dc16Request('PATCH',"onboarding/{$id}/rows",['revision'=>$s->fresh()->revision,'rows'=>$locationReviews->map(fn($row)=>['id'=>$row->id,'action'=>'create','reason'=>'Reviewed synthetic location: separate warehouse '.$row->normalized['warehouse_ref'].'; shared rack names do not identify the same physical location.'])->all()],$owner);
        $validation=dc16Request('POST',"onboarding/{$id}/validate",['revision'=>$s->fresh()->revision],$owner);
    }
    dc16Evidence('opening-validation',$validation);
    if ($validation['reconciliation']['blocking']??[]) throw new RuntimeException('Opening source validation blocked; inspect evidence');
    $s->refresh();
    $pre=$s->pre_backup_id ? $s->backups()->find($s->pre_backup_id) : null;
    if (!$pre || ($pre->verification['revision']??-1)!==$s->revision) dc16Request('POST',"onboarding/{$id}/backups",['revision'=>$s->revision,'kind'=>'pre','passphrase'=>$dc16['password']],$owner);
    $s->refresh();
    if (!$s->reviewed_at) dc16Request('POST',"onboarding/{$id}/review",['revision'=>$s->revision,'source_totals_confirmed'=>true,'warnings_acknowledged'=>true],$reviewer);
    $s->refresh();
    if (!$s->approved_at) dc16Request('POST',"onboarding/{$id}/approve",['revision'=>$s->revision,'fingerprint'=>$s->review_fingerprint,'confirmed'=>true],$owner);
}
$s->refresh();
$payload=['revision'=>$s->revision,'fingerprint'=>$s->review_fingerprint,'confirmed'=>true];
for ($batch=0; $batch<40 && !$s->posted_at; $batch++) {
    $result=dc16Request('POST',"onboarding/{$id}/post",$payload+['limit'=>50],$owner);
    dc16Evidence('opening-progress',$result);
    if ($result['failure']??null) throw new RuntimeException('Opening posting stopped safely; inspect evidence');
    $s->refresh(); echo "Opening batch {$batch}, status {$s->status}.\n";
}
if (!$s->posted_at) throw new RuntimeException('Bounded run ended; resume opening.php');
if ($s->status==='posted') dc16Evidence('opening-verified',dc16Request('POST',"onboarding/{$id}/verify",[],$owner));
$s->refresh();
if ($s->status==='verified' && !$s->post_backup_id) dc16Request('POST',"onboarding/{$id}/backups",$payload+['kind'=>'post','passphrase'=>$dc16['password']],$owner);
$s->refresh();
if ($s->status==='verified' && !($s->restore_verification['passed']??false)) {
    $restore=dc16Request('POST',"onboarding/{$id}/restore-verify",['passphrase'=>$dc16['password']],$owner);
    dc16Evidence('opening-restore',$restore);
    if (!($restore['passed']??false)) throw new RuntimeException('Independent opening restore blocked; no lock bypass');
}
$s->refresh();
if ($s->status!=='locked') dc16Request('POST',"onboarding/{$id}/lock",$payload,$owner);
dc16Evidence('opening-final',['session'=>$id,'company'=>$companyId,'status'=>$s->fresh()->status,'reviewer'=>$s->reviewed_by,'approver'=>$s->approved_by,'expected_totals'=>$totals]);
echo "Opening complete through existing authorities.\n";
