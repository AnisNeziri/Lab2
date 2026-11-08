<?php
require dirname(__DIR__).'/dc16/bootstrap.php';
$dc17Root='C:/Users/anisi/.codex/recovery/dc17-20261103';
if(!is_dir($dc17Root.'/evidence'))mkdir($dc17Root.'/evidence',0700,true);
set_exception_handler(function(Throwable $e)use($dc17Root){file_put_contents($dc17Root.'/evidence/unhandled.json',json_encode(['type'=>get_class($e),'message'=>$e->getMessage()],JSON_PRETTY_PRINT));fwrite(STDERR,"DC17 stage failed; inspect private evidence.\n");exit(1);});
function dc17Evidence(string $name,array $value):void{global $dc17Root;file_put_contents($dc17Root.'/evidence/'.$name.'.json',json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
function dc17Request(string $method,string $path,array $data=[],?string $token=null):array{
 Illuminate\Support\Facades\Auth::forgetGuards();
 $r=Illuminate\Http\Request::create('/api/'.$path,$method,[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>$token?'Bearer '.$token:''],json_encode($data));
 $k=app(Illuminate\Contracts\Http\Kernel::class);$start=microtime(true);$s=$k->handle($r);$k->terminate($r,$s);
 return ['status'=>$s->getStatusCode(),'ms'=>round((microtime(true)-$start)*1000,2),'retry_after'=>$s->headers->get('Retry-After'),'body'=>json_decode($s->getContent(),true),'telemetry'=>app(App\Services\Platform\PlatformTrace::class)->stats()];
}
function dc17Login(string $role):string{
 global $dc16Root,$dc16;$accounts=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);$a=$accounts[$role];
 $r=dc17Request('POST','login',['email'=>$a['email'],'password'=>$a['password']??$dc16['owner_password']]);
 if($r['status']!==200)throw new RuntimeException('DC17 test login failed');return $r['body']['access_token']??$r['body']['token'];
}
