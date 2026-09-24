<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\{SalesOrder,SalesOrderItem,PickTask,PickWave,OutboundDispatch,OutboundReturn,OperationalException};
use App\Services\{OutboundService,PermissionService};
use Illuminate\Http\Request;

class FulfillmentController extends Controller
{
    public function __construct(private readonly OutboundService $service){}
    public function index(Request $r){return response()->json($this->service->orders($r->only(['search','status','view','wave','customer_id','product_id','warehouse_id','per_page'])));}
    public function show(SalesOrder $salesOrder){return response()->json($this->service->detail($salesOrder));}
    public function store(Request $r){return response()->json($this->service->detail($this->service->create($r->validate($this->orderRules()))),201);}
    private function orderRules():array{return ['customer_id'=>'required|integer','order_date'=>'required|date','requested_delivery_date'=>'nullable|date','priority'=>'nullable|integer|min:1|max:3','currency'=>'nullable|string|size:3','payment_type'=>'required|in:cash,credit,prepaid','warehouse_id'=>'nullable|integer','notes'=>'nullable|string|max:4000','idempotency_key'=>'required|string|max:100','items'=>'required|array|min:1|max:100','items.*.product_id'=>'required|integer','items.*.quantity'=>'required|numeric|gt:0|decimal:0,3','items.*.unit'=>'nullable|string|max:30','items.*.unit_price'=>'required|numeric|min:0|decimal:0,2'];}
    public function action(Request $r,SalesOrder $salesOrder,string $action){
        $permission=match($action){'pick','pack','unpack'=>'fulfillment.pick','dispatch','delivery'=>'fulfillment.dispatch',default=>'fulfillment.manage'};
        abort_unless(app(PermissionService::class)->roleHasPermission($r->user()->role,$permission),403);
        $rules=['idempotency_key'=>'required|string|max:100','reason'=>'nullable|string|max:2000','notes'=>'nullable|string|max:4000'];
        $rules+=match($action){
            'edit'=>$this->orderRules(),
            'reserve'=>['allocations'=>'sometimes|array|max:200','allocations.*.sales_order_item_id'=>'required|integer','allocations.*.warehouse_id'=>'required|integer','allocations.*.location_id'=>'nullable|integer','allocations.*.inventory_lot_id'=>'nullable|integer','allocations.*.quantity'=>'required|numeric|gt:0|decimal:0,3'],
            'assign'=>['task_id'=>'required|integer','assigned_to'=>'nullable|integer'],
            'release'=>['allocation_id'=>'nullable|integer'], 'allocate'=>['assigned_to'=>'nullable|integer'],
            'pick'=>['allocation_id'=>'required|integer','location_id'=>'nullable|integer','inventory_lot_id'=>'nullable|integer','quantity'=>'required|numeric|min:0|decimal:0,3','barcode'=>'nullable|string|max:191','manual_verification'=>'nullable|boolean'],
            'pack'=>['items'=>'required|array|min:1|max:200','items.*.allocation_id'=>'required|integer','items.*.quantity'=>'required|numeric|gt:0|decimal:0,3','type'=>'nullable|string|max:40','weight'=>'nullable|numeric|gt:0','dimensions'=>'nullable|string|max:100'],
            'unpack'=>['package_id'=>'required|integer'],
            'dispatch'=>['package_ids'=>'required|array|min:1|max:200','package_ids.*'=>'integer|distinct','driver'=>'nullable|string|max:100','vehicle'=>'nullable|string|max:100'],
            'delivery'=>['dispatch_id'=>'required|integer','recipient'=>'nullable|string|max:150','proof_reference'=>'nullable|string|max:255','failure_reason'=>'nullable|string|max:2000','items'=>'sometimes|array|max:200','items.*.package_item_id'=>'required|integer|distinct','items.*.quantity'=>'required|numeric|min:0|decimal:0,3'],
            'return'=>['package_item_id'=>'nullable|integer','stage'=>'required|in:requested,authorized,received,resolved,cancelled','allocation_id'=>'required_if:stage,requested|integer','quantity'=>'required_if:stage,requested|numeric|gt:0|decimal:0,3','return_id'=>'required_unless:stage,requested|integer','disposition'=>'nullable|in:sellable,quarantine,damaged,scrap','financial_account_id'=>'nullable|integer'],
            'confirm','cancel','credit-override'=>[],default=>abort(404)};
        return response()->json($this->service->action($salesOrder,$action,$r->validate($rules)));
    }
    public function candidates(SalesOrderItem $salesOrderItem){return response()->json($this->service->candidates($salesOrderItem));}
    public function queues(Request $r){
        app(\App\Services\OutboundReportingService::class)->refreshExceptions();
        return response()->json(['tasks'=>PickTask::query()->with(['order.customer:id,name','assignee:id,name'])->whereNotIn('status',['completed','cancelled'])->when($r->warehouse_id,fn($q,$id)=>$q->where('warehouse_id',$id))->when($r->wave,fn($q,$id)=>$q->where('pick_wave_id',$id))->orderBy('priority')->orderBy('id')->paginate(30,['*'],'task_page'),
            'waves'=>PickWave::query()->withCount('tasks')->latest()->limit(30)->get(),
            'pickers'=>app(PermissionService::class)->roleHasPermission($r->user()->role,'fulfillment.manage')
                ? \App\Models\User::query()->where('company_id',$r->user()->company_id)->select(['id','name','role'])->get()->filter(fn($u)=>app(PermissionService::class)->roleHasPermission($u->role,'fulfillment.pick'))->values() : [],
            'metrics'=>app(\App\Services\OutboundReportingService::class)->metrics(),
            'counts'=>SalesOrder::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total','status'),
            'late_orders'=>SalesOrder::query()->whereNotIn('status',['delivered','cancelled'])->whereDate('requested_delivery_date','<',now())->count(),
            'failed_deliveries'=>OutboundDispatch::query()->where('status','failed')->count(),
            'returns_awaiting_action'=>OutboundReturn::query()->whereNotIn('status',['resolved','cancelled'])->count(),
            'exceptions'=>OperationalException::query()->where('entity_type','SalesOrder')->where('status','open')->latest()->limit(30)->get()]);
    }
    public function wave(Request $r){return response()->json($this->service->wave($r->validate(['warehouse_id'=>'required|integer','task_ids'=>'required|array|min:1|max:100','task_ids.*'=>'integer|distinct'])),201);}
    public function slip(SalesOrder $salesOrder){
        $data=$this->service->detail($salesOrder);
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.packing-slip',['order'=>$data,'company'=>auth()->user()->company])->download($salesOrder->order_number.'-packing-slip.pdf');
    }
}
