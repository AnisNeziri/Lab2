<?php
require __DIR__.'/../../../backend/vendor/autoload.php';
$app=require __DIR__.'/../../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||basename(config('database.connections.sqlite.database'))!=='e2e.sqlite'||!app()->environment('e2e'))throw new RuntimeException('Isolated E2E database required.');
use App\Models\{Company,User,Category,Product,Supplier,ProductSupplier,AnalyticsSnapshot};
use Illuminate\Support\Facades\{Auth,Hash,DB};
$user=User::where('email','optimizer-v11@enterprise.test')->first();
if(($argv[1]??'')==='work'){\Illuminate\Support\Facades\Artisan::call('supply-optimizer:work',['--once'=>true]);echo \Illuminate\Support\Facades\Artisan::output();exit;}
if(($argv[1]??'')==='verify'){
 if(!$user)throw new RuntimeException('Fixture absent.');Auth::setUser($user);echo json_encode(['purchase_requests'=>DB::table('purchase_requests')->where('company_id',$user->company_id)->count(),'transfers'=>DB::table('stock_transfers')->where('company_id',$user->company_id)->count(),'business'=>collect(['products','stock_movements','purchase_orders','journal_entries','financial_account_transactions'])->mapWithKeys(fn($t)=>[$t=>DB::table($t)->where('company_id',$user->company_id)->orderBy('id')->get()->toJson()])->all()]);exit;
}
if($user)throw new RuntimeException('Use a fresh isolated run.');
$company=Company::factory()->create(['name'=>'V11 Optimizer Test','base_currency'=>'EUR']);$user=User::factory()->create(['company_id'=>$company->id,'email'=>'optimizer-v11@enterprise.test','password'=>Hash::make('password'),'role'=>'admin','is_active'=>true,'email_verified_at'=>now()]);Auth::setUser($user);\App\Services\AnalyticsPermissions::install();
$cat=Category::create(['name'=>'Hardware']);$ids=[];
for($n=1;$n<=3;$n++){$p=Product::create(['name'=>'Door handles '.$n,'sku'=>'OPT-'.$n,'unit'=>'pcs','quantity'=>50,'min_quantity'=>20,'price'=>5,'purchase_price'=>2,'category_id'=>$cat->id]);$p->forceFill(['created_at'=>now()->subYear()])->save();$s=Supplier::create(['name'=>'Factory '.$n,'is_active'=>true]);ProductSupplier::create(['product_id'=>$p->id,'supplier_id'=>$s->id,'purchase_price'=>2,'currency'=>'EUR','exchange_rate_to_base'=>1,'usual_lead_time_days'=>3,'pack_size'=>5,'minimum_order_quantity'=>10,'is_active'=>true]);for($d=1;$d<=90;$d++)AnalyticsSnapshot::create(['entity_type'=>'demand_observation','entity_id'=>$p->id,'warehouse_id'=>0,'snapshot_date'=>today()->subDays($d),'observed_at'=>today()->subDays($d)->endOfDay(),'feature_version'=>'observed-v2','facts'=>['unit'=>'pcs','demand'=>10,'sales_quantity'=>10,'returns_quantity'=>0,'available'=>100,'stockout'=>false,'quality'=>'observed','complete'=>true,'potentially_censored'=>false]]);$ids[]=$p->id;}
echo json_encode(['product_ids'=>$ids]);
