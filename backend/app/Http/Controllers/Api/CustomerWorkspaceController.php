<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Customer,CustomerContact,CustomerLocation,CustomerQuotation,SalesOpportunity,OrderChannel,User};
use App\Services\{CustomerWorkspaceService,CustomerQuotationService};
use App\Support\CompanyCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Barryvdh\DomPDF\Facade\Pdf;

class CustomerWorkspaceController extends Controller
{
    public function __construct(private CustomerWorkspaceService $crm,private CustomerQuotationService $quotes){}
    public function index(Request $r){return response()->json($this->crm->listing($r->validate(['scope'=>'nullable|in:mine,all,unassigned','search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1'])));}
    public function show(Customer $customer){return response()->json($this->crm->customer($customer));}
    public function timeline(Customer $customer){return response()->json($this->crm->timeline($customer));}
    public function owner(Request $r,Customer $customer){$this->crm->own($customer);$d=$r->validate(['account_owner_id'=>'nullable|integer']);$id=$this->crm->owner($d['account_owner_id']??null);return DB::transaction(function()use($customer,$id){$customer=Customer::lockForUpdate()->findOrFail($customer->id);$old=$customer->account_owner_id;$customer->update(['account_owner_id'=>$id]);$this->crm->audit($customer,'customer.owner_changed',['previous'=>$old,'assigned'=>$id]);return $this->show($customer);});}
    public function relationships(Customer $customer){$this->crm->own($customer);return response()->json(['contacts'=>CustomerContact::where('customer_id',$customer->id)->orderByDesc('is_active')->orderBy('name')->limit(100)->get(),'locations'=>CustomerLocation::where('customer_id',$customer->id)->orderByDesc('is_active')->orderBy('label')->limit(100)->get()]);}
    public function contact(Request $r,Customer $customer,?int $contact=null){
        $this->crm->own($customer);$d=$r->validate(['name'=>'required|string|max:150','relationship_role'=>'nullable|string|max:100','email'=>'nullable|email|max:255','phone'=>'nullable|string|max:50','is_active'=>'sometimes|boolean']);
        return response()->json(DB::transaction(function()use($customer,$d,$contact){$row=$contact?CustomerContact::where('customer_id',$customer->id)->lockForUpdate()->findOrFail($contact):new CustomerContact(['customer_id'=>$customer->id,'created_by'=>Auth::id()]);$row->fill($d)->save();$this->crm->audit($customer,'customer.contact_saved',['contact_id'=>$row->id,'is_active'=>$row->is_active]);return $row;}),$contact?200:201);
    }
    public function location(Request $r,Customer $customer,?int $location=null){
        $this->crm->own($customer);$d=$r->validate(['label'=>'required|string|max:100','kind'=>'required|in:billing,delivery','address'=>'required|string|max:2000','contact_name'=>'nullable|string|max:150','phone'=>'nullable|string|max:50','is_active'=>'sometimes|boolean']);
        return response()->json(DB::transaction(function()use($customer,$d,$location){$row=$location?CustomerLocation::where('customer_id',$customer->id)->lockForUpdate()->findOrFail($location):new CustomerLocation(['customer_id'=>$customer->id,'created_by'=>Auth::id()]);$row->fill($d)->save();$this->crm->audit($customer,'customer.location_saved',['location_id'=>$row->id,'is_active'=>$row->is_active]);return $row;}),$location?200:201);
    }
    public function note(Request $r,Customer $customer){return response()->json($this->crm->note($customer,$r->validate(['kind'=>'required|in:note,call,meeting,complaint','note'=>'required|string|min:3|max:4000','occurred_at'=>'required|date|before_or_equal:now','idempotency_key'=>'required|string|max:100'])),201);}
    public function followup(Request $r,Customer $customer){return response()->json($this->crm->followup($customer,$r->validate(['title'=>'required|string|max:240','description'=>'nullable|string|max:2000','due_at'=>'required|date','priority'=>'required|in:low,normal,high,urgent','idempotency_key'=>'required|string|max:70'])),201);}
    public function opportunities(Request $r){$d=$r->validate(['customer_id'=>'nullable|integer','scope'=>'nullable|in:mine,all','page'=>'nullable|integer|min:1']);if(!empty($d['customer_id']))Customer::findOrFail($d['customer_id']);return response()->json(SalesOpportunity::with('customer:id,name,business_name')->when($d['customer_id']??null,fn($q,$id)=>$q->where('customer_id',$id))->when(($d['scope']??'mine')==='mine',fn($q)=>$q->where('owner_id',Auth::id()))->orderByRaw('CASE WHEN next_action_on IS NULL THEN 1 ELSE 0 END')->orderBy('next_action_on')->orderByDesc('id')->paginate(20));}
    public function opportunity(Request $r,?SalesOpportunity $opportunity=null){
        if($opportunity)$this->crm->own($opportunity);
        $d=$r->validate(['title'=>'required|string|max:200','customer_id'=>'nullable|integer','prospect_name'=>'nullable|string|max:200','stage'=>'required|in:lead,qualified,quoted,negotiation,won,lost','owner_id'=>'nullable|integer','expected_value'=>'nullable|numeric|min:0|decimal:0,2','expected_close_on'=>'nullable|date_format:Y-m-d','next_action'=>'nullable|string|max:500','next_action_on'=>'nullable|date_format:Y-m-d','notes'=>'nullable|string|max:4000']);
        if(!empty($d['customer_id']))Customer::findOrFail($d['customer_id']);abort_unless(!empty($d['customer_id'])||!empty($d['prospect_name']),422,'Choose a customer or enter a prospect name.');
        if(!in_array($d['stage'],['won','lost']))abort_unless(!empty($d['next_action'])&&!empty($d['next_action_on']),422,'Open opportunities require a next action and date.');
        $d['owner_id']=$this->crm->owner($d['owner_id']??Auth::id());
        return DB::transaction(function()use($opportunity,$d){$row=$opportunity?SalesOpportunity::lockForUpdate()->findOrFail($opportunity->id):new SalesOpportunity(['created_by'=>Auth::id(),'currency'=>CompanyCurrency::current()]);$old=$row->exists?$row->only(['stage','owner_id']):null;$row->fill($d)->save();
        \App\Models\ActivityLog::create(['user_id'=>Auth::id(),'action'=>'opportunity.saved','entity'=>'SalesOpportunity','entity_id'=>$row->id,'description'=>$row->title,'old_value'=>$old,'new_value'=>$row->only(['stage','owner_id','customer_id'])]);return response()->json($row,$opportunity?200:201);});
    }
    public function channels(){return response()->json(OrderChannel::where('enabled',true)->where('currency',CompanyCurrency::current())->get(['id','name','currency']));}
    public function quotations(Request $r){$d=$r->validate(['customer_id'=>'nullable|integer','page'=>'nullable|integer|min:1']);if(!empty($d['customer_id']))Customer::findOrFail($d['customer_id']);return response()->json(CustomerQuotation::query()->when($d['customer_id']??null,fn($q,$id)=>$q->where('customer_id',$id))->latest('id')->paginate(20)->through(fn($q)=>$this->quotes->present($q,false)));}
    public function quotation(CustomerQuotation $quotation){return response()->json($this->quotes->present($quotation));}
    public function preview(Request $r){$d=$r->validate($this->quotes->rules());return response()->json($this->quotes->prices(Customer::findOrFail($d['customer_id']),OrderChannel::findOrFail($d['order_channel_id']),$d['items'],$d['quote_date']));}
    public function createQuote(Request $r){return response()->json($this->quotes->present($this->quotes->create($r->validate($this->quotes->rules()))),201);}
    public function updateQuote(Request $r,CustomerQuotation $quotation){return response()->json($this->quotes->present($this->quotes->updateDraft($quotation,$r->validate($this->quotes->rules()+['expected_revision'=>'required|string|size:64']))));}
    public function quoteAction(CustomerQuotation $quotation,string $action){$quote=$action==='convert'?$this->quotes->convert($quotation):$this->quotes->transition($quotation,$action);return response()->json($this->quotes->present($quote));}
    public function pdf(Request $r,CustomerQuotation $quotation){$locale=$r->validate(['language'=>'nullable|in:en,sq'])['language']??'en';$quote=$this->quotes->present($quotation);return response(Pdf::loadView('customers.quotation',['quote'=>$quote,'customer'=>$quotation->customer,'company'=>Auth::user()->company,'sq'=>$locale==='sq'])->setPaper('a4')->output())->header('Content-Type','application/pdf')->header('Content-Disposition','inline; filename="'.$quotation->number.'.pdf"');}
}
