<?php
require __DIR__.'/api.php';
use Illuminate\Support\Facades\{Auth,DB};

$token=dc16Login('primary');
dc16Request('GET','me',[],$token);
dc16Request('PUT','commercial/capabilities',['package'=>'platform','capabilities'=>App\Services\Commercial\Entitlements::CAPABILITIES],$token);
$accounts=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);
$owner=App\Models\User::withoutGlobalScopes()->findOrFail($accounts['primary']['user_id']);
dc16Clock('2025-11-01 07:00:00'); Auth::setUser($owner);
if(DB::table('_dc16_checkpoints')->where('name','setup')->exists()){echo "History setup already committed.\n";exit;}
DB::transaction(function()use($accounts){
    $workforce=app(App\Services\Workforce\WorkforceService::class);
    app(App\Services\Workforce\PayrollService::class)->initialize();
    for($i=1;$i<=20;$i++){
        $e=$workforce->saveEmployee(['employee_number'=>sprintf('DC16-E%03d',$i),'name'=>'Synthetic Employee '.sprintf('%02d',$i),'personal_number'=>'SYNTHETIC-NOT-A-REAL-ID-'.$i,'start_date'=>'2025-11-01','status'=>'active','department'=>['Warehouse','Sales','Purchasing','Finance','Administration'][($i-1)%5],'position'=>'Test Coordinator','payment_method'=>'bank']);
        $workforce->terms($e->id,['effective_from'=>'2025-11-01','contract_type'=>'permanent','position'=>'Test Coordinator','department'=>$e->department,'pay_basis'=>'monthly','amount'=>(string)(650+($i%8)*50),'currency'=>'EUR','proration_policy'=>'calendar_days','employer_classification'=>'primary','pension_treatment'=>'resident_contributor','employee_pension_rate'=>'5','employer_pension_rate'=>'5','reason'=>'Synthetic approved employment terms, not a real employment contract']);
    }
    app(App\Services\InvoiceProfileService::class)->updateForCurrentCompany(['legal_name'=>'Dardania Textile Distribution — SYNTHETIC ONLY','business_registration_number'=>'TEST-NUI-DC16','fiscal_number'=>'TEST-FISCAL-DC16','registered_address'=>'Synthetic Industrial District','municipality'=>'Prishtina','country_code'=>'XK','invoice_prefix'=>'DC16-INV','credit_note_prefix'=>'DC16-CN','is_vat_registered'=>false,'default_payment_terms_days'=>14,'default_language'=>'en']);
    $rule=app(App\Services\AutomationService::class)->save(['name'=>'DC16 Low Stock Review','trigger'=>'inventory.low_stock','priority'=>'high','conditions'=>['all'=>[]],'actions'=>[['type'=>'create_task','title'=>'Review low stock and qualified replenishment evidence','due_days'=>1]]]);
    app(App\Services\AutomationService::class)->toggle($rule,true);
    $products=App\Models\Product::orderBy('id')->get();
    foreach($products as $index=>$p)app(App\Services\ProductService::class)->update($p,['min_quantity'=>10,'reorder_point'=>30,'safety_stock'=>10,'high_stock_threshold'=>500,'description'=>'DC16 synthetic fabric/material; profile '.['regular','seasonal','growth','decline','intermittent'][$index%5]]);
    DB::table('_dc16_checkpoints')->insert(['name'=>'setup','completed_day'=>-1,'state'=>json_encode(['employees'=>20,'products'=>$products->count()]),'saved_at'=>now()]);
});
dc16Evidence('history-setup',['employees'=>20,'payroll_configured'=>true,'invoice_profile'=>'non VAT registered synthetic seller','automation_rule'=>'inventory.low_stock','date'=>'2025-11-01']);
echo "History setup committed through workforce, product and profile authorities.\n";
