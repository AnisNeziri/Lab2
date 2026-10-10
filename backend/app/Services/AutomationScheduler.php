<?php
namespace App\Services;

use App\Models\{Automation, AutomationExecution, BusinessEvent, Product, Customer, PurchaseOrder, Shipment, Document, IntegrationProvider, OperationalTask};
use Illuminate\Support\Facades\{Auth, Cache};

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
                        $this->resolveRecoveredTasks($rule);
                        $this->scan($rule);
                        BusinessEvent::query()->where('event_type',$rule->trigger)->where('id','>',$rule->event_cursor)->orderBy('id')->limit(200)->get()->each(function($event)use($rule){
                            app(AutomationService::class)->run($rule,$event);
                            $rule->update(['event_cursor'=>$event->id]);
                        });
                        AutomationExecution::where('automation_id',$rule->id)->where('status','failed')->where('attempts','<',3)->whereNotNull('next_retry_at')->where('next_retry_at','<=',now())->limit(20)->get()->each(fn($run)=>app(AutomationService::class)->run($rule,BusinessEvent::findOrFail($run->business_event_id),$run));
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Automation scheduler rule failed',['automation_id'=>$rule->id,'error_class'=>class_basename($e)]);
                    }
                });
            });
        } finally { $lock->release(); }
    }

    private function resolveRecoveredTasks(Automation $rule): void
    {
        if (!in_array($rule->trigger,['document.expiring','customer.payment_overdue','integration.failed','shipment.delayed'],true)) return;
        OperationalTask::where('automation_id',$rule->id)->whereIn('status',['open','in_progress'])->each(function($task)use($rule){
            $source=app(AutomationRegistry::class)->source($task->source_type,(int)$task->source_id);
            if (!$source) return;
            $resolved=match($rule->trigger) {
                'document.expiring'=>$source->status==='archived' || ($source->expiry_date && $source->expiry_date->gt(\App\Support\CompanyClock::today()->addDays(30))),
                'customer.payment_overdue'=>(float)app(CustomerCreditService::class)->exposure($source)['overdue']<=0,
                'integration.failed'=>!in_array($source->health_state,['error','degraded'],true),
                'shipment.delayed'=>(bool)$source->arrival_date || $source->status==='delivered',
            };
            if ($resolved) {
                $task->update(['status'=>'completed','dedupe_key'=>null,'completed_at'=>now(),'outcome'=>['decision'=>'source_resolved','at'=>now()->toIso8601String()]]);
                app(AutomationService::class)->audit('task.source_resolved',$task,['trigger'=>$rule->trigger]);
            }
        });
    }

    private function scan(Automation $rule): void
    {
        $schedule=$rule->schedule;
        if ($schedule) {
            $now=now(config('app.timezone'));
            if ($now->format('H:i')<($schedule['time'] ?? '08:00')) return;
            if ($schedule['frequency']==='weekly' && $now->dayOfWeekIso!==($schedule['weekday'] ?? 1)) return;
            if ($schedule['frequency']==='month_end' && !$now->isLastOfMonth()) return;
        }
        // Repeated sweeps on the same day reuse the existing business event.
        $emit=function($source,array $metadata=[])use($rule){
            app(BusinessEventService::class)->record($rule->trigger,$source,$source->name ?: $source->reference ?: $source->po_number ?: '#'.$source->id,$metadata,'automation-observation:'.$rule->trigger.':'.$source->id.':'.\App\Support\CompanyClock::today()->toDateString());
        };
        switch ($rule->trigger) {
            case 'inventory.low_stock': case 'inventory.stockout':
                Product::query()->chunkById(100,function($products)use($rule,$emit){foreach($products as $p){$s=app(InventorySnapshotService::class)->forProduct($p);if($s['available']<=($rule->trigger==='inventory.stockout'?0:(float)$p->min_quantity))$emit($p,['available'=>$s['available'],'minimum'=>$p->min_quantity,'shortage'=>max(0,(float)$p->min_quantity-$s['available'])]);}}); break;
            case 'customer.payment_overdue': case 'customer.credit_warning':
                Customer::query()->chunkById(100,function($customers)use($rule,$emit){foreach($customers as $c){$s=app(CustomerCreditService::class)->exposure($c);if($rule->trigger==='customer.payment_overdue'?(float)$s['overdue']>0:($s['utilization_percent'] ?? 0)>=90)$emit($c,$s);}}); break;
            case 'purchase_order.overdue':
                PurchaseOrder::whereNotIn('status',['completed','received','cancelled'])->where('expected_at','<',\App\Support\CompanyClock::today())->each(fn($x)=>$emit($x)); break;
            case 'shipment.delayed':
                Shipment::whereNull('arrival_date')->whereNotIn('status',['delivered','cancelled'])->where('eta','<',now())->each(fn($x)=>$emit($x)); break;
            case 'shipment.eta_changed':
                Shipment::whereNotNull('previous_eta')->whereColumn('eta','!=','previous_eta')->each(fn($x)=>$emit($x,['eta'=>$x->eta?->toIso8601String(),'previous'=>['eta'=>$x->previous_eta?->toIso8601String()]])); break;
            case 'document.expiring':
                Document::where('status','!=','archived')->whereNotNull('expiry_date')->where('expiry_date','<=',\App\Support\CompanyClock::today()->addDays(30))->each(fn($x)=>$emit($x)); break;
            case 'integration.failed':
                IntegrationProvider::where('enabled',true)->whereIn('health_state',['error','degraded'])->each(fn($x)=>$emit($x)); break;
            case 'task.overdue':
                // Do not escalate escalation tasks: this prevents recursive task growth.
                OperationalTask::whereIn('status',['open','in_progress'])->where(fn($q)=>$q->whereNull('source_type')->orWhere('source_type','!=','OperationalTask'))->where('due_at','<',now()->subDays(2))->whereNull('escalated_at')->each(function($task)use($emit){$emit($task);$task->update(['escalated_at'=>now()]);}); break;
        }
    }
}
