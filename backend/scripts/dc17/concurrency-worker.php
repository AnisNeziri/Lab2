<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\Auth;
use App\Models\{User,Product};
$company=(int)($argv[1]??0);$run=$argv[2]??'before';if(!in_array($company,[2,3],true)||!in_array($run,['before','after']))throw new RuntimeException('Invalid worker target');
$account=json_decode(file_get_contents($dc16Root.'/accounts.json'),true)[$company===2?'primary':'control'];Auth::setUser(User::withoutGlobalScopes()->findOrFail($account['user_id']));
file_put_contents($dc17Root.'/evidence/concurrency-'.$run.'-'.$company.'.ready','ready');
$limit=microtime(true)+15;while(!is_file($dc17Root.'/evidence/concurrency-'.$run.'.go')){if(microtime(true)>$limit)throw new RuntimeException('Start barrier timeout');usleep(20000);}
$results=[];
for($i=0;$i<6;$i++){
 $sku='DC17-CON-'.$run.'-'.$company.'-'.$i;$start=microtime(true);
 try{
  $p=Product::where('sku',$sku)->first();
  if(!$p)$p=app(App\Services\ProductService::class)->create(['company_id'=>$company,'name'=>'DC17 short transaction '.$company.'-'.$i,'sku'=>$sku,'unit'=>'pcs','quantity'=>1,'purchase_price'=>2,'selling_price'=>5,'price'=>5,'default_warehouse_id'=>$company===2?3:6]);
  $results[]=['sku'=>$sku,'product_id'=>$p->id,'quantity'=>$p->quantity,'ms'=>round((microtime(true)-$start)*1000,2),'status'=>'PASS'];
 }catch(Throwable $e){$results[]=['sku'=>$sku,'ms'=>round((microtime(true)-$start)*1000,2),'status'=>'FAIL','type'=>get_class($e),'sql_state'=>$e->errorInfo[0]??null,'driver_code'=>$e->errorInfo[1]??null];}
}
dc17Evidence('concurrency-'.$run.'-'.$company,['company'=>$company,'outer_transaction'=>false,'authority'=>'ProductService → StockMovementService → AccountingService','results'=>$results]);
echo 'Company '.$company.': '.count(array_filter($results,fn($r)=>$r['status']==='PASS'))."/6 completed.\n";
if(collect($results)->contains('status','FAIL'))exit(2);
