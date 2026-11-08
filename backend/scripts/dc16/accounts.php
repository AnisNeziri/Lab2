<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{Auth,DB,Event};
use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;

$captured='';
Event::listen(Illuminate\Mail\Events\MessageSending::class,function($event)use(&$captured){$captured=$event->message->getTextBody()??$event->message->getHtmlBody();});
$observations=[];
function dc16Api(string $method,string $path,array $data=[],?string $token=null):array{
    global $observations;
    Auth::forgetGuards();
    $r=Request::create('/api/'.$path,$method,[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json',...($token?['HTTP_AUTHORIZATION'=>'Bearer '.$token]:[])],json_encode($data));
    $k=app(Kernel::class);$response=$k->handle($r);$body=json_decode($response->getContent(),true)??[];$k->terminate($r,$response);
    $observations[]=['method'=>$method,'path'=>$path,'status'=>$response->getStatusCode()];
    dc16Evidence('registration-requests',$observations);
    if($response->getStatusCode()>=400){dc16Evidence('last-failure',['path'=>$path,'status'=>$response->getStatusCode(),'body'=>$body]);throw new RuntimeException($path.' failed: '.$response->getStatusCode());}
    return $body;
}
$accounts=[];
foreach(['primary'=>'Dardania Textile Distribution','control'=>'DC16 Isolation Control'] as $kind=>$name){
    $email=$kind.'@dc16.example.test';
    $user=App\Models\User::withoutGlobalScopes()->where('email',$email)->first();
    if(!$user){dc16Api('POST','register',['name'=>'DC16 '.ucfirst($kind).' Owner','email'=>$email,'password'=>$dc16['owner_password'],'password_confirmation'=>$dc16['owner_password'],'company_name'=>$name.' — SYNTHETIC ONLY','company_address'=>'Synthetic Industrial District, Prishtina','timezone'=>'Europe/Tirane','currency'=>'EUR','locale'=>'en']);}
    else if(!$user->email_verified_at){dc16Api('POST','verify-email/resend',['email'=>$email]);}
    $user=App\Models\User::withoutGlobalScopes()->where('email',$email)->firstOrFail();
    if(!$user->email_verified_at){if(!preg_match('/token=([a-zA-Z0-9]{64})/',$captured,$match))throw new RuntimeException('Verification message not captured');dc16Api('POST','verify-email',['email'=>$email,'token'=>$match[1]]);}
    $login=dc16Api('POST','login',['email'=>$email,'password'=>$dc16['owner_password']]);
    $accounts[$kind]=['company_id'=>$user->company_id,'user_id'=>$user->id,'email'=>$email,'token'=>$login['access_token']??$login['token']];
    file_put_contents($dc16Root.'/accounts.json',json_encode($accounts,JSON_PRETTY_PRINT));
}
dc16Evidence('accounts',['new_primary_company'=>$accounts['primary']['company_id'],'new_control_company'=>$accounts['control']['company_id'],'normal_registration'=>true,'normal_email_verification'=>true,'mail_delivery'=>'local array transport only','external_delivery'=>false]);
echo "Two new tenants registered, verified and signed in through application APIs.\n";
