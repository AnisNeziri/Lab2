<?php
namespace App\Services;

use App\Models\{Automation, AutomationExecution, BusinessEvent, OperationalTask};
use Illuminate\Support\Facades\{Auth,Cache,DB};

/** Bounded recovery scans supplement the existing durable event consumer. */
class AutomationScheduler
{
    public function tick(): void
    {
        $lock=Cache::lock('aims-automation-tick',300);
        if (!$lock->get()) return;
        try {
            Automation::withoutGlobalScopes()->where('enabled',true)->orderBy('id')->each(function($rule){
                app(AutomationService::class)->asCreator($rule,function()use($rule){
                    try {
                        if (app(AutomationOperations::class)->paused($rule)) return;
                        Cache::put('automation-heartbeat:'.$rule->company_id,now()->toIso8601String(),86400);
                        $this->resolveRecoveredTasks($rule);
                        $this->scan($rule);
                        BusinessEvent::query()->where('event_type',$rule->trigger)->where('id','>',$rule->event_cursor)->orderBy('id')->limit(200)->get()->each(function($event)use($rule){
                            app(AutomationService::class)->run($rule,$event);
                            $rule->update(['event_cursor'=>$event->id]);
                        });
                        $this->escalate($rule);
                        AutomationExecution::where('automation_id',$rule->id)->where('status','failed')->where('failure_class','retryable')->where('attempts','<',3)
                            ->whereNotNull('next_retry_at')->where('next_retry_at','<=',now())->limit(20)->get()->each(fn($run)=>app(AutomationService::class)->run($rule,BusinessEvent::findOrFail($run->business_event_id),$run));
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Automation scheduler rule failed',['automation_id'=>$rule->id,'error_class'=>class_basename($e)]);
                    }
                });
            });
        } finally { $lock->release(); }
    }

    public function resolveRecoveredTasks(Automation $rule, ?array $sourceIds=null): void
    {
        if (!in_array($rule->trigger,AutomationRegistry::ISSUE_TRIGGERS,true)) return;
        // Updating the observation timestamp rotates through older unresolved tasks.
        $tasks=OperationalTask::where('automation_id',$rule->id)->when($sourceIds!==null,fn($q)=>$q->whereIn('source_id',$sourceIds))->whereNotNull('dedupe_key')->orderBy('updated_at')->orderBy('id')->limit(100)->get();
        foreach($tasks as $candidate) DB::transaction(function()use($candidate,$rule){
            \App\Models\Company::whereKey($rule->company_id)->sharedLock()->firstOrFail();
            $currentRule=Automation::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            if (!$currentRule->enabled || app(AutomationOperations::class)->paused($currentRule)) return;
            $task=OperationalTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $source=app(AutomationRegistry::class)->source($task->source_type,(int)$task->source_id);
            if (!$source || !$task->dedupe_key) return;
            $event=new BusinessEvent(['company_id'=>$rule->company_id,'event_type'=>$rule->trigger,'entity_type'=>$task->source_type,'entity_id'=>$task->source_id,'reference'=>$task->title,'metadata'=>[]]);
            $facts=app(AutomationRegistry::class)->currentContext($event);
            $outcome=$task->outcome??[];
            $outcome['automation']['latest_evidence']=$facts;
            $outcome['automation']['last_observed_at']=now()->toIso8601String();
            if ($task->source_type==='Customer' && isset($outcome['collection']['promise'])) {
                $outcome['collection']['promise_progress']=$this->promiseProgress($task,$outcome['collection']['promise']);
            }
            $active=app(AutomationRegistry::class)->issueActive($rule->trigger,$source,$facts) && app(AutomationConditionEngine::class)->evaluate($currentRule->conditions,$facts)['matched'];
            if (!$active) {
                $outcome['source_resolved_at']=now()->toIso8601String();
                $outcome['workflow']['step']='resolved';
                $task->update(['status'=>$task->status==='cancelled'?'cancelled':'completed','dedupe_key'=>null,'completed_at'=>$task->completed_at??now(),'outcome'=>$outcome]);
                app(AutomationService::class)->audit('task.source_resolved',$task,['trigger'=>$rule->trigger,'current_evidence'=>$facts]);
                app(BusinessEventService::class)->record('automation.issue_resolved',$source,$task->title,['task_id'=>$task->id,'rule_id'=>$rule->id],'automation-resolved:'.$task->id);
            } else $task->update(['outcome'=>$outcome]);
        });
    }

