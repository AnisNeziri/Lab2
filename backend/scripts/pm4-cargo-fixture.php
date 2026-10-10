<?php
// Non-posting, idempotent UI fixture in the completed PM3 SQLite company only.
$db=realpath(__DIR__.'/../storage/app/synthetic/aims-pm3-20261006.sqlite');
if(!$db||!is_file($db.'.report.json'))throw new RuntimeException('Completed PM3 workspace required.');
foreach(['APP_ENV'=>'synthetic','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$db,'DB_URL'=>'','CACHE_STORE'=>'array','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync','BROADCAST_CONNECTION'=>'log','TRACKING_EXTERNAL_ENABLED'=>'false','APP_CONFIG_CACHE'=>__DIR__.'/../storage/app/synthetic/uncached-config.php'] as $key=>$value)putenv($key.'='.$value);
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||realpath(config('database.connections.sqlite.database'))!==$db)throw new RuntimeException('Wrong database.');
Illuminate\Support\Facades\Auth::setUser(App\Models\User::where('email','owner@aims-demo.test')->firstOrFail());
$reference='PM4-CARGO-REVIEW-TEST';
$shipment=App\Models\Shipment::where('tracking_number',$reference)->first();
if(!$shipment){
 $items=App\Models\PurchaseOrderItem::whereHas('purchaseOrder',fn($q)=>$q->whereIn('status',['ordered','partially_received']))->with('purchaseOrder.warehouse','purchaseOrder.supplier','product')->orderByDesc('id')->get()->filter(function($i){$allocated=App\Models\ShipmentItem::where('purchase_order_item_id',$i->id)->sum('quantity');return $i->product && (float)$i->quantity-(float)$allocated>=1;})->unique('product_id')->take(3);
 if($items->count()<2)throw new RuntimeException('At least two existing unallocated PM3 PO products required.');
 $shipment=Illuminate\Support\Facades\DB::transaction(function()use($items,$reference){
  $first=$items->first();$s=App\Models\Shipment::create(['tracking_number'=>$reference,'tracking_provider'=>'synthetic','vessel_name'=>'AIMS PM4 Cargo Review [TEST]','purchase_order_id'=>$first->purchase_order_id,'warehouse_id'=>$first->purchaseOrder->warehouse_id,'supplier_id'=>$first->purchaseOrder->supplier_id,'status'=>'in_transit','transport_mode'=>'sea','origin_port'=>'Shanghai [TEST]','destination_port'=>'Durres [TEST]','departed_at'=>today()->subDays(7),'eta'=>today()->addDays(10),'notes'=>'Non-posting PM4 presentation fixture. Existing PO/inventory/finance records are unchanged. No live AIS position.']);
  return app(App\Services\ShipmentLogisticsService::class)->update($s,['purchase_order_ids'=>$items->pluck('purchase_order_id')->unique()->all(),'items'=>$items->map(function($i){$free=(float)$i->quantity-(float)App\Models\ShipmentItem::where('purchase_order_item_id',$i->id)->sum('quantity');return ['purchase_order_item_id'=>$i->id,'product_id'=>$i->product_id,'description'=>$i->description,'unit'=>$i->unit,'quantity'=>min($free,10),'planned_quantity'=>min($free,10)];})->all()]);
 });
 app(App\Services\ShipmentIntelligenceService::class)->refresh($shipment->id);
}
echo json_encode(['shipment'=>$shipment->id,'reference'=>$reference,'non_posting'=>true]).PHP_EOL;
