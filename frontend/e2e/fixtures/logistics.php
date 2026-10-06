<?php
// This fixture refuses any production database. It is invoked only by Playwright.
require __DIR__.'/../../../backend/vendor/autoload.php';
$app=require __DIR__.'/../../../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||basename(config('database.connections.sqlite.database'))!=='e2e.sqlite'||!app()->environment('e2e'))throw new RuntimeException('Isolated E2E database required.');
$user=App\Models\User::where('email','admin@enterprise.com')->firstOrFail();Illuminate\Support\Facades\Auth::setUser($user);
$category=App\Models\Category::firstOrFail();$supplier=App\Models\Supplier::firstOrFail();
$product=App\Models\Product::create(['name'=>'E2E Milano V6','sku'=>'E2E-FAB-V6','category_id'=>$category->id,'supplier_id'=>$supplier->id,'unit'=>'m','quantity'=>60,'min_quantity'=>20,'purchase_price'=>2,'price'=>5]);
$product->forceFill(['created_at'=>today()->subYear()])->save();
App\Models\ProductSupplier::updateOrCreate(['product_id'=>$product->id,'supplier_id'=>$supplier->id],['purchase_price'=>2,'currency'=>'EUR','usual_lead_time_days'=>10,'is_active'=>true]);
$warehouse=App\Models\Warehouse::create(['name'=>'E2E Inland','code'=>'E2E-INLAND','is_active'=>true]);
App\Models\WarehouseStock::create(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'quantity'=>60,'available_quantity'=>40,'reserved_quantity'=>20]);
App\Models\InventoryPlanningPolicy::create(['product_id'=>$product->id,'warehouse_id'=>$warehouse->id,'scope_key'=>$warehouse->id,'version'=>'e2e-v6','settings'=>['allocation_share'=>1]]);
for($d=1;$d<=90;$d++)App\Models\AnalyticsSnapshot::create(['entity_type'=>'demand_observation','entity_id'=>$product->id,'warehouse_id'=>0,'snapshot_date'=>today()->subDays($d),'observed_at'=>today()->subDays($d)->endOfDay(),'feature_version'=>'e2e-test-observation','facts'=>['unit'=>'m','demand'=>20,'sales_quantity'=>20,'returns_quantity'=>0,'available'=>100,'complete'=>true,'stockout'=>false,'potentially_censored'=>false]]);
$po=App\Models\PurchaseOrder::create(['supplier_id'=>$supplier->id,'warehouse_id'=>$warehouse->id,'po_number'=>'E2E-V6-PO','status'=>'ordered','ordered_at'=>today()->subDays(25),'expected_at'=>today()->addDays(2),'total_amount'=>20000,'currency'=>'EUR','exchange_rate'=>1]);
$item=$po->items()->create(['product_id'=>$product->id,'description'=>$product->name,'unit'=>'m','inventory_unit'=>'m','quantity'=>10000,'base_quantity'=>10000,'unit_price'=>2,'line_total'=>20000]);
$shipment=App\Models\Shipment::create(['tracking_number'=>'E2E-SH-V6','purchase_order_id'=>$po->id,'warehouse_id'=>$warehouse->id,'supplier_id'=>$supplier->id,'origin_port'=>'Shanghai','destination_port'=>'Durrës','transport_mode'=>'sea','departed_at'=>today()->subDays(20),'eta'=>today()->addDays(5),'tracking_provider'=>'aisstream','status'=>'in_transit','mmsi'=>'123456789','current_lat'=>20,'current_lng'=>30,'position_updated_at'=>now()->subDays(2)]);
$shipment->items()->create(['purchase_order_item_id'=>$item->id,'product_id'=>$product->id,'description'=>$product->name,'unit'=>'m','quantity'=>10000,'base_quantity'=>10000]);
foreach(['warehouse_arrival'=>['planned_at'=>today()->addDays(2),'estimated_at'=>today()->addDays(10)],'destination_port'=>['planned_at'=>today()->subDays(2)]] as $type=>$dates)$shipment->milestones()->create(['scope_key'=>'shipment','milestone_type'=>$type,'status'=>'planned','source'=>'manual']+$dates);
echo json_encode(['shipment_id'=>$shipment->id]);
