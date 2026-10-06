<?php
require __DIR__.'/../../../backend/vendor/autoload.php';
$app=require __DIR__.'/../../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||basename(config('database.connections.sqlite.database'))!=='e2e.sqlite'||!app()->environment('e2e'))throw new RuntimeException('Isolated E2E database required.');
use App\Models\{Company,User,Customer,Category,Product,DailySale,CustomerDebtTransaction};
use Illuminate\Support\Facades\{Auth,DB,Hash};
use App\Services\AnalyticsPermissions;
$user=User::where('email','customer-v8@enterprise.test')->first();
if(($argv[1]??'')==='verify'){
 if(!$user)throw new RuntimeException('Test company missing.');Auth::setUser($user);echo json_encode(['business'=>collect(['products','stock_movements','customer_debt_transactions','journal_entries'])->mapWithKeys(fn($t)=>[$t=>DB::table($t)->where('company_id',$user->company_id)->orderBy('id')->get()->toJson()])->all(),'orders'=>DB::table('sales_orders')->where('company_id',$user->company_id)->count()]);exit;
}
if($user)throw new RuntimeException('Use a fresh isolated E2E run.');
$company=Company::factory()->create(['name'=>'V8 Wholesale Test','base_currency'=>'EUR']);$user=User::factory()->create(['name'=>'Sales Test Admin','email'=>'customer-v8@enterprise.test','password'=>Hash::make('password'),'role'=>'admin','company_id'=>$company->id,'is_active'=>true,'email_verified_at'=>now()]);Auth::setUser($user);AnalyticsPermissions::install();
$cat=Category::create(['name'=>'Upholstery']);$make=fn($name,$qty)=>Product::create(['name'=>$name,'sku'=>'V8-'.Product::count(),'category_id'=>$cat->id,'unit'=>'m','quantity'=>$qty,'price'=>3,'selling_price'=>3,'purchase_price'=>2,'min_quantity'=>50]);$a=$make('Fabric A',10000);$b=$make('Related Fabric B',15);$other=$make('Unrelated Fabric C',1000);$c=Customer::create(['name'=>'Furniture Company A','current_debt'=>900]);
$sale=function($customer,$days,$items){$s=DailySale::create(['sale_number'=>'V8-'.DailySale::count(),'customer_id'=>$customer?->id,'customer_name'=>$customer?->name,'sale_date'=>today()->subDays($days),'status'=>'finalized','inventory_applied_at'=>today()->subDays($days)->addHours(12),'total_amount'=>6000,'total_quantity'=>2000,'paid_amount'=>6000,'created_by'=>Auth::id()]);foreach($items as $i=>[$p,$quantity])$s->items()->create(['line_number'=>$i+1,'product_id'=>$p->id,'product_name'=>$p->name,'unit'=>'m','quantity'=>$quantity,'base_quantity'=>$quantity,'unit_price'=>3,'line_total'=>$quantity*3,'cost_total'=>$quantity*2]);};
$days=28;for($i=0;$i<18;$i++){$sale($c,$days,[[$a,[1500,2000,2500][$i%3]]]);$days+=[20,25,30][$i%3];}
for($i=0;$i<3;$i++){$buyer=Customer::create(['name'=>'Related Buyer '.$i]);for($j=0;$j<10;$j++)$sale($buyer,1+2*$j,[[$a,100],[$b,100]]);}for($i=0;$i<30;$i++)$sale(null,$i+1,[[$other,100]]);
for($i=0;$i<5;$i++){$due=today()->subDays(120-$i*20);CustomerDebtTransaction::create(['customer_id'=>$c->id,'user_id'=>$user->id,'type'=>'debt_added','source'=>'manual','amount'=>100,'balance_before'=>0,'balance_after'=>100,'credit_before'=>0,'credit_after'=>0,'transaction_date'=>$due->copy()->subDays(5),'due_date'=>$due]);CustomerDebtTransaction::create(['customer_id'=>$c->id,'user_id'=>$user->id,'type'=>'payment','source'=>'manual','amount'=>100,'balance_before'=>100,'balance_after'=>0,'credit_before'=>0,'credit_after'=>0,'transaction_date'=>$due]);}
CustomerDebtTransaction::create(['customer_id'=>$c->id,'user_id'=>$user->id,'type'=>'debt_added','source'=>'manual','amount'=>900,'balance_before'=>0,'balance_after'=>900,'credit_before'=>0,'credit_after'=>0,'transaction_date'=>today(),'due_date'=>today()->addDays(15)]);
echo json_encode(['customer_id'=>$c->id,'product_id'=>$a->id]);
