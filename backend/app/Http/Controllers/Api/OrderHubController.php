<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\{OrderChannel,OrderChannelKey,OrderIntake,Customer,Product,Warehouse,SalesOrder};
use App\Services\{OrderHubService,OutboundService};
use App\Support\CompanyCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderHubController extends Controller {
    public function __construct(private readonly OrderHubService $hub){}
    private function own($model): void {abort_unless((int)$model->company_id===(int)Auth::user()->company_id,404);}
    public function index(Request $r){return response()->json($this->hub->listing($r->validate(['search'=>'nullable|string|max:100','channel_id'=>'nullable|integer','customer_id'=>'nullable|integer','product_id'=>'nullable|integer','view'=>'nullable|string|max:40','per_page'=>'nullable|integer|min:1|max:50'])));}
    public function export(Request $r){
        $d=$r->validate(['format'=>'required|in:csv,xlsx,pdf','language'=>'nullable|in:en,sq','search'=>'nullable|string|max:100','channel_id'=>'nullable|integer','view'=>'nullable|string|max:40']);
        $query=$this->hub->query($d);abort_if((clone $query)->count()>2000,422,'Narrow the order filters to export at most 2,000 orders.');
        $headers=($d['language']??'en')==='sq'?['Porosia','Referenca e jashtme','Kanali','Klienti','Gjendja','Valuta','Totali','Dorëzimi i kërkuar']:['Order','External reference','Channel','Customer','State','Currency','Total','Requested delivery'];
        $rows=$query->get()->map(fn($i)=>[$i->order?->order_number??'#'.$i->id,$i->external_id??'',$i->channel->name,$i->order?->customer?->name??data_get($i->payload,'customer.name',''),$i->order?->status??$i->state,$i->order?->currency??'',$i->order?->total_amount??'',$i->order?->requested_delivery_date?->toDateString()??''])->all();
        if($d['format']==='pdf')return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.order-hub',['headers'=>$headers,'rows'=>$rows,'company'=>Auth::user()->company->name,'title'=>($d['language']??'en')==='sq'?'Qendra e porosive':'Order Hub'])->setPaper('a4','landscape')->download('AIMS-orders.pdf');
        if($d['format']==='xlsx'){$book=new \App\Support\OpenXmlWorkbook;$book->addSheet('Orders',[$headers,...$rows]);return response($book->bytes(),200,['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Content-Disposition'=>'attachment; filename="AIMS-orders.xlsx"']);}
        return response()->streamDownload(function()use($headers,$rows){$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");foreach([$headers,...$rows] as $row)fputcsv($out,array_map(fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:$v,$row),',','"','');fclose($out);},'AIMS-orders.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }
    public function bulk(Request $r){
        $d=$r->validate(['ids'=>'required|array|min:1|max:50','ids.*'=>'required|integer|distinct','action'=>'required|in:retry,confirm,cancel','idempotency_key'=>'required|string|max:40','reason'=>'required_if:action,cancel|nullable|string|min:3|max:1000']);
        $intakes=OrderIntake::whereIn('id',$d['ids'])->get()->keyBy('id');abort_unless($intakes->count()===count($d['ids']),404);
        $result=[];foreach($d['ids'] as $id){try{$i=$intakes[$id];$key=$d['idempotency_key'].':'.$id;if($d['action']==='retry')$this->hub->process($i);elseif($d['action']==='confirm')$this->hub->confirm($i,$key);else{if(!$i->order)throw ValidationException::withMessages(['order'=>'Match this order before cancellation.']);app(OutboundService::class)->action($i->order,'cancel',['idempotency_key'=>$key,'reason'=>$d['reason']]);}$result[]=['id'=>$id,'success'=>true];}catch(ValidationException|\App\Exceptions\CustomerCreditBlockedException $e){$result[]=['id'=>$id,'success'=>false,'message'=>$e instanceof ValidationException?collect($e->errors())->flatten()->join(' '):$e->getMessage()];}}
        return response()->json(['results'=>$result]);
    }
    public function show(OrderIntake $intake){$this->own($intake);return response()->json($this->hub->detail($intake));}
    public function invoice(Request $r,OrderIntake $intake){
        $this->own($intake);
        abort_unless(app(\App\Services\PermissionService::class)->roleHasPermission(Auth::user()->role,'invoices.manage'),403);
        $d=$r->validate(['daily_sale_id'=>'required|integer']);
        return response()->json(app(\App\Services\OrderWorkflowService::class)->invoice($intake,$d['daily_sale_id']),201);
    }
    public function ready(Request $r,OrderIntake $intake){
        $this->own($intake);
        abort_unless(app(\App\Services\PermissionService::class)->roleHasPermission(Auth::user()->role,'fulfillment.pick'),403);
        return response()->json(app(\App\Services\OrderWorkflowService::class)->ready($intake,$r->validate(['verified'=>'required|accepted','idempotency_key'=>'required|string|max:50'])));
    }
    public function payment(Request $r,OrderIntake $intake){
        $this->own($intake);
        $permissions=app(\App\Services\PermissionService::class);
        abort_unless($permissions->roleHasPermission(Auth::user()->role,'debts.payments')&&$permissions->roleHasPermission(Auth::user()->role,'financial_accounts.adjust'),403);
        return response()->json(app(\App\Services\OrderWorkflowService::class)->payment($intake,$r->validate(['idempotency_key'=>'required|string|max:50','amount'=>'required|numeric|gt:0|decimal:0,2','transaction_date'=>'required|date','payment_method'=>'required|in:cash,bank_transfer,card,other','financial_account_id'=>'required|integer','note'=>'nullable|string|max:1000'])));
    }
    public function channels(){return response()->json(OrderChannel::withCount(['intakes','intakes as attention_count'=>fn($q)=>$q->where('state','attention')])->withMax('intakes','last_success_at')->orderBy('name')->get());}
    public function overview(){
        $counts=[];
        foreach(['new','attention','ready_to_allocate','packed','late'] as $view)$counts[$view]=$this->hub->query(['view'=>$view])->count();
        return response()->json($counts);
    }
    public function lookups(Request $r){
        $d=$r->validate(['kind'=>'required|in:product,customer,warehouse','search'=>'nullable|string|max:100']);
        if ($d['kind'] === 'product') return response()->json($this->hub->catalog($d['search'] ?? '')['data']);
        if ($d['kind'] === 'customer') {
            $query = Customer::where('is_active', true);
            if (filled($d['search'] ?? null)) $query->where(function ($q) use ($d) {
                foreach (['name','business_name','email','phone','tax_number','fiscal_number'] as $field) $q->orWhere($field, 'like', '%'.$d['search'].'%');
            });
            return response()->json($query->orderBy('name')->limit(20)->get(['id','name','business_name','email','phone','address','credit_status','current_debt','current_credit','credit_limit','payment_terms_days']));
        }
        $class=['product'=>Product::class,'customer'=>Customer::class,'warehouse'=>Warehouse::class][$d['kind']];
        return response()->json($class::query()->when($d['search']??null,fn($q,$s)=>$q->where('name','like','%'.$s.'%'))->orderBy('name')->limit(50)->get($d['kind']==='product'?['id','name','sku']:['id','name']));
    }
    public function report(Request $r){$d=$r->validate(['from'=>'required|date','to'=>'required|date|after_or_equal:from']);return response()->json(app(\App\Services\OrderHubReportingService::class)->summary($d['from'],$d['to']));}
    public function presets(){return response()->json(DB::table('order_hub_presets')->where('company_id',Auth::user()->company_id)->where('user_id',Auth::id())->orderBy('name')->get()->map(function($p){$p->payload=$p->kind==='template'?['intake_id'=>$p->order_intake_id]:json_decode($p->payload,true);return $p;}));}
    public function savePreset(Request $r){
        $d=$r->validate(['name'=>'required|string|max:120','kind'=>'required|in:view,template','intake_id'=>'required_if:kind,template|integer','filters'=>'nullable|array:search,view,channel_id','filters.search'=>'nullable|string|max:100','filters.view'=>'nullable|string|max:40','filters.channel_id'=>'nullable|integer']);
        $payload=$d['kind']==='template'?['intake_id'=>OrderIntake::findOrFail($d['intake_id'])->id]:($d['filters']??[]);
        $id=DB::table('order_hub_presets')->insertGetId(['company_id'=>Auth::user()->company_id,'user_id'=>Auth::id(),'name'=>$d['name'],'kind'=>$d['kind'],'order_intake_id'=>$payload['intake_id']??null,'payload'=>json_encode($d['kind']==='template'?[]:$payload),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['id'=>$id],201);
    }
    public function deletePreset(int $preset){$count=DB::table('order_hub_presets')->where('company_id',Auth::user()->company_id)->where('user_id',Auth::id())->where('id',$preset)->delete();abort_unless($count,404);return response()->noContent();}
    public function previewImport(Request $r){
        $r->validate(['file'=>'required|file|max:256|mimes:csv,txt']);$handle=fopen($r->file('file')->getRealPath(),'r');
        try{$header=fgetcsv($handle,0,',','"','');abort_unless(is_array($header)&&array_diff(['external_id','customer_email','sku','quantity'],$header)===[],422,'CSV headers must include external_id, customer_email, sku, quantity.');$orders=[];$row=1;
            while(($values=fgetcsv($handle,0,',','"',''))!==false){$row++;abort_if($row>501,422,'Import at most 500 lines.');abort_unless(count($values)===count($header),422,'Invalid CSV column count at row '.$row);$v=array_combine($header,$values);abort_unless(trim($v['external_id'])!=='',422,'Each row needs an external order reference.');$id=$v['external_id'];
                $orders[$id]??=['external_id'=>$id,'idempotency_key'=>'csv-'.hash('sha256',$id),'customer'=>['email'=>$v['customer_email']],'order_date'=>$v['order_date']??today()->toDateString(),'payment_type'=>$v['payment_type']??'cash','items'=>[]];
                abort_unless($orders[$id]['customer']['email']===$v['customer_email'],422,'Conflicting customers for reference '.$id);
                $line=['sku'=>$v['sku'],'quantity'=>$v['quantity']];if(filled($v['unit']??null))$line['unit']=$v['unit'];if(filled($v['unit_price']??null))$line['unit_price']=$v['unit_price'];$orders[$id]['items'][]=$line;
            }
            abort_unless(count($orders)>0&&count($orders)<=100,422,'Import between 1 and 100 orders.');
            foreach($orders as $payload)validator($payload,$this->hub->rules())->validate();
            return response()->json(['orders'=>array_values($orders),'count'=>count($orders),'lines'=>$row-1]);
        }finally{fclose($handle);}
    }
    public function commitImport(Request $r,OrderChannel $channel){$this->own($channel);$r->validate(['orders'=>'required|array|min:1|max:100']);$orders=[];foreach($r->input('orders') as $p)$orders[]=validator($p,$this->hub->rules())->validate();return response()->json(DB::transaction(fn()=>collect($orders)->map(fn($p)=>$this->hub->detail($this->hub->intake($channel,$p)))));}
    public function channel(Request $r,?OrderChannel $channel=null){if($channel?->exists)$this->own($channel);
        $d=$r->validate(['name'=>'required|string|max:120','type'=>'required|in:manual,webstore,b2b_portal,api,marketplace,import,other','enabled'=>'required|boolean','external_reference'=>'nullable|string|max:150','warehouse_id'=>'nullable|integer','currency'=>'required|string|size:3','allow_guest'=>'required|boolean','acceptance'=>'required|in:review,automatic','oversale_policy'=>'required|in:do_not_accept,accept_backorder,require_review','price_tolerance'=>'required|numeric|min:0|decimal:0,2','configuration'=>'nullable|array:require_address,price_rules,confirmation_hours','configuration.require_address'=>'boolean','configuration.confirmation_hours'=>'nullable|integer|min:1|max:720','configuration.price_rules'=>'nullable|array|max:20','configuration.price_rules.*'=>'array:name,enabled,kind,value,product_id,customer_id,minimum_quantity,starts_on,ends_on','configuration.price_rules.*.name'=>'required|string|max:100','configuration.price_rules.*.enabled'=>'required|boolean','configuration.price_rules.*.kind'=>'required|in:percentage,fixed','configuration.price_rules.*.value'=>'required|numeric|min:0|decimal:0,2','configuration.price_rules.*.product_id'=>'nullable|integer','configuration.price_rules.*.customer_id'=>'nullable|integer','configuration.price_rules.*.minimum_quantity'=>'required|numeric|gt:0|decimal:0,3','configuration.price_rules.*.starts_on'=>'nullable|date_format:Y-m-d','configuration.price_rules.*.ends_on'=>'nullable|date_format:Y-m-d']);
        if(!empty($d['warehouse_id']))Warehouse::findOrFail($d['warehouse_id']);
        foreach($d['configuration']['price_rules']??[] as $rule){if(!empty($rule['product_id']))Product::findOrFail($rule['product_id']);if(!empty($rule['customer_id']))Customer::findOrFail($rule['customer_id']);abort_if($rule['kind']==='percentage'&&$rule['value']>100,422,'A percentage discount cannot exceed 100%.');abort_if(!empty($rule['starts_on'])&&!empty($rule['ends_on'])&&$rule['ends_on']<$rule['starts_on'],422,'Promotion end date must follow its start date.');}
        abort_unless(strtoupper($d['currency'])===CompanyCurrency::current(),422,'Use company currency until FX acceptance is configured.');
        $d['currency']=strtoupper($d['currency']);$d['company_id']=$r->user()->company_id;
        if($channel?->exists){$channel->update($d);return response()->json($channel);}
        return response()->json(OrderChannel::create($d),201);
    }
    public function mappings(OrderChannel $channel){$this->own($channel);return response()->json(DB::table('order_channel_mappings')->where('company_id',Auth::user()->company_id)->where('order_channel_id',$channel->id)->orderBy('id')->paginate(50));}
    public function mapping(Request $r,OrderChannel $channel){$this->own($channel);
        $d=$r->validate(['kind'=>'required|in:customer,product','external_id'=>'required|string|max:150','entity_id'=>'required|integer']);
        ($d['kind']==='product'?Product::class:Customer::class)::findOrFail($d['entity_id']);
        DB::table('order_channel_mappings')->updateOrInsert(['company_id'=>Auth::user()->company_id,'order_channel_id'=>$channel->id,'kind'=>$d['kind'],'external_id'=>$d['external_id']],[$d['kind'].'_id'=>$d['entity_id'],'created_at'=>now(),'updated_at'=>now()]);
        return $this->mappings($channel);
    }
    public function key(Request $r,OrderChannel $channel){$this->own($channel);
        $d=$r->validate(['scopes'=>'required|array|min:1|max:5','scopes.*'=>'in:orders:create,orders:read,orders:cancel,catalog:read,availability:read','customer_id'=>'nullable|integer','expires_at'=>'required|date|after:now']);
        if(!empty($d['customer_id']))Customer::findOrFail($d['customer_id']);
        $token=Str::random(64);$secret=Str::random(64);
        $key=OrderChannelKey::create(['company_id'=>Auth::user()->company_id,'order_channel_id'=>$channel->id,'user_id'=>Auth::id(),'customer_id'=>$d['customer_id']??null,'token_hash'=>hash('sha256',$token),'webhook_secret'=>$secret,'scopes'=>$d['scopes'],'expires_at'=>$d['expires_at']]);
        return response()->json(['id'=>$key->id,'token'=>$token,'webhook_secret'=>$secret,'expires_at'=>$key->expires_at],201)->header('Cache-Control','no-store');
    }
    public function keys(OrderChannel $channel){$this->own($channel);return response()->json(OrderChannelKey::where('company_id',Auth::user()->company_id)->where('order_channel_id',$channel->id)->get());}
    public function revoke(OrderChannel $channel,int $key){$this->own($channel);OrderChannelKey::where('company_id',Auth::user()->company_id)->where('order_channel_id',$channel->id)->findOrFail($key)->update(['revoked_at'=>now()]);return response()->json(['revoked'=>true]);}
    public function store(Request $r,OrderChannel $channel){$this->own($channel);return response()->json($this->hub->detail($this->hub->intake($channel,$r->validate($this->hub->rules()))),201);}
    public function manual(Request $r){
        $payload=$r->validate($this->hub->rules());
        return response()->json(DB::transaction(function()use($payload){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $channel=OrderChannel::firstOrCreate(['company_id'=>Auth::user()->company_id,'name'=>'Manual'],['type'=>'manual','enabled'=>true,'currency'=>CompanyCurrency::current(),'allow_guest'=>true,'oversale_policy'=>'require_review','acceptance'=>'review']);
            return $this->hub->detail($this->hub->intake($channel,$payload));
        }),201);
    }
    public function resolve(Request $r,OrderIntake $intake){$this->own($intake);$d=$r->validate([...$this->hub->rules(),'review_reason'=>'nullable|string|min:5|max:1000']);$reason=$d['review_reason']??null;unset($d['review_reason']);return response()->json($this->hub->detail($this->hub->resolve($intake,$d,$reason)));}
    public function action(Request $r,OrderIntake $intake,string $action){$this->own($intake);
        $d=$r->validate(['idempotency_key'=>'required|string|max:70','reason'=>'nullable|string|min:3|max:2000','notes'=>'nullable|string|max:4000']);
        if($action==='retry')return response()->json($this->hub->detail($this->hub->process($intake)));
        if($action==='confirm'){
            try{return response()->json($this->hub->confirm($intake,$d['idempotency_key']));}
            catch(ValidationException|\App\Exceptions\CustomerCreditBlockedException $e){
                $message=$e instanceof ValidationException?collect($e->errors())->flatten()->join(' '):$e->getMessage();
                $intake->update(['state'=>'attention','last_error'=>mb_substr($message,0,1000)]);
                throw $e;
            }
        }
        if($action==='review-stock'){$r->validate(['reason'=>'required|string|min:5|max:1000']);return response()->json($this->hub->reviewStock($intake,$d['reason']));}
        if($action==='request-approval'){$r->validate(['reason'=>'required|string|min:5|max:1000']);return response()->json($this->hub->requestApproval($intake,$d['reason']));}
        if($action==='tracking-link')return response()->json(['token'=>$this->hub->trackingLink($intake)])->header('Cache-Control','no-store');
        if($action==='note'){$this->hub->audit($intake,'order.internal_note',['note'=>$d['notes']??''],$d['idempotency_key']);return response()->json($this->hub->detail($intake));}
        abort_unless(in_array($action,['cancel','credit-override']),404);abort_unless($intake->order,422,'Match the order first.');
        app(OutboundService::class)->action($intake->order,$action,$d);return response()->json($this->hub->detail($intake->fresh()));
    }
    public function reorder(Request $r,OrderIntake $intake){$this->own($intake);
        $d=$r->validate(['idempotency_key'=>'required|string|max:100']);$o=$intake->order;abort_unless($o,422);
        $payload=$intake->payload;$payload['items']=$o->items->map(fn($i)=>['product_id'=>$i->product_id,'unit'=>$i->unit,'quantity'=>$i->quantity])->all();
        $payload['idempotency_key']=$d['idempotency_key'];unset($payload['external_id'],$payload['requested_delivery_date']);$payload['order_date']=today()->toDateString();
        return response()->json($this->hub->detail($this->hub->intake($intake->channel,$payload)),201);
    }
    public function publicCreate(Request $r){
        $d=$r->validate($this->hub->rules());$key=$r->attributes->get('order_channel_key');if($key->customer_id)$d['customer_id']=$key->customer_id;
        $channel=$r->attributes->get('order_channel');$i=$this->hub->intake($channel,$d);
        if($channel->acceptance==='automatic'&&$i->sales_order_id&&!$i->issues&&!$i->order->confirmed_at){
            try{$this->hub->confirm($i,'auto-'.$i->id);}catch(ValidationException|\App\Exceptions\CustomerCreditBlockedException $e){$i->update(['state'=>'attention','last_error'=>mb_substr($e->getMessage(),0,1000)]);}
        }
        return response()->json(['id'=>$i->id,...$this->hub->publicStatus($i->fresh())],201);
    }
    private function externalIntake(Request $r,int $id):OrderIntake {
        $channel=$r->attributes->get('order_channel');$key=$r->attributes->get('order_channel_key');
        return OrderIntake::where('order_channel_id',$channel->id)->when($key->customer_id,fn($q,$c)=>$q->where(fn($x)=>$x->whereHas('order',fn($o)=>$o->where('customer_id',$c))->orWhere(fn($y)=>$y->whereNull('sales_order_id')->where('payload->customer_id',$c))))->findOrFail($id);
    }
    public function publicShow(Request $r,int $id){return response()->json($this->hub->publicStatus($this->externalIntake($r,$id)));}
    public function publicCancel(Request $r,int $id){$i=$this->externalIntake($r,$id);$d=$r->validate(['idempotency_key'=>'required|string|max:70','reason'=>'required|string|min:3|max:1000']);abort_unless($i->order,422);app(OutboundService::class)->action($i->order,'cancel',$d);return response()->json($this->hub->publicStatus($i->fresh()));}
    public function publicCatalog(Request $r){return response()->json($this->hub->catalog(mb_substr($r->query('search',''),0,100)));}
    public function publicAvailability(Request $r){
        $catalog=$this->hub->catalog(mb_substr($r->query('search',''),0,100));
        $catalog['data']=array_map(fn($p)=>collect($p)->only(['id','sku','unit','availability'])->all(),$catalog['data']);
        return response()->json($catalog);
    }
    public function portal(Request $r){
        $key=$r->attributes->get('order_channel_key');abort_unless($key->customer_id,403,'This portal requires a customer-bound credential.');
        $customer=Customer::findOrFail($key->customer_id);
        $orders=OrderIntake::where('order_channel_id',$key->order_channel_id)->where(function($q)use($customer){$q->whereHas('order',fn($o)=>$o->where('customer_id',$customer->id))->orWhere('payload->customer_id',$customer->id);})->latest('id')->paginate(20);
        return response()->json(['customer'=>['id'=>$customer->id,'name'=>$customer->name],'credit'=>collect(app(\App\Services\CustomerCreditService::class)->exposure($customer))->only(['current_debt','advance','total_exposure','available_credit','credit_limit','total_overdue'])->all(),'orders'=>[...$orders->toArray(),'data'=>$orders->map(fn($i)=>['id'=>$i->id,...$this->hub->publicStatus($i)])->all()]])->header('Cache-Control','no-store');
    }
    public function webhook(Request $r){
        $key=$r->attributes->get('order_channel_key');$timestamp=$r->header('X-AIMS-Timestamp');$signature=$r->header('X-AIMS-Signature');
        abort_unless(ctype_digit((string)$timestamp)&&abs(time()-(int)$timestamp)<=300,401,'Webhook timestamp expired.');
        abort_unless(is_string($signature)&&hash_equals(hash_hmac('sha256',$timestamp.'.'.$r->getContent(),$key->webhook_secret),$signature),401,'Webhook signature is invalid.');
        return $this->publicCreate($r);
    }
    public function tracking(string $token){
        abort_unless(strlen($token)===64,404);
        $i=OrderIntake::withoutGlobalScopes()->where('tracking_hash',hash('sha256',$token))->where('tracking_expires_at','>',now())->firstOrFail();
        $actor=\App\Models\User::where('company_id',$i->company_id)->firstOrFail();$previous=Auth::user();Auth::setUser($actor);
        try{return response()->json($this->hub->publicStatus($i))->header('Cache-Control','no-store');}finally{$previous?Auth::setUser($previous):Auth::forgetUser();}
    }
}