    private function candidates(Automation $rule)
    {
        $registry=app(AutomationRegistry::class); $type=$registry->triggers()[$rule->trigger]['source']??null;
        if (!$type) return collect();
        $model=AutomationRegistry::SOURCES[$type][0]; $query=$model::query();
        $today=\App\Support\CompanyClock::today();
        match($rule->trigger) {
            'inventory.low_stock','inventory.stockout'=>$query->where('lifecycle_status','active'),
            'inventory.quality_hold'=>$query->with(['product:id,name','warehouse:id,name'])->where('quarantine_quantity','>',0),
            'inventory.transfer_stale'=>$query->whereIn('status',['in_transit','partially_received'])->where('dispatched_at','<',now()->subDays(7)),
            'inventory.count_variance'=>$query->whereIn('status',['in_progress','submitted','recount_required'])->whereHas('items',fn($q)=>$q->whereNotNull('counted_quantity')->where('variance_quantity','!=',0)),
            'inventory.reservation_invalid'=>$query->whereNotIn('status',['cancelled','delivered'])->whereHas('allocations',fn($q)=>$q->where('status','!=','released')->whereRaw('quantity > dispatched_quantity')->whereHas('lot',fn($q)=>$q->where('status','!=','active')->orWhereDate('expiry_at','<',$today))),
            'purchase_order.overdue'=>$query->whereIn('status',['confirmed','ordered','partially_received'])->where('expected_at','<',$today),
            'shipment.delayed'=>$query->whereNull('arrival_date')->whereNotIn('status',['delivered','cancelled'])->where('eta','<',now()),
            'shipment.eta_changed'=>$query->whereNotNull('previous_eta')->whereColumn('eta','!=','previous_eta'),
            'document.expiring'=>$query->where('status','!=','archived')->whereNotNull('expiry_date')->where('expiry_date','<=',$today->addDays(30)),
            'integration.failed'=>$query->where('enabled',true)->whereIn('health_state',['error','degraded']),
            'accounting.exception_detected'=>$query->where('status','open'),
            'fiscalization.review_required'=>$query->whereIn('status',['failed','unknown','unknown_outcome','needs_review']),
            'payroll.review_required'=>$query->whereIn('status',['calculated','needs_review']),
            'payroll.unpaid'=>$query->where('status','posted'),
            'employee.contract_expiring'=>$query->with('employee:id,name,status')->whereNotNull('effective_to')->where('effective_to','<=',$today->addDays(30)),
            'operating.brief'=>$query->whereKey($rule->company_id),
            'task.overdue'=>$query->whereIn('status',['open','in_progress'])->where(fn($q)=>$q->whereNull('source_type')->orWhere('source_type','!=','OperationalTask'))->where('due_at','<',now()->subDays(2))->whereNull('escalated_at'),
            default=>$query,
        };
        $rows=(clone $query)->where('id','>',$rule->scan_cursor)->orderBy('id')->limit(100)->get();
        if ($rows->isEmpty() && $rule->scan_cursor) $rows=$query->orderBy('id')->limit(100)->get();
        $rule->update(['scan_cursor'=>$rows->last()?->id??0]);
        return $rows;
    }

