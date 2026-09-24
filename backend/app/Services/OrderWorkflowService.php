<?php

namespace App\Services;

use App\Models\{OrderIntake, SalesOrder, DailySale, Invoice, CustomerDebtTransaction, FinancialAccount};
use App\Support\Money;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Validation\ValidationException;

/** Coordinates existing authorities; never posts stock or creates a parallel sale. */
class OrderWorkflowService
{
    private function fail(string $message): never { throw ValidationException::withMessages(['order' => [$message]]); }

    public function links(SalesOrder $order): array
    {
        $dispatches=$order->dispatches()->get();
        $sales=DailySale::whereIn('id',$dispatches->pluck('daily_sale_id')->filter())->get(['id','sale_number','sale_date','total_amount','paid_amount','payment_method']);
        $invoices=Invoice::whereIn('daily_sale_id',$sales->pluck('id'))->where('document_type','invoice')->get(['id','daily_sale_id','invoice_number','status','grand_total','total_paid']);
        return [
            'sales'=>$sales,
            'invoices'=>$invoices,
            'credit_notes'=>Invoice::whereIn('original_invoice_id',$invoices->pluck('id'))->where('document_type','credit_note')->get(['id','original_invoice_id','invoice_number','status','grand_total']),
            'payments'=>CustomerDebtTransaction::where('customer_id',$order->customer_id)->where('metadata->sales_order_id',$order->id)->where('type','payment')->get(),
            'recognition_policy'=>'dispatch',
            'next_action'=>$this->nextAction($order),
        ];
    }

    private function nextAction(SalesOrder $order): string
    {
        if($order->status==='cancelled')return 'none';
        if(!$order->confirmed_at)return 'confirm';
        if($order->items()->whereRaw('base_quantity > reserved_quantity + dispatched_quantity')->exists())return 'stock_review';
        return match($order->status){
            'packed'=>'dispatch', 'dispatched','partially_delivered'=>'deliver',
            'delivered'=>'documents', default=>'prepare',
        };
    }

    public function ready(OrderIntake $intake, array $data): array
    {
        if(empty($data['verified']))$this->fail('Verify the products and quantities before marking them ready.');
        return DB::transaction(function()use($intake,$data){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $order=SalesOrder::lockForUpdate()->findOrFail($intake->sales_order_id);
            if(!$order->confirmed_at)$this->fail('Confirm the order first.');
            if($order->status==='packed')return app(OrderHubService::class)->detail($intake);
            if(in_array($order->status,['cancelled','delivered','dispatched'])||$order->dispatches()->exists())$this->fail('Use the warehouse workflow for partially dispatched or completed orders.');
            if($order->items()->whereHas('product',fn($q)=>$q->whereNotIn('tracking_mode',['none'])->whereNotNull('tracking_mode'))->exists())$this->fail('This order contains tracked products. Scan and verify them in Warehouse Fulfillment.');
            $engine=app(OutboundService::class);$key=$data['idempotency_key'];
            if($order->items()->whereRaw('base_quantity > reserved_quantity + dispatched_quantity')->exists())$engine->action($order,'reserve',['idempotency_key'=>$key.':reserve']);
            $order->refresh();
            if($order->items()->whereRaw('base_quantity > reserved_quantity + dispatched_quantity')->exists())$this->fail('There is not enough reserved stock. Reduce the order or use the warehouse backorder workflow.');
            if($order->allocations()->where('status','reserved')->whereNull('pick_task_id')->exists())$engine->action($order,'allocate',['idempotency_key'=>$key.':allocate']);
            foreach($order->allocations()->where('status','!=','released')->get() as $a){
                if(Money::compareDecimal($a->picked_quantity,$a->quantity)<0)$engine->action($order,'pick',['idempotency_key'=>$key.':pick:'.$a->id,'allocation_id'=>$a->id,'location_id'=>$a->location_id,'inventory_lot_id'=>$a->inventory_lot_id,'quantity'=>$a->quantity,'manual_verification'=>true]);
            }
            $items=$order->allocations()->where('status','!=','released')->get()->filter(fn($a)=>Money::compareDecimal($a->picked_quantity,$a->packed_quantity)>0)->map(fn($a)=>['allocation_id'=>$a->id,'quantity'=>Money::normalizeDecimal(\Brick\Math\BigDecimal::of($a->picked_quantity)->minus($a->packed_quantity),3)])->values()->all();
            if($items)$engine->action($order,'pack',['idempotency_key'=>$key.':pack','items'=>$items]);
            return app(OrderHubService::class)->detail($intake->fresh());
        });
    }

    public function invoice(OrderIntake $intake,int $saleId): Invoice
    {
        return DB::transaction(function()use($intake,$saleId){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $order=SalesOrder::lockForUpdate()->findOrFail($intake->sales_order_id);
            $order->dispatches()->where('daily_sale_id',$saleId)->firstOrFail();
            $sale=DailySale::with('items')->lockForUpdate()->findOrFail($saleId);
            $existing=Invoice::where('daily_sale_id',$sale->id)->where('document_type','invoice')->first();
            if($existing)return app(InvoiceService::class)->find($existing);
            if($order->currency!=='EUR')$this->fail('The current invoice module supports EUR only.');
            $data=['customer_id'=>$order->customer_id,'invoice_date'=>now('Europe/Belgrade')->toDateString(),'supply_date'=>$sale->sale_date->toDateString(),'notes'=>'Order '.$order->order_number.' / '.$sale->sale_number,'items'=>$sale->items->map(fn($i)=>['product_id'=>$i->product_id,'description'=>$i->product_name,'quantity'=>$i->quantity,'unit'=>$i->unit,'unit_price'=>$i->unit_price,'warehouse_id'=>$i->warehouse_id])->all()];
            if(!$order->customer_id)$data['buyer']=['legal_name'=>$sale->customer_name];
            return app(InvoiceService::class)->createDraft($data,$sale);
        });
    }

    public function payment(OrderIntake $intake,array $data): array
    {
        return DB::transaction(function()use($intake,$data){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $order=SalesOrder::lockForUpdate()->findOrFail($intake->sales_order_id);
            if(!$order->customer_id||$order->payment_type==='cash'||$order->status==='cancelled')$this->fail('Cash orders are settled at dispatch. Customer credit and advances use the customer payment ledger.');
            $account=FinancialAccount::findOrFail($data['financial_account_id']);
            if($account->currency!==$order->currency)$this->fail('Choose a payment account in the order currency.');
            $transaction=app(CustomerDebtService::class)->recordPayment($order->customer,[
                'amount'=>$data['amount'],'transaction_date'=>$data['transaction_date'],'payment_method'=>$data['payment_method'],
                'financial_account_id'=>$data['financial_account_id'],'reference_number'=>$order->order_number,
                'note'=>$data['note']??'Order payment '.$order->order_number,'source'=>'manual',
                'idempotency_key'=>'order-payment:'.$data['idempotency_key'],'metadata'=>['sales_order_id'=>$order->id],
            ]);
            app(BusinessEventService::class)->record('order.payment_received',$order,$order->order_number,['customer_debt_transaction_id'=>$transaction->id,'amount'=>$transaction->amount],'order-payment:'.$transaction->id);
            return app(OrderHubService::class)->detail($intake->fresh());
        });
    }
}
