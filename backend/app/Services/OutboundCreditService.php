<?php
namespace App\Services;

use App\Models\{SalesOrder, Customer, ApprovalRequest, ApprovalDecision};
use App\Support\{Money, RequestFingerprint};
use Illuminate\Support\Facades\Auth;
use App\Exceptions\CustomerCreditBlockedException;

class OutboundCreditService
{
    public function context(SalesOrder $order): array
    {
        if(!$order->customer_id)return ['customer_id'=>null,'credit_status'=>'normal','current_debt'=>'0.00','advance'=>'0.00','current_exposure'=>'0.00','projected_exposure'=>'0.00','total_exposure'=>'0.00','credit_limit'=>null,'available_credit'=>null,'proposed_amount'=>'0.00'];
        $customer = Customer::query()->lockForUpdate()->findOrFail($order->customer_id);
        $exposure = app(CustomerCreditService::class)->exposure($customer);
        $own = $order->confirmed_at ? $order->committed_amount : '0';
        $amount = $order->confirmed_at ? $order->committed_amount : $order->total_amount;
        // Net exposure may be clamped to zero by the shared service. Preserve
        // unused advance before projecting this order, then clamp each result.
        $net = Money::subtract(Money::add($exposure['current_debt'], Money::subtract($exposure['committed_exposure'], $own)), $exposure['advance']);
        $current = Money::compare($net,'0')<0?'0.00':$net;
        $projected = Money::add($net,$amount);
        if(Money::compare($projected,'0')<0)$projected='0.00';
        return [...$exposure, 'customer_id'=>$customer->id, 'customer_name'=>$customer->name,
            'current_exposure'=>$current, 'proposed_amount'=>$amount, 'projected_exposure'=>$projected,
            'credit_status'=>$customer->credit_status, 'source_entity'=>'sales_order', 'source_reference'=>$order->order_number,
            'intent_signature'=>RequestFingerprint::make(['order'=>$order->id,'customer'=>$customer->id,'total'=>$order->total_amount,'terms'=>$customer->payment_terms_days,'limit'=>$customer->credit_limit,'credit_status'=>$customer->credit_status,'hold'=>$customer->credit_hold_reason,'currency'=>$order->currency,'items'=>$order->items()->orderBy('id')->get(['product_id','quantity','unit','unit_price'])->toArray()])];
    }

    public function authorize(SalesOrder $order): void
    {
        if ($order->payment_type === 'cash') return;
        $c=$this->context($order);
        if ($order->payment_type === 'prepaid') {
            $other=Money::subtract($c['committed_exposure']??'0',$order->confirmed_at?$order->committed_amount:'0');
            if(Money::compare($c['advance'],Money::add($c['proposed_amount'],$other))<0)
                throw \Illuminate\Validation\ValidationException::withMessages(['payment_type'=>['The recorded customer advance does not cover this order and existing commitments.']]);
            return;
        }
        $blocked=$c['credit_status']==='blocked'||($c['credit_limit']!==null&&Money::compare($c['projected_exposure'],$c['credit_limit'])>0);
        if(!$blocked)return;
        $a=$order->approval_request_id?app(ApprovalService::class)->lockCustomerCreditOverride($order->approval_request_id,$order->customer):null;
        if($a && in_array($a->status,['approved','consumed']) && hash_equals((string)data_get($a->context,'intent_signature',''),$c['intent_signature']) && (int)data_get($a->context,'sales_order_id')===$order->id)return;
        throw new CustomerCreditBlockedException('Credit approval is required before fulfilling this sales order.',[...$c,'blocked'=>true,'override_allowed'=>true,'approval_request_id'=>$a?->id,'approval_status'=>$a?->status]);
    }

    public function request(SalesOrder $order,string $reason): ApprovalRequest
    {
        $c=$this->context($order);
        $a=app(ApprovalService::class)->requestCustomerCreditOverride($order->customer,$c['proposed_amount'],$order->currency,[...$c,'sales_order_id'=>$order->id,'override_reason'=>$reason]);
        $order->update(['approval_request_id'=>$a->id]); return $a;
    }

    public function consume(SalesOrder $order): void
    {
        if(!$order->approval_request_id)return;
        $a=ApprovalRequest::query()->lockForUpdate()->findOrFail($order->approval_request_id);
        if($a->status!=='approved')return;
        $a->update(['status'=>'consumed']);
        ApprovalDecision::create(['company_id'=>$order->company_id,'approval_request_id'=>$a->id,'actor_id'=>Auth::id(),'decision'=>'consumed','comment'=>'Bound to sales order '.$order->order_number,'decided_at'=>now(),'snapshot'=>['sales_order_id'=>$order->id,'context'=>$a->context]]);
    }
}