    private function scan(Automation $rule): void
    {
        if (!in_array($rule->trigger,AutomationRegistry::SCHEDULED_TRIGGERS,true)) return;
        if ($schedule=$rule->schedule) {
            $now=\App\Support\CompanyClock::now();
            if ($now->format('H:i')<($schedule['time']??'08:00') || ($schedule['frequency']==='weekly' && $now->dayOfWeekIso!==($schedule['weekday']??1)) || ($schedule['frequency']==='month_end'&&!$now->isLastOfMonth())) return;
        }
        $sources=$this->candidates($rule); $registry=app(AutomationRegistry::class);
        $stock=in_array($rule->trigger,['inventory.low_stock','inventory.stockout'],true)?app(InventorySnapshotService::class)->onHandForProducts($sources):collect();
        foreach($sources as $source) {
            $reference=$source->name ?: $source->reference ?: $source->po_number ?: $source->transfer_number ?: $source->count_number ?: ($source->employee?->name ?? '#'.$source->id);
            $event=new BusinessEvent(['event_type'=>$rule->trigger,'entity_type'=>class_basename($source),'entity_id'=>$source->id,'reference'=>$reference,'metadata'=>[]]);
            if ($stock->has($source->id)) $facts=['reference'=>$reference,'available'=>$stock[$source->id]['available'],'minimum'=>(float)$source->min_quantity,'shortage'=>max(0,(float)$source->min_quantity-$stock[$source->id]['available'])];
            else $facts=$registry->currentContext($event);
            if (!$registry->issueActive($rule->trigger,$source,$facts)) continue;
            $resolved=OperationalTask::where('automation_id',$rule->id)->where('source_type',class_basename($source))->where('source_id',$source->id)->whereNotNull('outcome->source_resolved_at')->max('id')??0;
            $version=$source->updated_at?->format('YmdHisu')??'none';
            $key=hash('sha256',implode(':',[$rule->id,$rule->version,$rule->trigger,$source->id,\App\Support\CompanyClock::today()->toDateString(),$version,$resolved]));
            app(BusinessEventService::class)->record($rule->trigger,$source,$reference,array_intersect_key($facts,array_flip($registry->triggers()[$rule->trigger]['fields'])),'automation-observation:'.$key);
            if ($rule->trigger==='task.overdue') $source->update(['escalated_at'=>now()]);
        }
    }

    private function escalate(Automation $rule): void
    {
        $tasks=OperationalTask::where('automation_id',$rule->id)->whereIn('status',['open','in_progress'])->whereNull('escalated_at')->where('due_at','<',now())->orderBy('due_at')->limit(100)->get();
        foreach($tasks as $candidate) DB::transaction(function()use($candidate,$rule){
            \App\Models\Company::whereKey($rule->company_id)->sharedLock()->firstOrFail();
            $currentRule=Automation::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            if (!$currentRule->enabled || app(AutomationOperations::class)->paused($currentRule)) return;
            $task=OperationalTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (!in_array($task->status,['open','in_progress'],true)||$task->escalated_at) return;
            $days=$task->outcome['automation']['escalate_after_days']??null;
            $promise=$task->outcome['collection']['promise']??null;
            if ($days===null && !$promise) return;
            if ($promise && $task->source_type==='Customer' && $this->promiseProgress($task,$promise)['fulfilled']) return;
            if (!$promise && $task->due_at->gt(now()->subDays((int)$days))) return;
            $outcome=$task->outcome??[];
            $outcome['escalation']=['reason'=>$promise?'Recorded payment promise passed without the collection issue resolving':'Configured task response period passed','at'=>now()->toIso8601String(),'previous_priority'=>$task->priority];
            $task->update(['priority'=>'urgent','escalated_at'=>now(),'outcome'=>$outcome]);
            if ($task->assigned_user_id) app(NotificationService::class)->createAutomationNotice($rule->company_id,$task->assigned_user_id,'task-escalation:'.$task->id.':'.($promise['due_on']??'initial'),'Task needs attention',$task->title,'/action-center?task='.$task->id);
            app(AutomationService::class)->audit('task.escalated',$task,$outcome['escalation']);
        });
    }

    private function promiseProgress(OperationalTask $task,array $promise): array
    {
        // Read posted customer payment history; reversals never count as collections.
        $payments=\App\Models\CustomerDebtTransaction::where('customer_id',$task->source_id)->where('type','payment')->whereDoesntHave('reversals');
        if (isset($promise['ledger_cursor'])) $payments->where('id','>',$promise['ledger_cursor']);
        else $payments->where('created_at','>',$promise['recorded_at']);
        $received=\App\Support\Money::normalize($payments->sum('amount'));
        $remaining=\App\Support\Money::subtract($promise['amount'],$received);
        return ['received_amount'=>$received,'remaining_amount'=>\App\Support\Money::compare($remaining,'0.00')>0?$remaining:'0.00','fulfilled'=>\App\Support\Money::compare($remaining,'0.00')<=0];
    }
}
