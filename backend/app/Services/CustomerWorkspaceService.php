<?php
namespace App\Services;

use App\Models\{Customer,CustomerContact,CustomerLocation,CustomerInteraction,CustomerQuotation,SalesOpportunity,SalesOrder,DailySale,OperationalTask,User,ActivityLog};
use App\Support\{CompanyClock,CompanyCurrency,Money,RequestFingerprint};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Validation\ValidationException;

/** Bounded CRM projection. Financial positions are read from their existing authorities. */
class CustomerWorkspaceService
{
    public function installed(): bool {return \Illuminate\Support\Facades\Schema::hasTable('customer_quotation_items');}
    public function can(string $permission): bool {return app(PermissionService::class)->roleHasPermission(Auth::user()->role,$permission);}
    public function permit(): void
    {
        abort_unless(Auth::user()?->company_id && $this->can('customers.manage'), 403);
        abort_unless($this->installed(), response()->json([
            'code' => 'CUSTOMER_WORKSPACE_UPGRADE_REQUIRED',
            'message' => 'Customer workspace is waiting for a database update.',
        ], 503));
    }
    public function own($record): void {abort_unless((int)$record->company_id===(int)Auth::user()->company_id,404);}
    public function owner(?int $id): ?int {if($id)User::where('company_id',Auth::user()->company_id)->findOrFail($id);return $id;}
    private function identity(Customer $customer): array {return $customer->only(['id','name','business_name','phone','email','address','billing_address','customer_type','is_active','account_owner_id']);}
    public function listing(array $filters): array
    {
        $this->permit();$q=Customer::query();
        if(($filters['scope']??'mine')==='mine')$q->where('account_owner_id',Auth::id());
        if(($filters['scope']??'')==='unassigned')$q->whereNull('account_owner_id');
        if(!empty($filters['search']))$q->where(fn($x)=>$x->where('name','like','%'.$filters['search'].'%')->orWhere('business_name','like','%'.$filters['search'].'%')->orWhere('email','like','%'.$filters['search'].'%'));
        $customerIds=(clone $q)->select('id');
        $pipeline=SalesOpportunity::where('owner_id',Auth::id())->whereNotIn('stage',['won','lost']);
        $quotes=$this->can('fulfillment.view')?CustomerQuotation::whereIn('customer_id',$customerIds)->whereIn('status',['draft','sent','accepted'])->whereDate('expires_on','>=',CompanyClock::today())->count():null;
        $tasks=$this->can('tasks.view')?OperationalTask::where('source_type','Customer')->whereIn('source_id',$customerIds)->where('assigned_user_id',Auth::id())->whereIn('status',['open','in_progress'])->where('due_at','<=',CompanyClock::today()->endOfDay())->count():null;
        $rows=$q->when($this->can('invoices.manage'),fn($q)=>$q->withCount('invoices'))->orderBy('name')->paginate(20,['id','name','business_name','phone','email','is_active','account_owner_id']);
        // Invoice count is not part of the commercial projection for staff lacking invoice access.
        $overdue=$this->can('debts.view')?app(CustomerCreditService::class)->overdueForCustomers($rows->getCollection()):[];
        $rows->through(function($c)use($overdue){$row=$this->identity($c);if($this->can('invoices.manage'))$row['invoice_count']=$c->invoices_count;if($this->can('debts.view'))$row['overdue']=$overdue[$c->id]['overdue']??'0.00';return $row;});
        return ['customers'=>$rows,'summary'=>['my_opportunities'=>$pipeline->count(),'open_quotations'=>$quotes,'followups_due'=>$tasks,
            'open_orders'=>$this->can('fulfillment.view')?SalesOrder::whereIn('customer_id',$customerIds)->whereNotIn('status',['cancelled','delivered'])->count():null],
            'recent_activity'=>CustomerInteraction::whereIn('customer_id',$customerIds)->latest('occurred_at')->limit(5)->get(['id','customer_id','kind','note','occurred_at']),
            'owners'=>User::where('company_id',Auth::user()->company_id)->orderBy('name')->limit(100)->get(['id','name']), 'currency'=>CompanyCurrency::current()];
    }
    public function customer(Customer $customer): array
    {
        $this->permit();$this->own($customer);
        $tasks=$this->can('tasks.view')?app(ActionCenterService::class)->tasks(['source_type'=>'Customer','source_id'=>$customer->id,'per_page'=>5])->items():[];
        $recent=$this->can('daily_sales.manage')?DailySale::where('customer_id',$customer->id)->latest('sale_date')->limit(5)->get(['id','sale_number','sale_date','total_amount']):[];
        $interaction=CustomerInteraction::where('customer_id',$customer->id)->latest('occurred_at')->first(['id','kind','note','occurred_at']);
        return ['customer'=>$this->identity($customer),'owner'=>$customer->account_owner_id?User::where('company_id',$customer->company_id)->find($customer->account_owner_id,['id','name']):null,
            'finance'=>$this->can('debts.view')?app(CustomerCreditService::class)->exposure($customer):null,'currency'=>CompanyCurrency::current(),
            'last_interaction'=>$interaction,'followups'=>array_slice($tasks,0,5),'recent_purchases'=>$recent,
            'open_orders'=>$this->can('fulfillment.view')?SalesOrder::where('customer_id',$customer->id)->whereNotIn('status',['delivered','cancelled'])->count():null,
            'open_quotations'=>$this->can('fulfillment.view')?CustomerQuotation::where('customer_id',$customer->id)->whereIn('status',['draft','sent','accepted'])->whereDate('expires_on','>=',CompanyClock::today())->count():null,
            'opportunities'=>SalesOpportunity::where('customer_id',$customer->id)->whereNotIn('stage',['won','lost'])->count(),
            'financial_basis'=>'Existing AR/advance and credit policy; advances and order commitments remain separate.'];
    }
    public function timeline(Customer $customer)
    {
        $this->permit();$this->own($customer);$company=Auth::user()->company_id;
        $queries=[DB::table('customer_interactions')->where('company_id',$company)->where('customer_id',$customer->id)->selectRaw("id, kind, kind as reference, note as description, occurred_at, NULL as amount")];
        if($this->can('fulfillment.view')){
            $queries[]=DB::table('sales_orders')->where('company_id',$company)->where('customer_id',$customer->id)->selectRaw("id, 'order' as kind, order_number as reference, status as description, updated_at as occurred_at, total_amount as amount");
            $queries[]=DB::table('customer_quotations')->where('company_id',$company)->where('customer_id',$customer->id)->selectRaw("id, 'quotation' as kind, number as reference, status as description, updated_at as occurred_at, total_amount as amount");
            $queries[]=DB::table('outbound_returns as r')->join('sales_orders as o',fn($j)=>$j->on('o.id','=','r.sales_order_id')->on('o.company_id','=','r.company_id'))->where('r.company_id',$company)->where('o.customer_id',$customer->id)->selectRaw("r.id, 'return' as kind, o.order_number as reference, r.status as description, r.updated_at as occurred_at, NULL as amount");
        }
        if($this->can('debts.view'))$queries[]=DB::table('customer_debt_transactions')->where('company_id',$company)->where('customer_id',$customer->id)->selectRaw("id, 'ledger' as kind, type as reference, note as description, transaction_date as occurred_at, amount");
        if($this->can('invoices.manage'))$queries[]=DB::table('invoices')->where('company_id',$company)->where('customer_id',$customer->id)->selectRaw("id, 'invoice' as kind, invoice_number as reference, status as description, updated_at as occurred_at, grand_total as amount");
        if($this->can('daily_sales.manage'))$queries[]=DB::table('daily_sales')->where('company_id',$company)->where('customer_id',$customer->id)->selectRaw("id, 'sale' as kind, sale_number as reference, payment_method as description, sale_date as occurred_at, total_amount as amount");
        foreach($queries as $q)$q->selectRaw('NULL AS source_id');
        if($this->can('fulfillment.view'))$queries[]=DB::table('business_events as e')->join('sales_orders as o',fn($j)=>$j->on('o.id','=','e.entity_id')->on('o.company_id','=','e.company_id'))->where('e.company_id',$company)->where('e.entity_type','SalesOrder')->where('o.customer_id',$customer->id)->selectRaw("e.id, 'order_event' as kind, o.order_number as reference, e.event_type as description, e.occurred_at, NULL as amount, o.id AS source_id");
        $query=array_shift($queries);foreach($queries as $q)$query->unionAll($q);
        return DB::query()->fromSub($query,'customer_activity')->orderByDesc('occurred_at')->orderBy('kind')->orderByDesc('id')->paginate(20)->through(function($row)use($customer){
            $row->url=match($row->kind){'order'=>'/fulfillment?order='.$row->id,'order_event'=>'/fulfillment?order='.$row->source_id,'return'=>'/fulfillment?order='.\App\Models\OutboundReturn::find($row->id)?->sales_order_id,'quotation'=>'/customers?customer='.$customer->id.'&tab=quotations&quote='.$row->id,'ledger'=>'/customer-debts?customer='.$customer->id,'invoice'=>'/invoices?invoice='.$row->id,'sale'=>'/daily-sales?sale='.$row->id,default=>null};return $row;
        });
    }
    public function note(Customer $customer,array $data): CustomerInteraction
    {
        $this->permit();$this->own($customer);
        return DB::transaction(function()use($customer,$data){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();$fp=RequestFingerprint::make($data+['customer_id'=>$customer->id],['idempotency_key']);
            if($old=CustomerInteraction::where('request_key',$data['idempotency_key'])->first()){abort_unless(hash_equals($old->fingerprint,$fp),409,'This request key has different interaction data.');return $old;}
            $row=CustomerInteraction::create([...collect($data)->except('idempotency_key')->all(),'customer_id'=>$customer->id,'created_by'=>Auth::id(),'request_key'=>$data['idempotency_key'],'fingerprint'=>$fp]);$this->audit($customer,'customer.interaction_recorded',['interaction_id'=>$row->id,'kind'=>$row->kind]);return $row;
        });
    }
    public function followup(Customer $customer,array $data): OperationalTask
    {
        $this->permit();$this->own($customer);abort_unless($this->can('tasks.create')&&$this->can('tasks.view')&&$this->can('debts.view'),403);
        return DB::transaction(function()use($customer,$data){
            DB::table('companies')->where('id',Auth::user()->company_id)->lockForUpdate()->first();
            $key='crm-followup:'.$customer->id.':'.$data['idempotency_key'];$fp=RequestFingerprint::make($data,['idempotency_key']);
            if($old=OperationalTask::where('dedupe_key',$key)->first()){abort_unless(hash_equals((string)data_get($old->outcome,'crm.fingerprint'),$fp),409,'This request key has different follow-up data.');return $old;}
            $task=app(ActionCenterService::class)->save(['title'=>$data['title'],'description'=>$data['description']??null,'due_at'=>$data['due_at'],'priority'=>$data['priority']??'normal','source_type'=>'Customer','source_id'=>$customer->id]);
            $task->update(['dedupe_key'=>$key,'outcome'=>['crm'=>['fingerprint'=>$fp,'prepared_by'=>Auth::id()]]]);return $task;
        });
    }
    public function attention(): array
    {
        if(!$this->can('customers.manage')||!$this->can('fulfillment.view')||!$this->installed())return [];
        return CustomerQuotation::where('status','sent')->whereDate('expires_on','<=',CompanyClock::today()->addDays(3))->orderBy('expires_on')->limit(15)->get(['id','customer_id','number','expires_on'])->map(fn($q)=>['kind'=>'customer','id'=>'quotation-'.$q->id,'title'=>$q->number,'description'=>'Quotation needs follow-up','due_at'=>$q->expires_on->toDateString(),'url'=>'/customers?customer='.$q->customer_id.'&tab=quotations&quote='.$q->id])->all();
    }
    public function audit(Customer $customer,string $action,array $data): void
    {
        ActivityLog::create(['user_id'=>Auth::id(),'action'=>$action,'entity'=>'Customer','entity_id'=>$customer->id,'description'=>$customer->name,'new_value'=>$data]);
    }
}
