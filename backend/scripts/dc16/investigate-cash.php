<?php
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{Auth,DB};
$a=json_decode(file_get_contents($dc16Root.'/accounts.json'),true);Auth::setUser(App\Models\User::withoutGlobalScopes()->findOrFail($a['primary']['user_id']));
$sales=App\Models\DailySale::whereDoesntHave('outboundDispatch',fn($q)=>$q->whereHas('order',fn($o)=>$o->where('payment_type','!=','cash')))->get()->toArray();
$returns=App\Models\InventoryReturn::with('items')->get()->toArray();
$transactions=DB::table('financial_account_transactions')->where('company_id',Auth::user()->company_id)->where('source_type','like','%return%')->get()->toArray();
$journals=DB::table('journal_entries')->where('company_id',Auth::user()->company_id)->where(fn($q)=>$q->where('source_type','like','%return%')->orWhere('source_key','like','%return%'))->get()->toArray();
dc16Evidence('cash-investigation',['cash_included_daily_sales'=>$sales,'returns'=>$returns,'return_bank_transactions'=>$transactions,'return_journals'=>$journals,'checkpoint'=>DB::table('_dc16_checkpoints')->where('name','history')->value('completed_day')]);
echo json_encode(['cash_included_daily_sale_ids'=>array_column($sales,'id'),'cash_included_total'=>array_sum(array_column($sales,'total_amount')),'returns'=>count($returns),'return_bank_transactions'=>count($transactions)])."\n";
