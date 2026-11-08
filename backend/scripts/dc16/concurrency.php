<?php
// Actual independent MariaDB workers, restricted to the registered control tenant.
require __DIR__.'/api.php';
use App\Models\{User,Product,Customer,Warehouse,SalesOrder,PurchaseOrder,GoodsReceipt,WarehouseStock};
use App\Services\{OutboundService,PurchaseOrderService};
use Illuminate\Support\Facades\{Auth,DB};
use Symfony\Component\Process\Process;

$accounts=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);
$control=$accounts['control'];
function dc16ControlKey(string $purpose): string { $h=hash('sha256','DC16-CONTROL:'.$purpose);return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12); }
if(($argv[1]??'')==='worker'){
    $user=User::withoutGlobalScopes()->findOrFail($control['user_id']);
    if($user->company_id!==$control['company_id'])throw new RuntimeException('Worker tenant mismatch');
    Auth::setUser($user);$kind=$argv[2];$id=(int)$argv[3];$key=$argv[4];
    while(microtime(true)<(float)$argv[5])usleep(1000);
    try{
        if($kind==='reserve')app(OutboundService::class)->action(SalesOrder::findOrFail($id),'reserve',['idempotency_key'=>$key]);
        elseif($kind==='receive'){
            $po=PurchaseOrder::with('items')->findOrFail($id);
            app(PurchaseOrderService::class)->receive($po,['received_at'=>today()->toDateString(),'items'=>[['id'=>$po->items->first()->id,'quantity'=>6]],'idempotency_key'=>$key]);
        }elseif($kind==='dispatch'){
            $o=SalesOrder::with('packages')->findOrFail($id);app(OutboundService::class)->action($o,'dispatch',['package_ids'=>$o->packages->pluck('id')->all(),'idempotency_key'=>$key]);
        }elseif($kind==='automation')app(App\Services\AutomationService::class)->consume(App\Models\BusinessEvent::findOrFail($id));
        else throw new RuntimeException('Unregistered worker operation');
        echo 'accepted';
    }catch(Illuminate\Validation\ValidationException $e){echo 'rejected';}
    exit;
}

