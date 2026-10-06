<?php
namespace App\Services;

use App\Models\{OperationalTask, OperationalException, AccountingException, AutomationExecution};
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ActionCenterService
{
    public function tasks(array $filters=[])
    {
        $q=OperationalTask::query();
        $allowed=array_values(array_filter(array_keys(AutomationRegistry::SOURCES),fn($type)=>app(AutomationRegistry::class)->canAccessSource($type)));
        $q->where(fn($q)=>$q->whereNull('source_type')->orWhereIn('source_type',$allowed));
        if ($this->can('documents.view')) $q->where(fn($q)=>$q->whereNull('source_type')->orWhere('source_type','!=','Document')->orWhereIn('source_id',app(DocumentService::class)->visibleQuery()->select('id')));
        if (!$this->can('tasks.manage') || ($filters['view'] ?? '')==='mine') $q->where(fn($q)=>$q->where('assigned_user_id',Auth::id())->orWhere('created_by',Auth::id())->orWhere(fn($q)=>$q->whereNull('assigned_user_id')->where('assigned_role',Auth::user()->role)));
        if (!empty($filters['source_type'])) $q->where('source_type',$filters['source_type']);
        if (!empty($filters['source_id'])) $q->where('source_id',(int)$filters['source_id']);
        if (!empty($filters['task'])) $q->whereKey((int)$filters['task']);
        if (($filters['status'] ?? 'active')==='active') $q->whereIn('status',['open','in_progress']);
        elseif (!empty($filters['status']) && $filters['status']!=='all') $q->where('status',$filters['status']);
        if (!empty($filters['priority'])) $q->where('priority',$filters['priority']);
        if (!empty($filters['q'])) $q->where('title','like','%'.mb_substr($filters['q'],0,100).'%');
        return $q->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('due_at')->latest('id')->paginate(50)->through(fn($task)=>$this->present($task));
    }

    public function present(OperationalTask $task): array
    {
        if ($task->source_type && !(app(AutomationRegistry::class)->url($task->source_type,(int)$task->source_id))) abort(403);
        if ($task->source_type==='Document' && !app(DocumentService::class)->visibleQuery()->whereKey($task->source_id)->exists()) abort(403);
        return $task->toArray()+['url'=>app(AutomationRegistry::class)->url($task->source_type ?? '',(int)$task->source_id),
            'overdue'=>$task->due_at?->isPast() && in_array($task->status,['open','in_progress']),
            'can_complete'=>$this->can('tasks.complete') && ($this->can('tasks.manage') || $task->assigned_user_id===Auth::id() || $task->created_by===Auth::id() || (!$task->assigned_user_id && $task->assigned_role===Auth::user()->role))];
    }

    public function save(array $data, ?OperationalTask $task=null): OperationalTask
    {
        $service=app(AutomationService::class);
        $service->authorize($task?'tasks.complete':'tasks.create');
        if ($task && !$this->present($task)['can_complete']) abort(403);
        $data=validator($data,[
            'title'=>($task?'sometimes':'required').'|string|max:240','description'=>'nullable|string|max:2000',
            'status'=>'sometimes|in:open,in_progress,completed,cancelled','priority'=>'sometimes|in:low,normal,high,urgent',
            'assigned_user_id'=>'nullable|integer','assigned_role'=>'nullable|in:admin,manager,staff',
            'due_at'=>'nullable|date','source_type'=>'nullable|string','source_id'=>'nullable|integer',
            'outcome_note'=>'nullable|string|max:2000',
        ])->validate();
        if ($task && array_diff(array_keys($data),['status','outcome_note'])) $service->authorize('tasks.manage');
        $service->validateAssignee($data);
        if (!empty($data['source_type']) && (!app(AutomationRegistry::class)->url($data['source_type'],(int)($data['source_id']??0)) || !app(AutomationRegistry::class)->source($data['source_type'],(int)($data['source_id']??0)))) throw ValidationException::withMessages(['source'=>['Select an authorized existing company record.']]);
        $note=$data['outcome_note'] ?? null; unset($data['outcome_note']);
        if (in_array($data['status']??null,['completed','cancelled'])) {
            $data['completed_at']=now();
            if (empty($task?->outcome['draft'])) $data['dedupe_key']=null;
            $data['outcome']=array_merge($task?->outcome ?? [],['decision'=>$data['status'],'note'=>$note,'user_id'=>Auth::id(),'at'=>now()->toIso8601String()]);
        }
        elseif (in_array($data['status']??null,['open','in_progress'])) $data['completed_at']=null;
        if ($task) { $task->update($data); }
        else $task=OperationalTask::create($data+['company_id'=>Auth::user()->company_id,'created_by'=>Auth::id(),'assigned_user_id'=>Auth::id()]);
        $service->audit('task.'.($data['status'] ?? 'saved'),$task,$data);
        return $task;
    }

    public function snapshot(array $filters=[]): array
    {
        $tasks=$this->tasks($filters);
        if (!empty($filters['summary_only'])) return ['summary'=>[
            'tasks'=>$tasks->total(),
            'approvals'=>$this->can('approvals.view')?app(ApprovalService::class)->pendingForUser(Auth::user())->count():0,
            'issues'=>OperationalTask::whereIn('id',collect($tasks->items())->pluck('id'))->whereIn('priority',['high','urgent'])->count(),
        ]];
        $items=[];$intelligentProducts=[];
        if($this->can('shipments.view'))foreach(\App\Models\ShipmentIntelligence::where('is_current',true)->whereIn('risk',['CRITICAL','DELAYED','AT_RISK','WATCH'])->whereHas('shipment',fn($q)=>$q->whereNull('archived_at'))->latest('id')->limit(25)->get() as $r){
            $items[]=['kind'=>'shipment','id'=>'logistics-'.$r->shipment_id,'title'=>$r->evidence['reference'],'description'=>$r->evidence['reason'],
                'values'=>['risk'=>$r->risk,'exposed_products'=>$this->can('inventory.view')?$r->impact['exposed_products']:null],
                'url'=>'/shipments/my-shipments?view=intelligence&shipment='.$r->shipment_id,'due_at'=>$r->eta['predicted']];
        }
        if($this->can('supplier_performance.view')&&$this->can('purchase_orders.view')){
            foreach(\App\Models\SupplierDeliveryRisk::with('purchaseOrder')->where('risk','high')->latest()->limit(20)->get() as $r)
                if($r->purchaseOrder&&!in_array($r->purchaseOrder->status,['completed','received','cancelled']))$items[]=['kind'=>'procurement','id'=>'supplier-risk-'.$r->id,'title'=>$r->purchaseOrder->po_number,'description'=>'Review incoming delivery risk','url'=>'/suppliers?supplier='.$r->supplier_id,'due_at'=>$r->evidence['original_promise']??null];
            foreach(\App\Models\SupplierLeadModel::where('status','candidate')->latest()->limit(10)->get() as $m)
                if(app(SupplierLearningService::class)->gates($m,app(SupplierIntelligenceService::class)->active($m->supplier_id))['eligible'])$items[]=['kind'=>'procurement','id'=>'supplier-model-'.$m->id,'title'=>'Supplier delivery model','description'=>'Review qualified candidate','url'=>'/suppliers?supplier='.$m->supplier_id,'due_at'=>null];
        }
        if($this->can('analytics.view')&&$this->can('inventory.view')){
            foreach(\App\Models\EnterpriseDecision::whereIn('status',['open','reviewed'])->whereIn('severity',['critical','high','warning'])->latest('id')->limit(30)->get() as $d){
                if(in_array($d->product_id,$intelligentProducts,true))continue;$intelligentProducts[]=$d->product_id;
                $items[]=['kind'=>'stock','id'=>'decision-'.$d->id,'title'=>$d->evidence['product']['name'],'description'=>'Review enterprise decision','url'=>'/inventory-intelligence?view=decisions&decision='.$d->id,'due_at'=>$d->evidence['forecast']['stockout_date']??null];
            }
            foreach(\App\Models\InventoryRecommendation::with('product')->whereNotNull('planning_key')->whereIn('status',['open','viewed'])->whereIn('explanation->optimization_state',['critical','potential_stockout','reorder_now','reorder_soon'])->whereHas('prediction',fn($q)=>$q->where('valid_until','>',now()))->latest('id')->limit(20)->get() as $r){
                if(in_array($r->product_id,$intelligentProducts,true))continue;$intelligentProducts[]=$r->product_id;
                $transfer=\App\Models\BusinessEvent::where('entity_type','InventoryRecommendation')->where('entity_id',$r->id)->where('event_type','inventory.optimization.transfer_opportunity')->exists();
                $items[]=['kind'=>'stock','id'=>'planning-'.$r->id,'title'=>$r->product?->name,'description'=>$transfer?'Review warehouse transfer opportunity':'Review inventory purchasing plan','url'=>'/inventory-intelligence?view=planning&product='.$r->product_id.($r->warehouse_id?'&warehouse_id='.$r->warehouse_id:''),'due_at'=>$r->explanation['reorder_date']??null];
            }
            foreach(\App\Models\InventoryIntelligenceAlert::with('product')->where('status','open')->latest()->limit(20)->get() as $a)
                $items[]=['kind'=>'stock','id'=>'intelligence-'.$a->id,'title'=>$a->product?->name,'description'=>'Review forecast performance and data quality','url'=>'/inventory-intelligence?product='.$a->product_id.'&horizon='.($a->horizon?:30),'due_at'=>null];
            foreach(\App\Models\InventoryForecastModel::where('status','candidate')->latest()->limit(10)->get() as $m){
                $p=\App\Models\Product::find($m->product_id);if(!$p)continue;
                $learning=app(InventoryLearningService::class);if(!$learning->gates($m,$learning->active($p,$m->horizon))['eligible'])continue;
                $items[]=['kind'=>'stock','id'=>'model-'.$m->id,'title'=>$p->name,'description'=>'Review forecast model candidate','url'=>'/inventory-intelligence?product='.$p->id.'&horizon='.$m->horizon,'due_at'=>null];
            }
            foreach(\App\Models\InventoryRecommendation::with('product')->whereIn('status',['open','viewed'])->whereIn('risk',['high','watch'])
                ->whereHas('prediction',fn($q)=>$q->where('valid_until','>',now())->where('value->horizon',30))->latest()->limit(25)->get() as $r){
                if(in_array($r->product_id,$intelligentProducts,true))continue;$intelligentProducts[]=$r->product_id;
                $items[]=['kind'=>'stock','id'=>$r->product_id,'title'=>$r->product?->name,'description'=>'Review forecast replenishment','url'=>'/inventory-intelligence?product='.$r->product_id,'due_at'=>$r->explanation['reorder_date']??null];
            }
        }
        if ($this->can('analytics.data_quality') && $this->can('analytics.finance')) foreach(\App\Models\AnalyticsIssue::whereNull('resolved_at')->where('severity','problem')->latest()->limit(25)->get() as $issue) $items[]=['kind'=>'analytics','id'=>$issue->id,'title'=>$issue->code,'description'=>'Analytics data-quality problem','url'=>'/analytics?tab=data_quality&issue='.$issue->id,'due_at'=>null];
        if ($this->can('replenishment.view')) {
            $suggestions=app(ReplenishmentService::class)->suggestions();
            $suggestions['suggestions']=array_values(array_filter($suggestions['suggestions'],fn($s)=>!in_array($s['product_id'],$intelligentProducts,true)));
            foreach(collect($suggestions['suggestions'])->where('needs_reorder',true)->take(25) as $s) $items[]=['kind'=>'stock','id'=>$s['product_id'],'title'=>$s['product_name'],'description'=>$s['reason'],'values'=>['available'=>$s['calculation']['available'],'minimum'=>$s['calculation']['minimum_stock'],'incoming'=>$s['calculation']['confirmed_incoming']],'url'=>'/operations-center?tab=replenishment','due_at'=>null];
        }
        if ($this->can('purchase_orders.view')) foreach(\App\Models\PurchaseOrder::whereNotIn('status',['completed','received','cancelled'])->where('expected_at','<',today())->oldest('expected_at')->limit(25)->get() as $o) $items[]=['kind'=>'procurement','id'=>$o->id,'title'=>$o->po_number,'description'=>'Purchase order overdue','url'=>'/purchase-orders?po='.$o->id,'due_at'=>$o->expected_at];
        if ($this->can('documents.view')) foreach(app(DocumentService::class)->visibleQuery()->where('status','!=','archived')->whereNotNull('expiry_date')->where('expiry_date','<=',today()->addDays(30))->oldest('expiry_date')->limit(25)->get() as $d) $items[]=['kind'=>'document','id'=>$d->id,'title'=>$d->title,'description'=>'Review or replace expiring document','url'=>'/documents?document='.$d->id,'due_at'=>$d->expiry_date];
        if ($this->can('debts.view')) {
            foreach(\App\Models\Customer::whereHas('debtTransactions',fn($q)=>$q->where('due_date','<',today()))->limit(100)->get() as $customer) {
                $credit=app(CustomerCreditService::class)->exposure($customer);
                if ((float)$credit['overdue']>0) $items[]=['kind'=>'customer','id'=>$customer->id,'title'=>$customer->name,'description'=>'Customer payment overdue','values'=>['overdue'=>$credit['overdue']],'url'=>'/customer-debts?customer='.$customer->id,'due_at'=>$credit['oldest_overdue_date']];
            }
        }
        if ($this->can('approvals.view')) foreach(app(ApprovalService::class)->pendingForUser(Auth::user())->take(25) as $a) $items[]=['kind'=>'approval','id'=>$a->id,'title'=>$a->rule_type,'description'=>$a->status,'url'=>'/procurement?view=approvals','due_at'=>null];
        if ($this->can('control_tower.view')) foreach(OperationalException::whereIn('status',['active','open'])->latest()->limit(25)->get() as $e) $items[]=['kind'=>'shipment','id'=>$e->id,'title'=>$e->exception_type,'description'=>$e->description,'url'=>$e->next_action['url'] ?? '/control-tower','due_at'=>null];
        if ($this->can('accounting.integrity.view')) foreach(AccountingException::where('status','open')->latest()->limit(25)->get() as $e) $items[]=['kind'=>'finance','id'=>$e->id,'title'=>$e->type,'description'=>$e->message,'url'=>'/accounting?tab=integrity','due_at'=>null];
        if ($this->can('fulfillment.view')) foreach(\App\Models\OrderIntake::where('state','attention')->latest()->limit(25)->get() as $o) $items[]=['kind'=>'order','id'=>$o->id,'title'=>$o->external_id ?: '#'.$o->id,'description'=>'Review order','url'=>'/order-hub?intake='.$o->id,'due_at'=>null];
        if ($this->can('automations.executions.view')) foreach(app(AutomationService::class)->visibleExecutions()->whereIn('status',['failed','blocked'])->latest()->limit(25)->get() as $e) $items[]=['kind'=>'system','id'=>$e->id,'title'=>'Automation #'.$e->automation_id,'description'=>$e->error,'url'=>'/automation-studio?automation='.$e->automation_id,'due_at'=>$e->next_retry_at];
        $summary=['tasks'=>$tasks->total(),'approvals'=>count(array_filter($items,fn($x)=>$x['kind']==='approval')),'issues'=>count($items)];
        if (!empty($filters['area'])) $items=array_values(array_filter($items,fn($x)=>$x['kind']===$filters['area']));
        return ['tasks'=>$tasks,'attention'=>$items,'summary'=>$summary,'preview_limits'=>['per_area'=>25,'customer_candidates'=>100]];
    }
    private function can(string $permission): bool { return app(PermissionService::class)->roleHasPermission(Auth::user()->role,$permission); }
}
