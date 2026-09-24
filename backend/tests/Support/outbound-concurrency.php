<?php

// Separate processes verify database locking, not just sequential API requests.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Company,User,Product,Customer,Warehouse,SalesOrder,OutboundAllocation,WarehouseStock};
use App\Services\{OutboundService,StockMovementService};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$connection=config('database.default');
$database=config('database.connections.'.$connection.'.database');
if(!app()->environment('testing')||$connection!=='mysql'||!str_ends_with($database,'_test')){
    fwrite(STDERR,"Requires APP_ENV=testing and a disposable MySQL database ending in _test.\n");exit(2);
}

if(($argv[1]??'')==='reserve'){
    Auth::login(User::findOrFail((int)$argv[2]));
    while(microtime(true)<(float)$argv[4])usleep(1000);
    app(OutboundService::class)->action(SalesOrder::findOrFail((int)$argv[3]),'reserve',['idempotency_key'=>'parallel-reserve-'.$argv[3]]);
    exit(0);
}

(new Database\Seeders\RolePermissionSeeder)->run();
$company=Company::factory()->create();$user=User::factory()->create(['company_id'=>$company->id,'role'=>'admin']);Auth::login($user);
$warehouse=Warehouse::create(['company_id'=>$company->id,'name'=>'Concurrency test','code'=>'CONCURRENT','is_active'=>true,'is_default'=>true]);
$product=Product::create(['company_id'=>$company->id,'default_warehouse_id'=>$warehouse->id,'name'=>'Concurrent stock','sku'=>'CONCURRENT','unit'=>'pcs','quantity'=>0,'price'=>5,'purchase_price'=>2,'selling_price'=>5,'min_quantity'=>0]);
app(StockMovementService::class)->store(['product_id'=>$product->id,'warehouse_id'=>$warehouse->id,'type'=>'in','quantity'=>20,'stock_state'=>'available','reason'=>'Concurrency fixture','movement_code'=>'import_adjustment_in','idempotency_key'=>(string)Str::uuid()]);
$customer=Customer::create(['company_id'=>$company->id,'name'=>'Concurrency buyer']);
$service=app(OutboundService::class);$orders=[];
foreach([1,2] as $i){
    $order=$service->create(['customer_id'=>$customer->id,'order_date'=>today()->toDateString(),'payment_type'=>'cash','idempotency_key'=>(string)Str::uuid(),'items'=>[['product_id'=>$product->id,'unit'=>'pcs','quantity'=>15,'unit_price'=>5]]]);
    $service->action($order,'confirm',['idempotency_key'=>(string)Str::uuid()]);$orders[]=$order;
}
$start=microtime(true)+1;$workers=[];
foreach($orders as $order){$worker=new Process([PHP_BINARY,__FILE__,'reserve',(string)$user->id,(string)$order->id,(string)$start],base_path(),null,null,30);$worker->start();$workers[]=$worker;}
foreach($workers as $worker){$worker->wait();if(!$worker->isSuccessful())throw new RuntimeException($worker->getErrorOutput().$worker->getOutput());}
$quantities=OutboundAllocation::query()->whereIn('sales_order_id',array_map(fn($o)=>$o->id,$orders))->selectRaw('sales_order_id, SUM(quantity) as total')->groupBy('sales_order_id')->pluck('total')->map(fn($q)=>(int)$q)->sort()->values()->all();
if($quantities!==[5,15]||(int)$product->fresh()->quantity!==20||(int)WarehouseStock::where('product_id',$product->id)->sum('reserved_quantity')!==20)throw new RuntimeException('Concurrent reservations did not reconcile.');
foreach($orders as $order)$service->action($order,'reserve',['idempotency_key'=>'parallel-reserve-'.$order->id]);
if((int)WarehouseStock::where('product_id',$product->id)->sum('reserved_quantity')!==20)throw new RuntimeException('Retry duplicated reservations.');
echo "PASS: two real concurrent processes reserve 15 + 5 from 20; physical stock unchanged; retries idempotent.\n";
