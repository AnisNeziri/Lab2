<?php
// Test data only. Refuse any live connection before creating the test company.
require __DIR__.'/../../../backend/vendor/autoload.php';
$app=require __DIR__.'/../../../backend/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||basename(config('database.connections.sqlite.database'))!=='e2e.sqlite'||!app()->environment('e2e'))throw new RuntimeException('Isolated E2E database required.');
use App\Models\{Company,User,Customer,CustomerDebtTransaction,FinancialAccount,Supplier,PurchaseOrder,Expense,ExpensePayment,Category,Product,ProductSupplier,Shipment,ShipmentIntelligence,FinancialIntelligencePolicy};
use Illuminate\Support\Facades\{Auth,DB,Hash};
use App\Services\{AnalyticsPermissions,InventoryPlanningService,EnterpriseDecisionService};
$user=User::where('email','finance-v7@enterprise.test')->first();
if(($argv[1]??'')==='verify'){
 if(!$user)throw new RuntimeException('Test company missing.');Auth::setUser($user);
 echo json_encode(collect(['journal_entries','financial_account_transactions','customer_debt_transactions','purchase_orders','expenses','stock_movements'])->mapWithKeys(fn($t)=>[$t=>DB::table($t)->where('company_id',$user->company_id)->orderBy('id')->get()->toJson()])->all());exit;
}
if($user)throw new RuntimeException('Use a fresh isolated E2E run.');
$company=Company::factory()->create(['name'=>'V7 Wholesale Test','base_currency'=>'EUR']);$user=User::factory()->create(['name'=>'Financial Test Admin','email'=>'finance-v7@enterprise.test','password'=>Hash::make('password'),'role'=>'admin','company_id'=>$company->id,'is_active'=>true,'email_verified_at'=>now()]);Auth::setUser($user);AnalyticsPermissions::install();
FinancialAccount::create(['type'=>'bank','name'=>'Recorded test bank','currency'=>'EUR','opening_balance'=>80000,'opening_date'=>today(),'is_active'=>true]);
$customer=Customer::create(['name'=>'Late Wholesale Buyer','current_debt'=>42000,'current_credit'=>500]);
$debt=function($amount,$due,$date)use($customer){return CustomerDebtTransaction::create(['customer_id'=>$customer->id,'user_id'=>Auth::id(),'type'=>'debt_added','source'=>'manual','amount'=>$amount,'balance_before'=>0,'balance_after'=>$amount,'credit_before'=>0,'credit_after'=>0,'transaction_date'=>$date,'due_date'=>$due]);};
$payment=function($amount,$date)use($customer){CustomerDebtTransaction::create(['customer_id'=>$customer->id,'user_id'=>Auth::id(),'type'=>'payment','source'=>'manual','amount'=>$amount,'balance_before'=>$amount,'balance_after'=>0,'credit_before'=>0,'credit_after'=>0,'transaction_date'=>$date]);};
for($i=0;$i<5;$i++){$due=today()->subDays(80-$i*12);$debt(100,$due,$due->copy()->subDays(5));$payment(100,$due->copy()->addDays(10));}
$debt(50000,today()->addDays(10),today());$payment(8000,today());
$supplier=Supplier::create(['name'=>'V7 Import Factory','is_active'=>true]);
$expense=Expense::create(['supplier_id'=>$supplier->id,'vendor_name'=>$supplier->name,'vendor_key'=>hash('sha256','V7 Import Factory'),'document_type'=>'purchase_invoice','document_number'=>'V7-SUP-1','document_number_normalized'=>'V7-SUP-1','status'=>'posted','invoice_date'=>today(),'received_date'=>today(),'due_date'=>today()->addDays(5),'currency'=>'EUR','exchange_rate'=>1,'gross_amount'=>40000,'gross_amount_eur'=>40000,'net_amount'=>40000,'net_amount_eur'=>40000,'vat_amount'=>0,'created_by'=>Auth::id()]);
ExpensePayment::create(['expense_id'=>$expense->id,'amount'=>5000,'amount_eur'=>5000,'status'=>'completed','payment_date'=>today(),'payment_method'=>'bank','created_by'=>Auth::id()]);
$po=PurchaseOrder::create(['supplier_id'=>$supplier->id,'po_number'=>'V7-PO-1','status'=>'ordered','total_amount'=>28000,'currency'=>'EUR','total_paid'=>0,'ordered_at'=>today(),'due_at'=>today()->addDays(7)]);
$ship=Shipment::create(['tracking_number'=>'V7-SHIP-1','purchase_order_id'=>$po->id,'supplier_id'=>$supplier->id,'transport_mode'=>'sea','status'=>'in_transit']);
ShipmentIntelligence::create(['shipment_id'=>$ship->id,'version'=>'v7-test-eta','fingerprint'=>hash('sha256','v7-test-eta'),'risk'=>'DELAYED','confidence'=>'moderate','eta'=>['target'=>'warehouse','predicted'=>today()->addDays(20)->toDateString()],'evidence'=>[],'impact'=>[],'alternatives'=>[],'generated_at'=>now(),'evidence_cutoff'=>now(),'checked_at'=>now()]);
FinancialIntelligencePolicy::create(['version'=>'v7-test-policy','settings'=>['cash_coverage_confirmed'=>true,'minimum_cash'=>['EUR'=>'50000.00'],'terms'=>['po:'.$po->id=>['basis'=>'arrival','reference'=>'Test signed contract clause 5','shipment_id'=>$ship->id,'offset_days'=>0]],'split_permissions'=>[$supplier->id=>['allowed'=>true,'reference'=>'Test supplier permits split deliveries']]],'created_by'=>Auth::id()]);
$cat=Category::create(['name'=>'V7 Hardware']);$product=Product::create(['name'=>'V7 Door handles','sku'=>'V7-HANDLE','category_id'=>$cat->id,'supplier_id'=>$supplier->id,'quantity'=>500,'unit'=>'pcs','purchase_price'=>3,'price'=>5,'min_quantity'=>8500,'weighted_average_cost'=>3,'inventory_value'=>1500]);
ProductSupplier::create(['product_id'=>$product->id,'supplier_id'=>$supplier->id,'purchase_price'=>3,'currency'=>'EUR','exchange_rate_to_base'=>1,'pack_size'=>100,'minimum_order_quantity'=>100,'usual_lead_time_days'=>7,'is_active'=>true]);
InventoryPlanningService::invalidate($company->id,[$product->id]);$plan=app(InventoryPlanningService::class)->save($product->id,['base_quantity'=>8000,'supplier_id'=>$supplier->id]);
echo json_encode(['product_id'=>$product->id,'supplier_id'=>$supplier->id,'shipment_id'=>$ship->id,'recommended_cost'=>$plan['plan']['base_cost']]);
