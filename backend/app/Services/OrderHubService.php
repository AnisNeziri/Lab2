<?php
namespace App\Services;

use App\Models\{OrderChannel,OrderIntake,SalesOrder,Customer,Product,ActivityLog,BusinessEvent,OperationalException};
use App\Support\{Money,RequestFingerprint,CompanyCurrency};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Channel intake is staging, never a second sales, inventory or money ledger. */
class OrderHubService {
    public function attachManual(SalesOrder $order,array $payload): void {
        $channel=OrderChannel::firstOrCreate(['company_id'=>$order->company_id,'name'=>'Manual'],['type'=>'manual','enabled'=>true,'currency'=>$order->currency,'oversale_policy'=>'accept_backorder','acceptance'=>'review']);
        OrderIntake::firstOrCreate(['company_id'=>$order->company_id,'sales_order_id'=>$order->id],['order_channel_id'=>$channel->id,'external_id'=>$order->order_number,'idempotency_key'=>'manual-'.$order->id,'fingerprint'=>RequestFingerprint::make($payload),'payload'=>$payload,'state'=>'validated','last_success_at'=>now()]);
    }
    private function fail(string $message): never { throw ValidationException::withMessages(['order'=>[$message]]); }
    public function rules(): array {
        return ['external_id'=>'nullable|string|max:150','idempotency_key'=>'required|string|max:100',
            'customer_id'=>'nullable|integer','external_customer_id'=>'nullable|string|max:150',
            'customer'=>'nullable|array:name,email,phone,tax_number','customer.name'=>'nullable|string|max:150','customer.email'=>'nullable|email|max:200','customer.phone'=>'nullable|string|max:40','customer.tax_number'=>'nullable|string|max:80',
            'order_date'=>'required|date','requested_delivery_date'=>'nullable|date|after_or_equal:order_date',
            'currency'=>'nullable|string|size:3','payment_type'=>'required|in:cash,credit,prepaid','priority'=>'nullable|integer|min:1|max:3',
            'billing_address'=>'nullable|string|max:1000','delivery_address'=>'nullable|string|max:1000','notes'=>'nullable|string|max:4000',
            'preorder'=>'nullable|boolean','external_created_at'=>'nullable|date','shipping_amount'=>'nullable|numeric|min:0|decimal:0,2','tax_amount'=>'nullable|numeric|min:0|decimal:0,2',
            'items'=>'required|array|min:1|max:100','items.*'=>'array:product_id,external_product_id,sku,barcode,quantity,unit,unit_price',
            'items.*.product_id'=>'nullable|integer','items.*.external_product_id'=>'nullable|string|max:150','items.*.sku'=>'nullable|string|max:100','items.*.barcode'=>'nullable|string|max:191',
            'items.*.quantity'=>'required|numeric|gt:0|decimal:0,3','items.*.unit'=>'nullable|string|max:30','items.*.unit_price'=>'nullable|numeric|min:0|decimal:0,2'];
    }
    public function intake(OrderChannel $channel,array $payload): OrderIntake {
        abort_unless((int)$channel->company_id===(int)Auth::user()->company_id,404);
        abort_unless($channel->enabled,422,'Order channel is disabled.');
        $result=DB::transaction(function()use($channel,$payload){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $fingerprint=RequestFingerprint::make($payload,['idempotency_key']);
            $existing=OrderIntake::where('order_channel_id',$channel->id)->where(fn($q)=>$q->where('idempotency_key',$payload['idempotency_key'])->when($payload['external_id']??null,fn($x,$id)=>$x->orWhere('external_id',$id)))->get();
            if($existing->count()>1)$this->fail('External reference and request key refer to different orders.');
            if($old=$existing->first()) {
                if(!hash_equals($old->fingerprint,$fingerprint)) {
                    $old->update(['conflict_count'=>$old->conflict_count+1,'last_conflict'=>['fingerprint'=>$fingerprint,'received_at'=>now()->toIso8601String(),'external_id'=>$payload['external_id']??null], 'last_error'=>'Sync conflict: changed external payload. Original order has not been overwritten.']);
                    $this->audit($old,'order.sync_conflict',['fingerprint'=>$fingerprint],$fingerprint);
                    return null;
                }
                return $old;
            }
            $intake=OrderIntake::create(['company_id'=>$channel->company_id,'order_channel_id'=>$channel->id,'external_id'=>$payload['external_id']??null,'idempotency_key'=>$payload['idempotency_key'],'fingerprint'=>$fingerprint,'payload'=>$payload,'received_payload'=>$payload]);
            $this->audit($intake,'online_order.received');
            \App\Models\Notification::create(['company_id'=>$channel->company_id,'user_id'=>Auth::id(),'type'=>'online_order_received','title'=>'New order','message'=>$channel->name.' · '.($intake->external_id??'#'.$intake->id),'data'=>['order_intake_id'=>$intake->id,'title_en'=>'New order','title_sq'=>'Porosi e re']]);
            return $this->process($intake);
        });
        if(!$result)$this->fail('Sync conflict: this external order already exists with different values. Review it; confirmed history is not overwritten.');
        return $result;
    }
    private function matchCustomer(OrderChannel $channel,array $p): array {
        if(!empty($p['customer_id']))return [Customer::where('is_active',true)->find($p['customer_id']),false];
        if(!empty($p['external_customer_id'])) {
            $id=DB::table('order_channel_mappings')->where('company_id',$channel->company_id)->where('order_channel_id',$channel->id)->where('kind','customer')->where('external_id',$p['external_customer_id'])->value('customer_id');
            if($id)return [Customer::where('is_active',true)->find($id),false];
        }
        $identifiers=collect($p['customer']??[])->only(['email','phone','tax_number'])->filter(fn($x)=>filled($x));
        if($identifiers->isEmpty())return [null,false];
        $matches=Customer::where('is_active',true)->where(function($q)use($identifiers){foreach($identifiers as $key=>$value)$q->orWhere($key,trim($value));})->limit(3)->get();
        return [$matches->count()===1?$matches->first():null,$matches->count()>1];
    }
    private function matchProduct(OrderChannel $channel,array $line): ?Product {
        if(!empty($line['product_id']))return Product::with('units')->find($line['product_id']);
        if(!empty($line['external_product_id'])) {
            $id=DB::table('order_channel_mappings')->where('company_id',$channel->company_id)->where('order_channel_id',$channel->id)->where('kind','product')->where('external_id',$line['external_product_id'])->value('product_id');
            if($id)return Product::with('units')->find($id);
        }
        if(empty($line['sku'])&&empty($line['barcode']))return null;
        $rows=Product::with('units')->where(function($q)use($line){if(!empty($line['sku']))$q->orWhere('sku',$line['sku']);if(!empty($line['barcode']))$q->orWhere('barcode',$line['barcode'])->orWhereHas('alternativeBarcodes',fn($b)=>$b->where('barcode_normalized',\App\Support\BarcodeIdentity::normalize($line['barcode']))->where('is_active',true));})->limit(2)->get();
        return $rows->count()===1?$rows->first():null;
    }
    public function process(OrderIntake $intake): OrderIntake {
        return DB::transaction(function()use($intake){
            $intake=OrderIntake::lockForUpdate()->findOrFail($intake->id);
            if($intake->order?->confirmed_at)return $intake;
            $channel=$intake->channel; $p=$intake->payload; $issues=[]; $lines=[];$priceSnapshots=[];
            [$customer,$ambiguous]=$this->matchCustomer($channel,$p);
            if($ambiguous)$issues[]=['code'=>'ambiguous_customer','message'=>'Multiple exact customer identifiers match. Select the correct customer.'];
            $guest=!$customer&&!$ambiguous&&$channel->allow_guest&&empty($p['customer_id'])&&$p['payment_type']==='cash'&&!empty($p['customer']['name']);
            if(!$customer&&!$guest)$issues[]=['code'=>'customer_unmatched','message'=>'Select or map an existing active customer. Guest orders require cash terms and a contact name.'];
            foreach($p['items'] as $index=>$line) {
                $product=$this->matchProduct($channel,$line);
                if(!$product||($product->lifecycle_status && $product->lifecycle_status!=='active')){$issues[]=['code'=>'product_unmatched','line'=>$index+1,'message'=>'Map this line to an active AIMS product.'];continue;}
                $unit=$line['unit']??$product->unit; $factor='1';
                if(strtolower($unit)!==strtolower($product->unit)) {
                    $pack=$product->units->first(fn($u)=>$u->is_active&&$u->conversion_mode==='fixed'&&strtolower($u->code)===strtolower($unit));
                    if(!$pack){$issues[]=['code'=>'invalid_unit','line'=>$index+1,'message'=>'Choose the inventory unit or an active fixed conversion.'];continue;}
                    $factor=$pack->factor_to_base;
                }
                $quote=app(OrderPriceService::class)->quote($product,$channel,$customer,(string)$factor,(string)$line['quantity'],substr($p['order_date'],0,10));
                $expected=$quote['expected_price'];
                $price=isset($line['unit_price'])?Money::normalize($line['unit_price']):$expected;
                $difference=\Brick\Math\BigDecimal::of($price)->minus($expected)->abs();
                if($difference->isGreaterThan($channel->price_tolerance)&&empty($intake->resolution['price_review']))$issues[]=['code'=>'price_mismatch','line'=>$index+1,'message'=>'Submitted price differs from the current AIMS price.','expected'=>$expected,'submitted'=>$price];
                $lines[]=['product_id'=>$product->id,'quantity'=>$line['quantity'],'unit'=>$unit,'unit_price'=>$price];
                $priceSnapshots[]=[...$quote,'product_id'=>$product->id,'unit'=>$unit,'quantity'=>$line['quantity'],'accepted_price'=>$price,'currency'=>$p['currency']??$channel->currency];
            }
            $currency=strtoupper($p['currency']??$channel->currency);
            if($currency!==CompanyCurrency::current())$issues[]=['code'=>'currency_review','message'=>'An accepted FX snapshot is required before this currency can enter fulfillment.'];
            foreach(['shipping_amount','tax_amount'] as $field)if(Money::compare($p[$field]??0,0)>0)$issues[]=['code'=>'financial_review','message'=>'Separate shipping or tax amounts require financial review; they will not be silently discarded.'];
            if(empty($p['delivery_address'])&&($channel->configuration['require_address']??false))$issues[]=['code'=>'address_required','message'=>'Enter a delivery address before accepting this order.'];
            $blocking=collect($issues)->contains(fn($i)=>$i['code']!=='price_mismatch'&&$i['code']!=='address_required');
            if(!$blocking && count($lines)===count($p['items'])) {
                $data=['customer_id'=>$customer?->id,'guest_customer'=>$guest?($p['customer']??[]):null,'order_date'=>$p['order_date'],'requested_delivery_date'=>$p['requested_delivery_date']??null,'priority'=>$p['priority']??2,'currency'=>$currency,'payment_type'=>$p['payment_type'],'warehouse_id'=>$channel->warehouse_id,'notes'=>$p['notes']??null,'items'=>$lines,'idempotency_key'=>'hub-'.$intake->id];
                if($intake->sales_order_id){$data['idempotency_key']='hub-edit-'.Str::uuid();app(OutboundService::class)->action($intake->order,'edit',$data);$order=$intake->order->fresh();}
                else{$order=app(OutboundService::class)->create([...$data,'hub_intake_id'=>$intake->id]);$intake->sales_order_id=$order->id;}
                $order->update(['metadata'=>[...($order->metadata??[]),'order_hub_id'=>$intake->id,'channel_id'=>$channel->id,'external_id'=>$intake->external_id,'guest_customer'=>$guest?($p['customer']??null):null,'billing_address'=>$p['billing_address']??null,'delivery_address'=>$p['delivery_address']??null,'preorder'=>(bool)($p['preorder']??false),'external_created_at'=>$p['external_created_at']??null]]);
                $intake->validation_signature=$this->orderSignature($order);
                $order->update(['metadata'=>[...$order->metadata,'pricing_snapshot'=>$priceSnapshots,'pricing_accepted_at'=>now()->toIso8601String()]]);
            }
            $intake->fill(['issues'=>$issues,'state'=>$issues?'attention':'validated','attempts'=>$intake->attempts+1,'last_success_at'=>now(),'last_error'=>null,'next_retry_at'=>null])->save();
            $this->exception($intake,$issues);
            $this->audit($intake,$issues?'order.validation_failed':'order.validated', ['issues'=>$issues], 'validation:'.$intake->attempts);
            return $intake->fresh();
        });
    }
    public function resolve(OrderIntake $intake,array $payload,?string $reason=null): OrderIntake {
        return DB::transaction(function()use($intake,$payload,$reason){
            $intake=OrderIntake::lockForUpdate()->findOrFail($intake->id);
            if($intake->order?->confirmed_at)$this->fail('Confirmed orders cannot be rewritten. Use cancellation or returns.');
            $resolution=$reason?['price_review'=>true,'reason'=>$reason,'user_id'=>Auth::id(),'at'=>now()->toIso8601String()]:[];
            $intake->update(['payload'=>$payload,'resolution'=>$resolution]);
            $this->audit($intake,'order.reviewed',['reason'=>$reason],(string)Str::uuid());
            return $this->process($intake);
        });
    }
    public function assertConfirmable(SalesOrder $order): void {
        $intake=OrderIntake::where('sales_order_id',$order->id)->first(); if(!$intake)return;
        if(!$intake->channel->enabled)$this->fail('This order channel is disabled.');
        if($intake->issues)$this->fail('Resolve Order Hub attention items before confirming this order.');
        if(data_get($order->metadata,'order_hub_id') && (!$intake->validation_signature||!hash_equals($intake->validation_signature,$this->orderSignature($order))))$this->fail('The order changed after validation. Review it again in Order Hub before confirming.');
        if(app(ApprovalService::class)->isRequired('order_acceptance',Money::normalize($order->total_amount))) {
            $approval=\App\Models\ApprovalRequest::where('entity_type','order_intake')->where('entity_id',$intake->id)->where('rule_type','order_acceptance')->latest('id')->first();
            if(!$approval || $approval->status!=='approved' || !hash_equals($this->orderSignature($order),(string)data_get($approval->context,'intent_signature','')))$this->fail('Order acceptance approval is required. Request approval for the current order before confirmation.');
        }
        if($this->shortage($order)) {
            if($intake->channel->oversale_policy==='do_not_accept')$this->fail('Insufficient eligible stock. This channel does not accept backorders.');
            if($intake->channel->oversale_policy==='require_review' && !hash_equals($this->orderSignature($order),(string)data_get($intake->resolution,'stock_review.signature','')))$this->fail('Insufficient eligible stock. Review and explicitly accept the backorder before confirmation.');
        }
    }
    private function orderSignature(SalesOrder $order): string {
        return RequestFingerprint::make(['customer'=>$order->customer_id,'warehouse'=>$order->warehouse_id,'currency'=>$order->currency,'payment'=>$order->payment_type,'total'=>Money::normalize($order->total_amount),'delivery'=>$order->requested_delivery_date?->toDateString(),'items'=>$order->items()->orderBy('id')->get()->map(fn($i)=>['product'=>$i->product_id,'quantity'=>(string)\Brick\Math\BigDecimal::of($i->quantity)->toScale(3),'unit'=>$i->unit,'price'=>Money::normalize($i->unit_price)])->all()]);
    }
    public function requestApproval(OrderIntake $intake,string $reason): array {
        return DB::transaction(function()use($intake,$reason){
            $intake=OrderIntake::lockForUpdate()->findOrFail($intake->id);$order=$intake->order;
            if(!$order||$order->confirmed_at)$this->fail('Only a matched, unconfirmed order can request acceptance approval.');
            $signature=$this->orderSignature($order);$service=app(ApprovalService::class);
            $existing=\App\Models\ApprovalRequest::where('entity_type','order_intake')->where('entity_id',$intake->id)->where('rule_type','order_acceptance')->whereIn('status',['pending','approved','rejected'])->latest('id')->first();
            if($existing&&!hash_equals($signature,(string)data_get($existing->context,'intent_signature','')))$service->invalidate('order_intake',$intake->id,'order_acceptance','Order changed; a new acceptance decision is required.');
            $request=$service->requestIfRequired('order_intake',$intake->id,'order_acceptance',Money::normalize($order->total_amount),$order->currency,['intent_signature'=>$signature,'order_reference'=>$order->order_number,'reason'=>$reason,'url'=>'/order-hub?intake='.$intake->id]);
            if(!$request)$this->fail('No applicable order acceptance rule is enabled.');
            return $this->detail($intake);
        });
    }
    public function reviewStock(OrderIntake $intake,string $reason): array {
        return DB::transaction(function()use($intake,$reason){
            $intake=OrderIntake::lockForUpdate()->findOrFail($intake->id);
            if(!$intake->order || $intake->order->confirmed_at || $intake->channel->oversale_policy!=='require_review')$this->fail('This order is not eligible for backorder review.');
            $intake->update(['resolution'=>[...($intake->resolution??[]),'stock_review'=>['signature'=>$this->orderSignature($intake->order),'reason'=>$reason,'user_id'=>Auth::id(),'at'=>now()->toIso8601String()]]]);
            $this->audit($intake,'order.backorder_reviewed',['reason'=>$reason]);
            return $this->detail($intake);
        });
    }
    private function shortage(SalesOrder $order): bool {
        foreach($order->items()->with('product')->get()->groupBy('product_id') as $items){
            $item=$items->first();
            $available=collect(app(OutboundService::class)->candidates($item))->reduce(fn($n,$r)=>(string)\Brick\Math\BigDecimal::of($n)->plus($r['available']),'0.000');
            $remaining=$items->reduce(fn($n,$i)=>$n->plus($i->base_quantity)->minus($i->reserved_quantity)->minus($i->dispatched_quantity),\Brick\Math\BigDecimal::of('0'));
            if($remaining->isGreaterThan($available))return true;
        }return false;
    }
    public function confirm(OrderIntake $intake,string $key): array {
        if(!$intake->sales_order_id)$this->fail('Resolve customer and product matching first.');
        return DB::transaction(function()use($intake,$key){
            $order=$intake->order;
            app(OutboundService::class)->action($order,'confirm',['idempotency_key'=>$key.':confirm']);
            app(OutboundService::class)->action($order->fresh(),'reserve',['idempotency_key'=>$key.':reserve']);
            $intake->update(['state'=>'accepted','last_error'=>null]);
            return $this->detail($intake->fresh());
        });
    }
    public function state(SalesOrder $o, bool $includeLedger = false, ?\Illuminate\Support\Collection $obligations = null): array {
        $dispatched=$o->dispatches->reduce(fn($n,$d)=>Money::add($n,$d->total_amount),'0.00');
        $payment=$o->payment_type==='cash'&&Money::compare($dispatched,0)>0?(Money::compare($dispatched,$o->total_amount)>=0?'paid':'partially_paid'):'unpaid';
        if($includeLedger&&$o->customer_id&&$o->payment_type!=='cash'&&Money::compare($dispatched,0)>0){
            $ids=$o->dispatches->pluck('customer_debt_transaction_id')->filter();
            $open=($obligations ?? app(CustomerCreditService::class)->obligations($o->customer))->whereIn('id',$ids)->reduce(fn($sum,$d)=>Money::add($sum,$d->outstanding_amount),'0.00');
            $paid=Money::subtract($dispatched,$open);
            $payment=Money::compare($paid,$o->total_amount)>=0?'paid':(Money::compare($paid,0)>0?'partially_paid':'unpaid');
        }
        if($includeLedger){
            $refunded=DB::table('outbound_returns as r')->join('inventory_returns as ir','ir.id','=','r.inventory_return_id')->join('financial_account_transactions as ft','ft.id','=','ir.financial_account_transaction_id')->where('r.company_id',$o->company_id)->where('r.sales_order_id',$o->id)->where('r.status','resolved')->where('ft.type','refund_out')->whereNull('ft.reversed_at')->sum('ft.amount');
            if(Money::compare($refunded,0)>0)$payment=Money::compare($refunded,$o->total_amount)>=0?'refunded':'partially_refunded';
        }
        // Credit and prepaid collections remain in the customer ledger. Do not
        // infer payment from a provider-supplied "paid" flag or shared advance.
        $delivery=$o->dispatches->contains('status','failed')?'failed':($o->status==='delivered'?'delivered':($o->dispatches->isNotEmpty()?'in_transit':'planned'));
        $backorder=$o->confirmed_at&&$o->status!=='cancelled'&&$o->items->contains(fn($i)=>\Brick\Math\BigDecimal::of($i->base_quantity)->isGreaterThan(\Brick\Math\BigDecimal::of($i->reserved_quantity)->plus($i->dispatched_quantity)));
        $late=$o->requested_delivery_date?->isBefore(today())&&!in_array($o->status,['delivered','cancelled']);
        return ['order'=>$o->status==='cancelled'?'cancelled':($o->confirmed_at?'confirmed':'draft'),'payment'=>$payment,'fulfillment'=>$o->status,'delivery'=>$delivery,'backordered'=>$backorder,'health'=>$late?'late':($delivery==='failed'||$backorder?'attention':'on_track')];
    }
    public function detail(OrderIntake $intake): array {
        $intake->load('channel','order.items','order.dispatches'); $data=$intake->toArray();
        $data['order']=$intake->order?app(OutboundService::class)->detail($intake->order):null;
        $data['connections']=$intake->order?app(OrderWorkflowService::class)->links($intake->order):null;
        $data['states']=$intake->order?$this->state($intake->order,true):['order'=>'received','payment'=>'unpaid','fulfillment'=>'not_started','delivery'=>'planned','health'=>'attention'];
        $data['states']['sync']=$intake->state==='attention'?'review':'synced';
        $data['acceptance_approval']=\App\Models\ApprovalRequest::where('entity_type','order_intake')->where('entity_id',$intake->id)->where('rule_type','order_acceptance')->latest('id')->first();
        $data['acceptance_approval_required']=$intake->order&&app(ApprovalService::class)->isRequired('order_acceptance',Money::normalize($intake->order->total_amount));
        $hours=$intake->channel->configuration['confirmation_hours']??null;
        $data['confirmation_due_at']=$hours?$intake->created_at->copy()->addHours($hours)->toIso8601String():null;
        $data['risk_flags']=[];
        if($data['acceptance_approval_required'])$data['risk_flags'][]=['en'=>'Order value requires acceptance approval.','sq'=>'Vlera e porosisë kërkon miratim pranimi.'];
        if(filled(data_get($intake->payload,'billing_address'))&&filled(data_get($intake->payload,'delivery_address'))&&trim($intake->payload['billing_address'])!==trim($intake->payload['delivery_address']))$data['risk_flags'][]=['en'=>'Billing and delivery addresses differ; verify with the customer.','sq'=>'Adresa e faturimit ndryshon nga dorëzimi; verifikoni me klientin.'];
        if($hours&&!$intake->order?->confirmed_at&&$intake->created_at->copy()->addHours($hours)->isPast()){$data['states']['health']='late';$data['risk_flags'][]=['en'=>'The channel confirmation deadline has passed.','sq'=>'Afati i konfirmimit të kanalit ka kaluar.'];}
        if($data['order'])foreach($data['order']['items'] as &$line){$missing=\Brick\Math\BigDecimal::of($line['base_quantity'])->minus($line['reserved_quantity'])->minus($line['dispatched_quantity']);$line['backordered_quantity']=$intake->order->confirmed_at&&$missing->isPositive()?(string)$missing->toScale(3):'0.000';}unset($line);
        if($intake->issues||$intake->last_error)$data['states']['health']='attention';
        $data['timeline']=BusinessEvent::where(function($q)use($intake){$q->where(fn($x)=>$x->where('entity_type','OrderIntake')->where('entity_id',$intake->id));if($intake->sales_order_id)$q->orWhere(fn($x)=>$x->where('entity_type','SalesOrder')->where('entity_id',$intake->sales_order_id));})->latest('occurred_at')->limit(80)->get();
        return $data;
    }
    public function query(array $filters=[]) {
        if (in_array($filters['view'] ?? '', ['paid','unpaid','partially_paid','refunded','partially_refunded'], true)) {
            $status=$filters['view']; $filters['view']=null;
            $base=$this->query($filters); $ids=[]; $obligations=[];
            (clone $base)->reorder()->chunkById(100,function($rows)use(&$ids,&$obligations,$status){
                foreach($rows as $row){
                    $customer=$row->order?->customer;
                    if($customer) $obligations[$customer->id]??=app(CustomerCreditService::class)->obligations($customer);
                    if($row->order && $row->order->status!=='cancelled' && $this->state($row->order,true,$customer ? $obligations[$customer->id] : null)['payment']===$status) $ids[]=$row->id;
                }
            });
            return $base->whereIntegerInRaw('order_intakes.id',$ids);
        }
        return OrderIntake::with(['channel:id,name,type','order.customer:id,name','order.items','order.dispatches'])
            ->when($filters['customer_id']??null,fn($q,$v)=>$q->whereHas('order',fn($o)=>$o->where('customer_id',$v)))
            ->when($filters['product_id']??null,fn($q,$v)=>$q->whereHas('order.items',fn($i)=>$i->where('product_id',$v)))
            ->when($filters['channel_id']??null,fn($q,$v)=>$q->where('order_channel_id',$v))
            ->when($filters['search']??null,fn($q,$v)=>$q->where(fn($x)=>$x->where('external_id','like','%'.$v.'%')->orWhereHas('channel',fn($c)=>$c->where('name','like','%'.$v.'%'))->orWhereHas('order',fn($o)=>$o->where('order_number','like','%'.$v.'%')->orWhereHas('items.product',fn($p)=>$p->where('name','like','%'.$v.'%')->orWhere('sku','like','%'.$v.'%'))->orWhereHas('customer',fn($c)=>$c->where('name','like','%'.$v.'%')->orWhere('phone','like','%'.$v.'%')))))
            ->when($filters['view']??null,function($q,$v){
                if($v==='attention')$q->where('state','attention');
                if($v==='new')$q->whereIn('state',['received','validated']);
                if($v==='backorders')$q->whereHas('order',fn($o)=>$o->whereNotNull('confirmed_at')->where('status','!=','cancelled')->whereHas('items',fn($i)=>$i->whereRaw('base_quantity > reserved_quantity + dispatched_quantity')));
                if($v==='late')$q->whereHas('order',fn($o)=>$o->whereNotIn('status',['cancelled','delivered'])->whereDate('requested_delivery_date','<',today()));
                if($v==='returns')$q->whereHas('order.returns');
                if($v==='partial')$q->whereHas('order',fn($o)=>$o->whereIn('status',['partially_allocated','partially_picked','partially_packed','partially_dispatched','partially_delivered']));
                if($v==='credit_review')$q->whereHas('order',fn($o)=>$o->whereNull('confirmed_at')->where('payment_type','credit')->where(fn($x)=>$x->whereNotNull('approval_request_id')->orWhereHas('customer',fn($c)=>$c->where('credit_status','blocked'))));
                if($v==='awaiting_payment')$q->whereHas('order',fn($o)=>$o->whereNull('confirmed_at')->where('payment_type','prepaid'));
                if($v==='preorders')$q->where('payload->preorder',true);
                if(in_array($v,['ready_to_allocate','packed','dispatched','delivered','cancelled']))$q->whereHas('order',fn($o)=>$o->where('status',$v));
            })->latest('id');
    }
    public function listing(array $filters=[]) {
        return $this->query($filters)->paginate(min(50,max(1,(int)($filters['per_page']??20))))->through(function($i){$a=$i->only(['id','external_id','state','issues','created_at','sales_order_id']);$a['channel']=$i->channel;$a['order']=$i->order?->only(['id','order_number','status','total_amount','currency','customer_id','requested_delivery_date','priority']);$a['customer']=$i->order?->customer?->name??data_get($i->payload,'customer.name');$a['states']=$i->order?$this->state($i->order,true):null;return $a;});
    }
    public function publicStatus(OrderIntake $i): array {
        $o=$i->order?->load('items.product','dispatches');
        return ['reference'=>$o?->order_number??$i->external_id,'external_id'=>$i->external_id,'received_at'=>$i->created_at,'states'=>$o?$this->state($o,true):['order'=>'received','fulfillment'=>'under_review'],'currency'=>$o?->currency,'total'=>$o?->total_amount,'requested_delivery_date'=>$o?->requested_delivery_date,'items'=>$o?->items->map(fn($l)=>['name'=>$l->product?->name,'quantity'=>$l->quantity,'unit'=>$l->unit,'unit_price'=>$l->unit_price,'delivered_quantity'=>$l->delivered_quantity]),'deliveries'=>$o?->dispatches->map->only(['reference','status','dispatched_at','delivered_at'])];
    }
    public function trackingLink(OrderIntake $i): string {
        $token=Str::random(64);$i->update(['tracking_hash'=>hash('sha256',$token),'tracking_expires_at'=>now()->addDays(90)]);return $token;
    }
    public function catalog(string $search=''): array {
        $products=Product::with('units')->where('lifecycle_status','active')->when($search,fn($q)=>$q->where(fn($x)=>$x->where('name','like','%'.$search.'%')->orWhere('sku','like','%'.$search.'%')->orWhere('barcode',$search)->orWhereHas('category',fn($c)=>$c->where('name','like','%'.$search.'%'))->orWhereHas('alternativeBarcodes',fn($b)=>$b->where('barcode_normalized',\App\Support\BarcodeIdentity::normalize($search))->where('is_active',true))))->orderBy('name')->paginate(20);
        $snapshots=app(InventorySnapshotService::class)->forProducts($products->getCollection());
        return [...$products->toArray(),'data'=>$products->map(fn($p)=>['id'=>$p->id,'name'=>$p->name,'sku'=>$p->sku,'unit'=>$p->unit,'price'=>$p->selling_price??$p->price,'currency'=>CompanyCurrency::current(),'availability'=>collect($snapshots[$p->id]??[])->only(['available','reserved','incoming','available_to_promise_by_as_of'])->all(),'units'=>$p->units->where('is_active',true)->where('conversion_mode','fixed')->map->only(['code','factor_to_base'])->values()])->all()];
    }
    public function audit(OrderIntake $i,string $event,array $metadata=[],?string $key=null): void {
        app(BusinessEventService::class)->record($event,$i,$i->external_id,$metadata,$key?'hub:'.$i->id.':'.$key:null);
        ActivityLog::create(['company_id'=>$i->company_id,'user_id'=>Auth::id(),'action'=>$event,'entity'=>'OrderIntake','entity_id'=>$i->id,'description'=>$event,'new_value'=>$metadata]);
    }
    private function exception(OrderIntake $i,array $issues):void {
        $key='order-intake:'.$i->id;
        if(!$issues){OperationalException::where('exception_key',$key)->where('status','open')->update(['status'=>'resolved','resolved_at'=>now(),'resolved_by'=>Auth::id()]);return;}
        OperationalException::updateOrCreate(['exception_key'=>$key],['company_id'=>$i->company_id,'entity_type'=>'OrderIntake','entity_id'=>$i->id,'exception_type'=>'order_intake_review','severity'=>'warning','status'=>'open','detected_at'=>now(),'description'=>'Order intake requires review.','relevant_values'=>['issues'=>$issues],'next_action'=>['url'=>'/order-hub?intake='.$i->id]]);
    }
}
