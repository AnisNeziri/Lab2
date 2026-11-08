<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\{Auth,DB};
$control=dc16Login('control');dc16Request('GET','me',[],$control);
dc16Request('PUT','commercial/capabilities',['package'=>'platform','capabilities'=>App\Services\Commercial\Entitlements::CAPABILITIES],$control);
$accounts=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);
$primary=$accounts['primary']['company_id'];$other=$accounts['control']['company_id'];
if($primary===$other)throw new RuntimeException('Control tenant is not separate');
$targets=[
    ['products','product'],['customers','customer'],['suppliers','supplier'],['purchase_orders','purchase-order'],['sales_orders','sales-order'],['invoices','invoice'],['journal_entries','journal'],['payroll_runs','payroll'],['financial_accounts','financial-account'],['shipments','shipment'],
];
$paths=['product'=>'products/','customer'=>'customers/','supplier'=>'suppliers/','purchase-order'=>'purchase-orders/','sales-order'=>'sales-orders/','invoice'=>'invoices/','journal'=>'accounting/journals/','payroll'=>'payroll/','financial-account'=>'finance/accounts/','shipment'=>'shipments/'];
$observations=[];
foreach($targets as [$table,$kind]){
    $id=DB::table($table)->where('company_id',$primary)->value('id');
    if(!$id){$observations[]=['kind'=>$kind,'status'=>'NOT TESTED','reason'=>'No populated source record yet'];continue;}
    $path=$paths[$kind].$id.($kind==='financial-account'?'/transactions':'');
    dc16Request('GET',$path,[],$control,[],[404]);
    $observations[]=['kind'=>$kind,'primary_id'=>$id,'status'=>'PASS','assertion'=>'Foreign record not found for fully entitled second-tenant owner'];
}
$customer=DB::table('customers')->where('company_id',$primary)->first(['id','name']);
dc16Request('PUT','customers/'.$customer->id,['name'=>'Unauthorized cross-company mutation'],$control,[],[404]);
if(DB::table('customers')->where('id',$customer->id)->value('name')!==$customer->name)throw new RuntimeException('Cross-company master was mutated');
$search=dc16Request('GET','search?q=Milano',[],$control);
$serialized=json_encode($search);
if(str_contains($serialized,'DC16-P')||str_contains($serialized,'Milano'))throw new RuntimeException('Control search returned primary fixture data');
dc16Evidence('tenant-isolation',['primary_company'=>$primary,'control_company'=>$other,'control_all_capabilities_enabled'=>true,'foreign_reads'=>$observations,'foreign_customer_update'=>'DENIED 404 / name unchanged','cross_tenant_global_search'=>'No primary fixture result','documents'=>'NOT TESTED — document fixtures pending','role_matrix'=>'NOT TESTED — this checks two entitled owners, not every business role']);
echo 'Second-tenant owner: '.count(array_filter($observations,fn($r)=>$r['status']==='PASS'))." foreign-record reads denied; foreign update and search isolated.\n";