if(is_file($dc16Root.'/storage/evidence/control-concurrency.json')){echo "Existing concurrency evidence retained; no regeneration.\n";exit;}
$historyDay=DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day');
if($historyDay<364)throw new RuntimeException('Run the concurrency fixture after chronological generation; preserve the recorded cross-company initialization deadlock for separate review.');
$token=dc16Login('control');dc16Request('GET','me',[],$token);
Auth::setUser(User::withoutGlobalScopes()->findOrFail($control['user_id']));
function dc16Race(string $kind,array $ids,array $keys): array
{
    $barrier=microtime(true)+2;$processes=[];
    foreach($ids as $i=>$id){$p=new Process([PHP_BINARY,__FILE__,'worker',$kind,(string)$id,$keys[$i],(string)$barrier],base_path(),null,null,40);$p->start();$processes[]=$p;}
    $out=[];foreach($processes as $p){$p->wait();if(!$p->isSuccessful())throw new RuntimeException('Control worker failed: '.$p->getErrorOutput());$out[]=trim($p->getOutput());}sort($out);return $out;
}
$fixtureFile=$dc16Root.'/storage/evidence/control-concurrency-fixture.json';
if(is_file($fixtureFile)){
    if(($argv[1]??'')!=='resume-after-description-fix')throw new RuntimeException('Inspect incomplete fixture before explicit resume.');
    $f=json_decode(file_get_contents($fixtureFile),true);
    if($f['company_id']!==$control['company_id'] || PurchaseOrder::count() || GoodsReceipt::count())throw new RuntimeException('Unexpected resume state');
    $warehouse=['id'=>$f['warehouse_id']];$product=['id'=>$f['product_id']];$customer=['id'=>$f['customer_id']];$supplier=['id'=>$f['supplier_id']];
}else{
$warehouse=DB::table('warehouses')->where('company_id',$control['company_id'])->where('code','DC16-RACE')->first();
$warehouse=$warehouse?(array)$warehouse:dc16Request('POST','warehouses',['name'=>'DC16 Control Concurrency Warehouse','code'=>'DC16-RACE','is_default'=>true],$token);
$category=DB::table('categories')->where('company_id',$control['company_id'])->where('name','DC16 Control Test Materials')->first();
$category=$category?(array)$category:dc16Request('POST','categories',['name'=>'DC16 Control Test Materials'],$token);
$product=dc16Request('POST','products',['name'=>'DC16 Control Fabric','sku'=>'CONTROL-RACE','category_id'=>$category['id'],'default_warehouse_id'=>$warehouse['id'],'unit'=>'pcs','quantity'=>50,'min_quantity'=>20,'purchase_price'=>2,'selling_price'=>5,'price'=>5],$token);
$customer=dc16Request('POST','customers',['name'=>'DC16 Control Buyer','is_active'=>true],$token);
$supplier=dc16Request('POST','suppliers',['name'=>'DC16 Control Supplier'],$token);
$f=['company_id'=>$control['company_id'],'warehouse_id'=>$warehouse['id'],'product_id'=>$product['id'],'customer_id'=>$customer['id'],'supplier_id'=>$supplier['id']];
dc16Evidence('control-concurrency-fixture',$f);
}
Auth::setUser(User::withoutGlobalScopes()->findOrFail($control['user_id']));
$out=app(OutboundService::class);$orders=[];
foreach([0,1] as $i){$o=$out->create(['customer_id'=>$customer['id'],'warehouse_id'=>$warehouse['id'],'order_date'=>today()->toDateString(),'currency'=>'EUR','payment_type'=>'credit','items'=>[['product_id'=>$product['id'],'quantity'=>40,'unit'=>'pcs','unit_price'=>5]],'idempotency_key'=>dc16ControlKey('order-'.$i)]);$out->action($o,'confirm',['idempotency_key'=>dc16ControlKey('confirm-'.$i)]);$orders[]=$o->id;}
$reserve=dc16Race('reserve',$orders,[dc16ControlKey('reserve-0'),dc16ControlKey('reserve-1')]);
$stocks=WarehouseStock::where('product_id',$product['id'])->get();
if((float)$stocks->sum('quantity')!==50.0 || (float)$stocks->sum('reserved_quantity')!==50.0 || (float)$stocks->sum('available_quantity')!==0.0)throw new RuntimeException('Concurrent reservation quantity invariant failed');
$results=['reservation'=>['workers'=>$reserve,'physical'=>50,'reserved'=>50,'available'=>0,'status'=>'PASS']];
foreach(['same-key','different-keys'] as $mode){
    $po=app(PurchaseOrderService::class)->create(['supplier_id'=>$supplier['id'],'warehouse_id'=>$warehouse['id'],'status'=>'ordered','currency'=>'EUR','exchange_rate'=>1,'ordered_at'=>today()->toDateString(),'items'=>[['product_id'=>$product['id'],'description'=>'DC16 Control Fabric','quantity'=>10,'unit'=>'pcs','unit_price'=>2]],'notes'=>'DC16 isolated concurrent receipt '.$mode]);
    $keys=[dc16ControlKey($mode.'-receipt-a'),dc16ControlKey($mode==='same-key'?$mode.'-receipt-a':$mode.'-receipt-b')];
    $result=dc16Race('receive',[$po->id,$po->id],$keys);
    $expected=$mode==='same-key'?['accepted','accepted']:['accepted','rejected'];
    if($result!==$expected || GoodsReceipt::where('purchase_order_id',$po->id)->count()!==1 || (float)$po->items()->sum('received_quantity')!==6.0)throw new RuntimeException('Concurrent receipt ownership failed: '.$mode);
    $results[$mode]=['workers'=>$result,'receipts'=>1,'received_quantity'=>6,'status'=>'PASS'];
}
// Whichever order won the first reservation can dispatch its reserved quantity.
$order=SalesOrder::whereIn('id',$orders)->whereHas('items',fn($q)=>$q->where('reserved_quantity',40))->firstOrFail();
$out->action($order,'allocate',['idempotency_key'=>dc16ControlKey('allocate')]);$order=$order->fresh('allocations');
foreach($order->allocations as $a)$out->action($order,'pick',['allocation_id'=>$a->id,'quantity'=>$a->quantity,'manual_verification'=>true,'reason'=>'DC16 controlled concurrent dispatch verification','idempotency_key'=>dc16ControlKey('pick-'.$a->id)]);
$order=$order->fresh('allocations');$out->action($order,'pack',['items'=>$order->allocations->map(fn($a)=>['allocation_id'=>$a->id,'quantity'=>$a->picked_quantity])->all(),'idempotency_key'=>dc16ControlKey('pack')]);
$dispatch=dc16Race('dispatch',[$order->id,$order->id],[dc16ControlKey('dispatch'),dc16ControlKey('dispatch')]);
if($order->dispatches()->count()!==1 || (float)Product::findOrFail($product['id'])->quantity!==22.0)throw new RuntimeException('Concurrent dispatch duplicated quantity effect');
$results['dispatch']=['workers'=>$dispatch,'dispatches'=>1,'physical'=>22,'status'=>'PASS'];
$rule=app(App\Services\AutomationService::class)->save(['name'=>'DC16 Control Concurrent Low Stock','trigger'=>'inventory.low_stock','priority'=>'high','conditions'=>['all'=>[]],'actions'=>[['type'=>'create_task','title'=>'DC16 control stock review','assigned_team'=>'purchasing']]]);
app(App\Services\AutomationService::class)->toggle($rule,true);
$event=app(App\Services\BusinessEventService::class)->record('inventory.low_stock',Product::findOrFail($product['id']),'DC16 control stock',['available'=>12,'minimum'=>20,'shortage'=>8],dc16ControlKey('low-stock-event'));
$automation=dc16Race('automation',[$event->id,$event->id],[dc16ControlKey('automation'),dc16ControlKey('automation')]);
if(App\Models\OperationalTask::where('automation_id',$rule->id)->count()!==1 || App\Models\AutomationExecution::where('automation_id',$rule->id)->count()!==1)throw new RuntimeException('Automation workers duplicated one issue');
$results['automation']=['workers'=>$automation,'tasks'=>1,'executions'=>1,'status'=>'PASS'];
dc16Evidence('control-concurrency',['company_id'=>$control['company_id'],'database'=>DB::getDatabaseName(),'engine'=>DB::selectOne('SELECT VERSION() v')->v,'actual_independent_processes'=>true,'results'=>$results,'primary_company_modified'=>false]);
echo "PASS: reservation, receipt retry/over-receipt, dispatch and automation races on the separate registered control tenant.\n";
