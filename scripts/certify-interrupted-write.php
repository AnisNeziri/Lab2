<?php
// Child process paused after a real authoritative SQL write, before commit.
$root=dirname(__DIR__);$target=$argv[1]??'';$mode=$argv[2]??'';$operation=$argv[3]??'';
$normalized=str_replace('\\','/',realpath(dirname($target))?:'');
if(!str_starts_with($normalized,str_replace('\\','/',$root).'/output/pr1-interruption-')||!is_file($target))throw new RuntimeException('Owned interruption test database required.');
foreach(['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$target,'DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','REDIS_ENABLED'=>'false','BROADCAST_CONNECTION'=>'log','APP_CONFIG_CACHE'=>dirname($target).'/unused.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
require $root.'/backend/vendor/autoload.php';require __DIR__.'/certification-common.php';
$app=require $root.'/backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{Auth,DB};
if(DB::table('_aims_synthetic_manifest')->value('marker')!=='AIMS_PM3_SYNTHETIC_ONLY')throw new RuntimeException('Synthetic ownership missing.');
if($mode==='prepare'){
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    Auth::setUser(App\Models\User::where('email','owner@aims-demo.test')->firstOrFail());
    // Prepare a fresh journal outside the interrupted posting operation.
    $accounts=collect(app(App\Services\AccountingService::class)->accounts())->keyBy('code');
    app(App\Services\AccountingService::class)->createDraft(['posting_date'=>'2026-10-11','description'=>'PR1 interrupted posting fixture','source_key'=>'pr1-interrupted-journal','lines'=>[['accounting_account_id'=>$accounts['1000']->id,'debit'=>1],['accounting_account_id'=>$accounts['3000']->id,'credit'=>1]]]);
    echo json_encode(['prepared'=>true]);exit;
}
if($mode==='snapshot'){
    $rows=[];foreach(['products','warehouse_stock','stock_movements','customers','customer_debt_transactions','financial_account_transactions','journal_entries','journal_lines','purchase_orders','purchase_order_items','goods_receipts','goods_receipt_items'] as $table)$rows[$table]=hash('sha256',DB::table($table)->orderBy('id')->get()->toJson());
    echo json_encode(['records'=>$rows,'integrity'=>DB::selectOne('PRAGMA integrity_check')->integrity_check,'foreign_keys'=>DB::select('PRAGMA foreign_key_check')]);exit;
}
if($mode!=='interrupt')throw new RuntimeException('Invalid test operation.');
Auth::setUser(App\Models\User::where('email','owner@aims-demo.test')->firstOrFail());
$product=App\Models\Product::where('quantity','>',10)->firstOrFail();
$stock=App\Models\WarehouseStock::where('product_id',$product->id)->whereNotNull('location_id')->firstOrFail();
$customer=App\Models\Customer::where('current_debt','>',10)->firstOrFail();
$order=App\Models\PurchaseOrder::whereIn('status',['ordered','partially_received'])->whereHas('items',fn($q)=>$q->whereColumn('quantity','>','received_quantity'))->with('items')->firstOrFail();
$item=$order->items->first(fn($i)=>$i->quantity>$i->received_quantity);
$journal=App\Models\JournalEntry::where('source_key','pr1-interrupted-journal')->firstOrFail();
DB::listen(function($query)use($target){if(DB::transactionLevel()>0&&preg_match('/^\s*(insert|update|delete)\b/i',$query->sql)){file_put_contents($target.'.pending','Authoritative SQL executed inside open transaction.');sleep(120);throw new RuntimeException('Parent did not interrupt the process.');}});
match($operation){
    'stock_movement'=>app(App\Services\StockMovementService::class)->store(['product_id'=>$product->id,'warehouse_id'=>$stock->warehouse_id,'location_id'=>$stock->location_id,'type'=>'in','quantity'=>1,'movement_code'=>'manual_adjustment_in','reason'=>'PR1 interrupted adjustment certification','note'=>'PR1 interruption','idempotency_key'=>'pr1-stock-interrupted']),
    'customer_payment'=>app(App\Services\CustomerDebtService::class)->recordPayment($customer,['amount'=>'1.00','transaction_date'=>'2026-10-11','idempotency_key'=>'pr1-payment-interrupted']),
    'journal_posting'=>app(App\Services\AccountingService::class)->post($journal),
    'goods_receipt'=>app(App\Services\PurchaseOrderService::class)->receive($order,['items'=>[['id'=>$item->id,'quantity'=>1]],'idempotency_key'=>'pr1-receipt-interrupted']),
    default=>throw new RuntimeException('Unknown authoritative operation.'),
};
throw new RuntimeException('No transaction write was interrupted.');
