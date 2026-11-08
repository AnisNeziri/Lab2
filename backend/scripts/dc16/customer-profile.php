<?php
require __DIR__.'/api.php';
$token=dc16Login('primary');dc16Request('GET','me',[],$token);
dc16Clock('2025-11-01 07:30:00');
foreach(App\Models\Customer::orderBy('id')->get() as $c){
    if($c->business_registration_number)continue;
    dc16Request('PUT','customers/'.$c->id,['name'=>$c->name,'business_name'=>$c->business_name,'business_registration_number'=>'TEST-NUI-DC16-'.$c->id,'customer_type'=>'business','billing_address'=>$c->address,'municipality'=>'Prishtina','country_code'=>'XK','payment_terms_days'=>21,'is_active'=>true],$token);
}
echo "Synthetic buyer identities completed through customer API.\n";
