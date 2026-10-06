<?php
require __DIR__.'/../../../backend/vendor/autoload.php';
$app=require __DIR__.'/../../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||basename(config('database.connections.sqlite.database'))!=='e2e.sqlite'||!app()->environment('e2e'))throw new RuntimeException('Isolated E2E database required.');
use App\Models\{Company,User,Category,Product,Supplier,ProductSupplier};
use App\Services\{AnalyticsPermissions,EnterpriseDecisionService};
use Illuminate\Support\Facades\{Auth,Hash,DB};
$user=User::where('email','copilot-v9@enterprise.test')->first();
if(($argv[1]??'')==='verify'){
 if(!$user)throw new RuntimeException('Fixture absent.');echo json_encode(['requests'=>DB::table('purchase_requests')->where('company_id',$user->company_id)->count(),'business'=>collect(['products','stock_movements','purchase_orders','journal_entries'])->mapWithKeys(fn($t)=>[$t=>DB::table($t)->where('company_id',$user->company_id)->orderBy('id')->get()->toJson()])->all()]);exit;
}
if($user)throw new RuntimeException('Use a fresh isolated run.');
$company=Company::factory()->create(['name'=>'V9 Copilot Test','base_currency'=>'EUR']);$user=User::factory()->create(['company_id'=>$company->id,'email'=>'copilot-v9@enterprise.test','password'=>Hash::make('password'),'role'=>'admin','is_active'=>true,'email_verified_at'=>now()]);Auth::setUser($user);AnalyticsPermissions::install();
$cat=Category::create(['name'=>'Hardware']);$p=Product::create(['name'=>'Door handles','sku'=>'COPILOT-1','unit'=>'pcs','quantity'=>5,'min_quantity'=>20,'price'=>5,'purchase_price'=>2,'category_id'=>$cat->id]);$s=Supplier::create(['name'=>'Reliable factory','is_active'=>true]);ProductSupplier::create(['product_id'=>$p->id,'supplier_id'=>$s->id,'purchase_price'=>2,'currency'=>'EUR','exchange_rate_to_base'=>1,'usual_lead_time_days'=>3,'pack_size'=>5,'minimum_order_quantity'=>10,'is_active'=>true]);$r=app(EnterpriseDecisionService::class)->refresh($p->id);echo json_encode(['product_id'=>$p->id,'decision_id'=>$r['decisions'][0]['id']]);
