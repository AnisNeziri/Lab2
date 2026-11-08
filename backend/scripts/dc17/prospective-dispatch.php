<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{Auth,DB};
use App\Models\{User,Product,SalesOrder,DailySale};
$path=$dc17Root.'/evidence/prospective-dispatch.json';if(is_file($path)){echo "Prospective dispatch evidence retained; no repeated business writes.\n";exit;}
$a=json_decode(file_get_contents($dc16Root.'/accounts.json'),true)['control'];Auth::setUser(User::withoutGlobalScopes()->findOrFail($a['user_id']));
if(Auth::user()->company_id!==3)throw new RuntimeException('Wrong disposable tenant');
$key=fn($purpose)=>substr(hash('sha256','DC17-provenance-'.$purpose),0,8).'-'.substr(hash('sha256',$purpose),0,4).'-4abc-a123-'.substr(hash('sha256','tail'.$purpose),0,12);
$out=app(App\Services\OutboundService::class);$product=Product::findOrFail(302);$before=(float)$product->quantity;
$order=$out->create(['customer_id'=>121,'warehouse_id'=>6,'order_date'=>today()->toDateString(),'payment_type'=>'cash','currency'=>'EUR','items'=>[['product_id'=>302,'quantity'=>5,'unit'=>'pcs','unit_price'=>5]],'idempotency_key'=>$key('create')]);
$out->action($order,'confirm',['idempotency_key'=>$key('confirm')]);$out->action($order,'reserve',['idempotency_key'=>$key('reserve')]);$out->action($order,'allocate',['idempotency_key'=>$key('allocate')]);
$order=$order->fresh('allocations');foreach($order->allocations as $line)$out->action($order,'pick',['allocation_id'=>$line->id,'quantity'=>$line->quantity,'manual_verification'=>true,'reason'=>'DC17 supervised provenance check','idempotency_key'=>$key('pick'.$line->id)]);
$order=$order->fresh('allocations');$out->action($order,'pack',['items'=>$order->allocations->map(fn($line)=>['allocation_id'=>$line->id,'quantity'=>$line->picked_quantity])->all(),'idempotency_key'=>$key('pack')]);
$order=$order->fresh('packages');$input=['package_ids'=>$order->packages->pluck('id')->all(),'idempotency_key'=>$key('dispatch')];$out->action($order,'dispatch',$input);
$firstJournals=DB::table('journal_entries')->count();$firstMovements=DB::table('stock_movements')->count();$sale=DailySale::findOrFail($order->dispatches()->firstOrFail()->daily_sale_id);$finalized=$sale->finalized_at?->toIso8601String();
$out->action($order,'dispatch',$input);
$reconciled=app(App\Services\AccountingService::class)->reconciliation(false)['all_reconciled'];
$checks=['authentic_finalized_at'=>$finalized!==null,'one_dispatch'=>$order->dispatches()->count()===1,'quantity_once'=>(float)$product->fresh()->quantity===$before-5,'no_retry_journals'=>DB::table('journal_entries')->count()===$firstJournals,'no_retry_movements'=>DB::table('stock_movements')->count()===$firstMovements,'provenance_unchanged'=>$sale->fresh()->finalized_at?->toIso8601String()===$finalized,'control_finance_reconciled'=>$reconciled];
dc17Evidence('prospective-dispatch',['company'=>3,'order_id'=>$order->id,'sale_id'=>$sale->id,'finalized_at'=>$finalized,'checks'=>$checks,'prior_history_rewritten'=>false]);echo json_encode($checks)."\n";if(in_array(false,$checks,true))exit(2);
