<?php
namespace App\Services;

use App\Models\{Customer,CustomerQuotation,CustomerQuotationItem,OrderChannel,OrderIntake,Product,SalesOpportunity};
use App\Support\{CompanyClock,CompanyCurrency,Money,RequestFingerprint};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Commercial documents only. Order, stock, tax and money effects belong to existing authorities. */
class CustomerQuotationService
{
    private function fail(string $message): never { throw ValidationException::withMessages(['quotation'=>[$message]]); }
    public function permit(string $permission): void {abort_unless(Auth::user()?->company_id && app(PermissionService::class)->roleHasPermission(Auth::user()->role,$permission),403);}
    public function own($record): void {abort_unless((int)$record->company_id===(int)Auth::user()->company_id,404);}
    private function snapshot(CustomerQuotation $quote): array { $quote->loadMissing('items');return [...$quote->attributesToArray(),'items'=>$quote->items->sortBy('id')->map(fn($line)=>$line->attributesToArray())->values()->all()]; }
    private function revision(array $document): string
    {
        $header=[];foreach(['id','customer_id','number','status','currency','quote_date','expires_on','payment_type','order_channel_id','opportunity_id','converted_order_id','total_amount','notes','billing_address','delivery_address'] as $field)$header[$field]=$document[$field]??null;
        return RequestFingerprint::make($header+['items'=>$document['items']]);
    }
    public function rules(): array
    {
        return ['customer_id'=>'required|integer','order_channel_id'=>'required|integer','opportunity_id'=>'nullable|integer',
            'quote_date'=>'required|date_format:Y-m-d','expires_on'=>'required|date_format:Y-m-d|after_or_equal:quote_date','payment_type'=>'required|in:cash,credit,prepaid',
            'notes'=>'nullable|string|max:4000','billing_address'=>'nullable|string|max:1000','delivery_address'=>'nullable|string|max:1000',
            'idempotency_key'=>'required|string|max:100','items'=>'required|array|min:1|max:50','items.*'=>'array:product_id,quantity,unit',
            'items.*.product_id'=>'required|integer','items.*.quantity'=>'required|numeric|gt:0|decimal:0,3','items.*.unit'=>'nullable|string|max:30'];
    }
    public function prices(Customer $customer,OrderChannel $channel,array $items,string $date): array
    {
        $this->own($customer); $this->own($channel);
        if(!$customer->is_active||!$channel->enabled||$channel->currency!==CompanyCurrency::current())$this->fail('Choose an active customer and order channel in company currency.');
        $rows=[];$total='0.00';
        foreach($items as $item){
            $product=Product::with('units')->findOrFail($item['product_id']);$unit=$item['unit']??$product->unit;$factor='1';
            if($product->lifecycle_status && $product->lifecycle_status!=='active')$this->fail('Choose an active product.');
            if(strtolower($unit)!==strtolower($product->unit)){
                $pack=$product->units->first(fn($u)=>$u->is_active&&$u->conversion_mode==='fixed'&&strtolower($u->code)===strtolower($unit));
                if(!$pack)$this->fail('Choose the inventory unit or an active fixed conversion.');$factor=(string)$pack->factor_to_base;
            }
            $price=app(OrderPriceService::class)->quote($product,$channel,$customer,$factor,(string)$item['quantity'],$date);
            $amount=Money::multiply($price['expected_price'],(string)$item['quantity']);$total=Money::add($total,$amount);
            $rows[]=['product_id'=>$product->id,'description'=>$product->name,'unit'=>$unit,'quantity'=>(string)$item['quantity'],'unit_price'=>$price['expected_price'],'line_total'=>$amount,'pricing_snapshot'=>$price];
        }
        return ['items'=>$rows,'total_amount'=>$total,'currency'=>CompanyCurrency::current()];
    }
    public function create(array $data): CustomerQuotation
    {
        $this->permit('customers.manage');$this->permit('fulfillment.manage');$this->permit('fulfillment.view');
        return DB::transaction(function()use($data){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $fingerprint=RequestFingerprint::make($data,['idempotency_key']);
            if($old=CustomerQuotation::where('request_key',$data['idempotency_key'])->first()){
                if(!hash_equals($old->fingerprint,$fingerprint))$this->fail('This quotation request key was already used for different values.');return $old->load('items');
            }
            $customer=Customer::findOrFail($data['customer_id']);$channel=OrderChannel::findOrFail($data['order_channel_id']);
            if(!empty($data['opportunity_id'])){ $o=SalesOpportunity::findOrFail($data['opportunity_id']);if((int)$o->customer_id!==$customer->id)$this->fail('The opportunity belongs to a different customer.'); }
            $prices=$this->prices($customer,$channel,$data['items'],$data['quote_date']);
            $quote=CustomerQuotation::create([...collect($data)->except(['items','idempotency_key'])->all(),'number'=>'Q-'.Str::uuid(),'status'=>'draft','currency'=>$prices['currency'],'total_amount'=>$prices['total_amount'],'request_key'=>$data['idempotency_key'],'fingerprint'=>$fingerprint,'created_by'=>Auth::id()]);
            $quote->update(['number'=>'Q-'.substr($data['quote_date'],0,4).'-'.str_pad((string)$quote->id,6,'0',STR_PAD_LEFT)]);
            foreach($prices['items'] as $row)CustomerQuotationItem::create($row+['quotation_id'=>$quote->id]);
            $this->audit($quote,'quotation.created');return $quote->load('items');
        });
    }
    public function present(CustomerQuotation $quote,bool $withItems=true): array
    {
        $this->permit('customers.manage');$this->permit('fulfillment.view');$this->own($quote);
        $status=$quote->status;
        if(!$quote->converted_order_id&&in_array($status,['draft','sent','accepted'])&&$quote->expires_on->toDateString()<CompanyClock::today()->toDateString())$status='expired';
        $intake=$quote->converted_order_id?OrderIntake::where('sales_order_id',$quote->converted_order_id)->value('id'):null;
        $document=$withItems?$this->snapshot($quote):$quote->attributesToArray();
        return [...$document,...($withItems?['edit_revision'=>$this->revision($document)]:[]),'effective_status'=>$status,'order_url'=>$intake?'/order-hub?intake='.$intake:null,'customer_url'=>'/customers?customer='.$quote->customer_id,
            'tax_basis'=>'Order catalogue pricing. Final tax calculation remains with the invoice authority.'];
    }
    public function updateDraft(CustomerQuotation $quote,array $data): CustomerQuotation
    {
        $this->permit('customers.manage');$this->permit('fulfillment.manage');$this->permit('fulfillment.view');$this->own($quote);
        return DB::transaction(function()use($quote,$data){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $quote=CustomerQuotation::lockForUpdate()->findOrFail($quote->id);$before=$this->snapshot($quote);
            if($quote->status!=='draft'||$quote->converted_order_id)$this->fail('Only an unsent draft quotation can be edited.');
            abort_unless(hash_equals($this->revision($before),$data['expected_revision']),409,'The draft changed. Reopen it and review the current quotation before editing.');
            if((int)$data['customer_id']!==(int)$quote->customer_id)$this->fail('A draft stays with its original customer. Create a new quotation for another customer.');
            if(!empty($data['opportunity_id'])&&(int)SalesOpportunity::findOrFail($data['opportunity_id'])->customer_id!==(int)$quote->customer_id)$this->fail('The opportunity belongs to a different customer.');
            $prices=$this->prices($quote->customer,OrderChannel::findOrFail($data['order_channel_id']),$data['items'],$data['quote_date']);
            $quote->update([...collect($data)->except(['items','idempotency_key','expected_revision'])->all(),'total_amount'=>$prices['total_amount']]);
            // These are unissued commercial draft lines, never stock or posted financial history.
            $quote->items()->delete();foreach($prices['items'] as $row)CustomerQuotationItem::create($row+['quotation_id'=>$quote->id]);
            \App\Models\ActivityLog::create(['user_id'=>Auth::id(),'action'=>'quotation.draft_updated','entity'=>'CustomerQuotation','entity_id'=>$quote->id,'description'=>$quote->number,'old_value'=>$before,'new_value'=>$quote->fresh('items')->toArray()]);
            return $quote->fresh('items');
        });
    }
    public function transition(CustomerQuotation $quote,string $action): CustomerQuotation
    {
        $this->permit('customers.manage');$this->permit('fulfillment.manage');$this->own($quote);
        return DB::transaction(function()use($quote,$action){
            $quote=CustomerQuotation::lockForUpdate()->findOrFail($quote->id);
            $target=['send'=>'sent','accept'=>'accepted','reject'=>'rejected','cancel'=>'cancelled'][$action]??null;
            abort_unless($target,404);if($quote->status===$target)return $quote;
            if($quote->converted_order_id||$quote->expires_on->toDateString()<CompanyClock::today()->toDateString())$this->fail('This quotation is converted or expired. Create a new reviewed quotation.');
            if(!in_array($quote->status,match($action){'send'=>['draft'],'accept','reject'=>['sent'],'cancel'=>['draft','sent','accepted'],default=>[]}))$this->fail('This quotation cannot make that transition.');
            $quote->update(['status'=>$target,...($action==='send'?['sent_at'=>now()]:[]),...($action==='accept'?['accepted_at'=>now()]:[])]);
            if($action==='send'&&$quote->opportunity_id){
                $opportunity=SalesOpportunity::lockForUpdate()->findOrFail($quote->opportunity_id);
                if(in_array($opportunity->stage,['lead','qualified'])){
                    \App\Models\ActivityLog::create(['user_id'=>Auth::id(),'action'=>'opportunity.quoted','entity'=>'SalesOpportunity','entity_id'=>$opportunity->id,'description'=>$opportunity->title,'old_value'=>['stage'=>$opportunity->stage],'new_value'=>['stage'=>'quoted','quotation_id'=>$quote->id]]);
                    $opportunity->update(['stage'=>'quoted']);
                }
            }
            $this->audit($quote,'quotation.'.$target);return $quote;
        });
    }
    public function convert(CustomerQuotation $quote): CustomerQuotation
    {
        $this->permit('customers.manage');$this->permit('fulfillment.manage');$this->permit('fulfillment.view');$this->own($quote);
        return DB::transaction(function()use($quote){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $quote=CustomerQuotation::lockForUpdate()->findOrFail($quote->id);
            if($quote->converted_order_id)return $quote;
            if($quote->status!=='accepted'||$quote->expires_on->toDateString()<CompanyClock::today()->toDateString())$this->fail('Only an accepted, unexpired quotation can become an order.');
            $items=$quote->items()->get();$payload=$items->map(fn($i)=>['product_id'=>$i->product_id,'quantity'=>$i->quantity,'unit'=>$i->unit])->all();
            $prices=$this->prices($quote->customer,OrderChannel::findOrFail($quote->order_channel_id),$payload,CompanyClock::today()->toDateString());
            foreach($items as $n=>$item)if(Money::compare($prices['items'][$n]['unit_price'],$item->unit_price)!==0)$this->fail('Pricing changed since this quotation. Prepare a new quotation for customer review before conversion.');
            $intake=app(OrderHubService::class)->intake(OrderChannel::findOrFail($quote->order_channel_id),[
                'external_id'=>'quotation-'.$quote->id,'idempotency_key'=>'quotation-'.$quote->id,'customer_id'=>$quote->customer_id,'order_date'=>CompanyClock::today()->toDateString(),
                'currency'=>$quote->currency,'payment_type'=>$quote->payment_type,'billing_address'=>$quote->billing_address,'delivery_address'=>$quote->delivery_address,
                'notes'=>trim($quote->number.' · '.($quote->notes??'')),'items'=>$payload]);
            if(!$intake->sales_order_id||$intake->issues)$this->fail('The order requires current pricing or customer review. No conversion was committed.');
            $intake->order->update(['metadata'=>[...($intake->order->metadata??[]),'quotation_id'=>$quote->id,'quotation_number'=>$quote->number]]);
            $quote->update(['converted_order_id'=>$intake->sales_order_id,'status'=>'converted']);
            $this->audit($quote,'quotation.converted',['sales_order_id'=>$intake->sales_order_id]);return $quote;
        });
    }
    private function audit(CustomerQuotation $quote,string $action,array $context=[]): void
    {
        \App\Models\ActivityLog::create(['company_id'=>Auth::user()->company_id,'user_id'=>Auth::id(),'action'=>$action,'entity'=>'CustomerQuotation','entity_id'=>$quote->id,'description'=>$quote->number,'new_value'=>$context]);
        app(BusinessEventService::class)->record($action,$quote,$quote->number,['customer_id'=>$quote->customer_id,'status'=>$quote->status,'expires_on'=>$quote->expires_on->toDateString()]+$context,$action.':'.$quote->id);
    }
}
