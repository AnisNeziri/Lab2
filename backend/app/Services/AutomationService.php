<?php
namespace App\Services;

use App\Models\{Automation, AutomationVersion, AutomationExecution, OperationalTask, BusinessEvent, ActivityLog, Notification, User};
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

class AutomationService
{
    private static array $trace=[];
    public static function eventTrace(): array { return self::$trace; }
    public function __construct(private AutomationRegistry $registry, private AutomationConditionEngine $conditions) {}

    public function visibleRules() {
        $triggers=array_keys(array_filter($this->registry->triggers(),fn($t)=>$this->registry->canAccessSource($t['source'])));
        return Automation::whereIn('trigger',$triggers);
    }
    public function visibleExecutions() { return AutomationExecution::whereIn('automation_id',$this->visibleRules()->select('id')); }

    public function authorize(string $permission): void
    {
        if (!Auth::user()?->company_id || !app(PermissionService::class)->roleHasPermission(Auth::user()->role,$permission)) throw new AuthorizationException('You do not have permission for this automation action.');
    }

    public function save(array $data, ?Automation $automation = null): Automation
    {
        $this->authorize($automation ? 'automations.edit':'automations.create');
        $data=validator($data,[
            'name'=>'required|string|max:160','description'=>'nullable|string|max:2000','trigger'=>'required|string',
            'conditions'=>'required|array','actions'=>'required|array|min:1|max:8','priority'=>'required|in:low,normal,high,urgent',
            'schedule'=>'nullable|array','schedule.frequency'=>'required_with:schedule|in:daily,weekly,month_end',
            'schedule.time'=>'required_with:schedule|date_format:H:i','schedule.weekday'=>'nullable|integer|min:1|max:7',
        ])->validate();
        $trigger=$this->registry->triggers()[$data['trigger']] ?? null;
        if (!$trigger) $this->invalid('trigger','Select a registered trigger.');
        if (!empty($data['schedule']) && !$trigger['scheduled']) $this->invalid('schedule','This trigger reacts to business events and does not support scheduled sweeps.');
        $this->authorize($trigger['permission']);
        if (!$this->registry->canAccessSource($trigger['source'])) throw new AuthorizationException('Financial Intelligence requires finance and cash-account access.');
        $this->conditions->validate($data['conditions'],$trigger['fields']);
        foreach ($data['actions'] as &$action) {
            $action=validator($action,[
                'type'=>'required|string','title'=>'required|string|max:160','description'=>'nullable|string|max:2000',
                'assigned_user_id'=>'nullable|integer','assigned_role'=>'nullable|string|exists:roles,slug','assigned_team'=>'nullable|in:sales,purchasing,warehouse,finance,hr,management','escalate_after_days'=>'nullable|integer|min:0|max:30',
                'due_days'=>'nullable|integer|min:0|max:365','quantity'=>'nullable|numeric|min:0.001|max:1000000',
            ])->validate();
            $definition=$this->registry->actions()[$action['type']] ?? null;
            if (!$definition) $this->invalid('actions','This action is not allowed.');
            $this->authorize($definition['permission']);
            $this->validateRecipient($trigger, $action);
            if (isset($definition['source']) && $definition['source']!==$trigger['source']) $this->invalid('actions','This action requires a compatible '.$definition['source'].' trigger.');
            if ($action['type']==='purchase_request_draft' && empty($action['quantity'])) $this->invalid('actions','Specify the draft purchase quantity.');
            $this->validateAssignee($action);
        }
        unset($action);
        return DB::transaction(function()use($data,$automation){
            if ($automation) {
                $automation=Automation::query()->lockForUpdate()->findOrFail($automation->id);
                // New definitions never silently go live.
                $automation->fill($data+['enabled'=>false,'version'=>$automation->version+1,'lifecycle_state'=>'draft'])->save();
            } else $automation=Automation::create($data+['company_id'=>Auth::user()->company_id,'created_by'=>Auth::id(),'enabled'=>false,'lifecycle_state'=>'draft']);
            AutomationVersion::create(['company_id'=>$automation->company_id,'automation_id'=>$automation->id,'version'=>$automation->version,'definition'=>$data,'created_by'=>Auth::id()]);
            $this->audit('automation.saved',$automation,['version'=>$automation->version]);
            return $automation->fresh();
        });
    }

    public function toggle(Automation $automation, bool $enabled): Automation
    {
        $this->authorize($enabled?'automations.enable':'automations.disable');
        if ($enabled) $this->validateExecutionPermissions($automation);
        return DB::transaction(function()use($automation,$enabled){
            \App\Models\Company::whereKey(Auth::user()->company_id)->sharedLock()->firstOrFail();
            $automation=Automation::whereKey($automation->id)->lockForUpdate()->firstOrFail();
            if ($enabled) $this->validateExecutionPermissions($automation);
            if ($enabled && $automation->lifecycle_state==='archived') $this->invalid('automation','Duplicate or revise an archived rule before activation.');
            $automation->update(['enabled'=>$enabled,'lifecycle_state'=>$enabled?'active':'paused','event_cursor'=>BusinessEvent::withoutGlobalScopes()->where('company_id',$automation->company_id)->max('id') ?? 0]);
            $this->audit($enabled?'automation.enabled':'automation.disabled',$automation,[]);
            return $automation;
        });
    }

    public function simulate(Automation $automation, ?int $eventId = null): array
    {
        $this->authorize('automations.test');
        $this->authorize($this->registry->triggers()[$automation->trigger]['permission']);
        $events=BusinessEvent::query()->where('event_type',$automation->trigger)
            ->when($eventId,fn($q)=>$q->whereKey($eventId))->where('occurred_at','>=',now()->subDays(30))->latest('id')->limit(100)->get();
        $results=$events->map(function($event)use($automation){
            $context=$this->registry->context($event);
            $evaluation=$context ? $this->conditions->evaluate($automation->conditions,$context) : ['matched'=>false,'unavailable'=>true];
            return ['event_id'=>$event->id,'reference'=>$context ? $event->reference : null,'evaluation'=>$evaluation,'would_execute'=>$evaluation['matched']?$automation->actions:[]];
        });
        $this->audit('automation.simulated',$automation,['evaluated'=>$results->count(),'matched'=>$results->where('evaluation.matched',true)->count()]);
        return ['evaluated'=>$results->count(),'matched'=>$results->where('evaluation.matched',true)->count(),'limit'=>100,'days'=>30,'results'=>$results,'note'=>'Read-only simulation. Event fields are used where recorded; missing fields use the current authorized source. Missing sources never match.'];
    }

    public function consume(BusinessEvent $event): void
    {
        // React to existing source events as well as scheduled condition checks.
        // Identifiers are resolved from tenant-owned sources, not chat metadata.
        $related=[];
        if (!str_starts_with($event->event_type,'automation.')) {
            if (in_array($event->entity_type,['Product','Customer','PurchaseOrder','Shipment'],true)) $related[$event->entity_type]=[$event->entity_id];
            if ($event->entity_type==='CustomerDebtTransaction') {
                $row=\App\Models\CustomerDebtTransaction::withoutGlobalScopes()->where('company_id',$event->company_id)->find($event->entity_id);
                if ($row) $related['Customer']=[$row->customer_id];
            }
            if ($event->entity_type==='GoodsReceipt') {
                $row=\App\Models\GoodsReceipt::withoutGlobalScopes()->where('company_id',$event->company_id)->find($event->entity_id);
                if ($row) $related['Product']=$row->items()->limit(100)->pluck('product_id')->all();
            }
            if ($related) Automation::withoutGlobalScopes()->where('company_id',$event->company_id)->where('enabled',true)->whereIn('trigger',AutomationRegistry::ISSUE_TRIGGERS)->each(function($rule)use($related){
                $type=$this->registry->triggers()[$rule->trigger]['source']??null;
                if (isset($related[$type])) $this->asCreator($rule,function()use($rule,$type,$related){if(!app(AutomationOperations::class)->paused($rule))app(AutomationScheduler::class)->resolveRecoveredTasks($rule,$related[$type]);});
            });
        }

        Automation::withoutGlobalScopes()->where('company_id',$event->company_id)->where('trigger',$event->event_type)->where('enabled',true)->where('event_cursor','<',$event->id)->each(function($rule)use($event){
            $this->asCreator($rule, function()use($rule,$event){ if(!app(AutomationOperations::class)->paused($rule))$this->run($rule,$event); });
        });
    }

    public function asCreator(Automation $automation, callable $callback): mixed
    {
        $previous=Auth::user();
        $creator=User::withoutGlobalScopes()->where('company_id',$automation->company_id)->find($automation->created_by);
        if (!$creator || !$creator->is_active) { $automation->update(['enabled'=>false]); return null; }
        Auth::setUser($creator);
        try { return app(\App\Services\Platform\PlatformTrace::class)->measure('automation.rule',$callback,['rule_id'=>$automation->id]); }
        finally { $previous ? Auth::setUser($previous) : Auth::forgetUser(); }
    }

    public function run(Automation $automation, BusinessEvent $event, ?AutomationExecution $retry = null): AutomationExecution
    {
        return DB::transaction(function()use($automation,$event,$retry){
        if ((int)$automation->company_id!==(int)Auth::user()->company_id || (int)$event->company_id!==(int)$automation->company_id) throw new AuthorizationException();
        $expectedVersion=(int)$automation->version;
        $company=\App\Models\Company::whereKey(Auth::user()->company_id)->sharedLock()->firstOrFail();
        $automation=Automation::whereKey($automation->id)->lockForUpdate()->firstOrFail();
        $execution=$retry ?? AutomationExecution::firstOrCreate([
            'company_id'=>$automation->company_id,
            'execution_key'=>hash('sha256', $automation->id.':'.$automation->version.':'.$event->id),
        ],[
            'automation_id'=>$automation->id,'version'=>$automation->version,'business_event_id'=>$event->id,'context'=>[],
            'correlation_id'=>$event->metadata['automation_correlation'] ?? $event->event_id,
            'depth'=>(int)($event->metadata['automation_depth'] ?? 0),
        ]);
        if (!$execution->wasRecentlyCreated && !$retry && $execution->status!=='queued') return $execution;
        if (!$automation->enabled || $automation->lifecycle_state==='archived' || (int)$automation->version!==$expectedVersion || app(AutomationOperations::class)->paused($automation,$company)) {
            $execution->update(['status'=>'blocked','failure_class'=>'paused_or_changed','error'=>'Automation is paused or its reviewed version changed. No action was performed.','completed_at'=>now()]);return $execution;
        }
        $uncertain=AutomationExecution::where('automation_id',$automation->id)->where('status','needs_verification')->where('id','!=',$execution->id)
            ->whereIn('business_event_id',BusinessEvent::where('entity_type',$event->entity_type)->where('entity_id',$event->entity_id)->select('id'))->exists();
        if ($uncertain) { $execution->update(['status'=>'blocked','failure_class'=>'verification_required','error'=>'A prior outcome for this rule and source needs verification. No action repeated.','completed_at'=>now()]); return $execution; }
        $started=microtime(true);
        try {
            DB::transaction(function() use($automation,$execution,$event){
                $run=AutomationExecution::query()->lockForUpdate()->findOrFail($execution->id);
                if (in_array($run->status,['succeeded','skipped','blocked','needs_verification'],true) || $run->attempts>=3) return;
                $run->update(['status'=>'running','started_at'=>now(),'attempts'=>$run->attempts+1,'next_retry_at'=>null]);
                $version=AutomationVersion::where('automation_id',$automation->id)->where('version',$run->version)->firstOrFail()->definition;
                $this->validateExecutionPermissions($automation,$version);
                if (!$run->trigger_context) $run->update(['trigger_context'=>$this->registry->context($event)]);
                if ($event->entity_type==='Product' && collect($version['actions'])->contains('type','purchase_request_draft')) \App\Models\Product::whereKey($event->entity_id)->lockForUpdate()->firstOrFail();
                $run->update(['context'=>$this->registry->currentContext($event)]);
                if ($run->depth>=4) {
                    $run->update(['status'=>'blocked','error'=>'Automation chain depth limit reached.','completed_at'=>now()]);
                    $this->audit('automation.loop_prevented',$automation,['execution_id'=>$run->id]);
                    return;
                }
                if (!$run->context || !$this->registry->source($event->entity_type,(int)$event->entity_id)) {
                    $run->update(['status'=>'blocked','error'=>'Source is missing or inaccessible.','completed_at'=>now()]); return;
                }
                $evaluated=$this->conditions->evaluate($version['conditions'],$run->context);
                $run->update(['conditions_evaluated'=>$evaluated]);
                if (!$this->registry->issueActive($automation->trigger,$this->registry->source($event->entity_type,(int)$event->entity_id),$run->context) || ($run->context['qualified']??true)===false || !$evaluated['matched']) { $run->update(['status'=>'skipped','completed_at'=>now()]); return; }
                $results=$run->results ?? [];
                foreach ($version['actions'] as $index=>$action) {
                    if (isset($results[$index])) continue;
                    $previousTrace=self::$trace;
                    self::$trace=['automation_correlation'=>$run->correlation_id,'automation_depth'=>$run->depth+1,'automation_causation'=>$run->id];
                    try { $results[$index]=$this->execute($action,$automation,$run,$event,$index); }
                    finally { self::$trace=$previousTrace; }
                    $run->update(['results'=>$results]);
                }
                $run->update(['status'=>'succeeded','completed_at'=>now(),'error'=>null]);
                $this->audit('automation.executed',$automation,['execution_id'=>$run->id,'results'=>$results]);
            });
        } catch (Throwable $error) {
            // All database effects from this attempt roll back together. The
            // originating business transaction has already committed.
            $execution->refresh();
            $driverCode=$error instanceof \Illuminate\Database\QueryException ? (int)($error->errorInfo[1]??0) : 0;
            $temporary=$error instanceof \Illuminate\Database\QueryException && (in_array($driverCode,[1205,1213],true) || (string)$error->getCode()==='40001');
            $unknown=in_array($driverCode,[2006,2013],true);
            $attempts=$execution->attempts+1;
            $execution->update(['status'=>$unknown?'needs_verification':($error instanceof AuthorizationException?'blocked':'failed'),'failure_class'=>$unknown?'unknown_outcome':($temporary?'retryable':'needs_review'),'attempts'=>$attempts,'completed_at'=>now(),
                'error'=>$unknown?'Database outcome is uncertain. Verify linked results before any retry.':($error instanceof AuthorizationException?'Creator permission was revoked.':($error instanceof ValidationException ? implode(' ',\Illuminate\Support\Arr::flatten($error->errors())):'Action failed; review the execution and configuration before retrying.')),
                'next_retry_at'=>$temporary && $attempts<3 ? now()->addMinutes($attempts*2):null]);
            $this->audit('automation.failed',$automation,['execution_id'=>$execution->id,'error_class'=>class_basename($error)]);
        }
        $execution->refresh()->update(['duration_ms'=>(int)((microtime(true)-$started)*1000)]);
        $automation->increment('run_count');
        if (in_array($execution->status,['failed','blocked'],true)) $automation->increment('failure_count');
        $automation->update(['last_run_at'=>now()]);
        return $execution->fresh();
        });
    }

    public function retry(AutomationExecution $run): AutomationExecution
    {
        $this->authorize('automations.retry');
        if ($run->status!=='failed' || $run->attempts>=3) $this->invalid('execution','Only failed executions with fewer than three attempts can be retried.');
        $automation=Automation::findOrFail($run->automation_id);
        $this->authorize($this->registry->triggers()[$automation->trigger]['permission']);
        $version=AutomationVersion::where('automation_id',$automation->id)->where('version',$run->version)->firstOrFail()->definition;
        $this->validateExecutionPermissions($automation,$version);
        if ((int)$run->version!==(int)$automation->version) $this->invalid('execution','Rule changed. Preview the current version instead of replaying an old definition.');
        if (app(AutomationOperations::class)->paused($automation)) $this->invalid('execution','Company or category automation is paused.');
        if (!$automation->enabled) $this->invalid('execution','Enable this automation before retrying.');
        $this->audit('automation.retried',$automation,['execution_id'=>$run->id]);
        return $this->asCreator($automation,fn()=> $this->run($automation,BusinessEvent::findOrFail($run->business_event_id),$run));
    }


    public function verifyOutcome(AutomationExecution $execution,array $input): AutomationExecution
    {
        $this->authorize('automations.retry');
        abort_unless($this->visibleExecutions()->whereKey($execution->id)->exists(),404);
        $data=validator($input,['outcome'=>'required|in:no_effect,effect_reviewed','reason'=>'required|string|min:10|max:1000'])->validate();
        return DB::transaction(function()use($execution,$data){
            \App\Models\Company::whereKey(Auth::user()->company_id)->sharedLock()->firstOrFail();
            Automation::whereKey($execution->automation_id)->lockForUpdate()->firstOrFail();
            $execution=AutomationExecution::whereKey($execution->id)->lockForUpdate()->firstOrFail();
            abort_unless($execution->status==='needs_verification',409,'This execution does not require outcome verification.');
            $execution->update(['status'=>$data['outcome']==='no_effect'?'failed':'verified','failure_class'=>'human_verified','next_retry_at'=>null,
                'error'=>$data['outcome']==='no_effect'?'An authorized reviewer verified no persistent effect. Explicit safe retry is available.':'An authorized reviewer inspected the outcome. No replay is authorized.']);
            $this->audit('automation.outcome_verified',$execution,$data+['verified_by'=>Auth::id()]);
            return $execution;
        });
    }

    private function execute(array $action, Automation $automation, AutomationExecution $run, BusinessEvent $event, int $index): array
    {
        $this->validateAssignee($action);
        $this->validateRecipient($this->registry->triggers()[$event->event_type], $action);
        $key=hash('sha256',$automation->id.':'.$run->version.':'.$index.':'.$event->entity_type.':'.$event->entity_id);
        if (in_array($action['type'],['create_task','purchase_request_draft'],true) && in_array($automation->trigger,AutomationRegistry::ISSUE_TRIGGERS,true)) {
            $key=hash('sha256',$automation->id.':inventory-issue:'.$index.':'.$event->entity_type.':'.$event->entity_id);
            // Reuse unresolved tasks created before the stable issue key was
            // introduced, including tasks from an older rule version.
            if (!OperationalTask::where('dedupe_key',$key)->exists()) {
                $legacyKeys=AutomationVersion::where('automation_id',$automation->id)->pluck('version')->map(fn($v)=>hash('sha256',$automation->id.':'.$v.':'.$index.':'.$event->entity_type.':'.$event->entity_id));
                $legacy=OperationalTask::where('automation_id',$automation->id)->where('source_type',$event->entity_type)->where('source_id',$event->entity_id)->whereIn('dedupe_key',$legacyKeys)->lockForUpdate()->first();
                if ($legacy) $legacy->update(['dedupe_key'=>$key]);
            }
        }
        if ($action['type']==='create_task') {
            $task=OperationalTask::firstOrCreate(['company_id'=>$automation->company_id,'dedupe_key'=>$key],[
                'title'=>$action['title'].' · '.mb_substr((string)$run->context['reference'],0,80),'description'=>$action['description'] ?? $automation->description,
                'priority'=>$automation->priority,'assigned_user_id'=>$action['assigned_user_id'] ?? null,'assigned_role'=>$action['assigned_role'] ?? null,'assigned_team'=>$action['assigned_team'] ?? null,
                'source_type'=>$event->entity_type,'source_id'=>$event->entity_id,'automation_id'=>$automation->id,'created_by'=>Auth::id(),
                'due_at'=>now()->addDays($action['due_days'] ?? 1),
                'outcome'=>['automation'=>['execution_id'=>$run->id,'event_id'=>$event->id,'version'=>$run->version,'trigger_evidence'=>$run->trigger_context,'latest_evidence'=>$run->context,'trigger'=>$automation->trigger,'action_index'=>$index,'escalate_after_days'=>$action['escalate_after_days']??null]],
            ]);
            if ($task->wasRecentlyCreated) $this->audit('task.automation_created',$task,['rule_id'=>$automation->id,'rule_version'=>$run->version,'event_id'=>$event->id,'execution_id'=>$run->id]);
            if (!$task->wasRecentlyCreated) { $outcome=$task->outcome??[]; $outcome['automation']['latest_evidence']=$run->context; $outcome['automation']['last_observed_at']=now()->toIso8601String(); $task->update(['outcome'=>$outcome]); }
            return ['type'=>'task','id'=>$task->id,'url'=>'/action-center?task='.$task->id,'reused'=>!$task->wasRecentlyCreated];
        }
        if ($action['type']==='notify') {
            $notification=app(NotificationService::class)->createAutomationNotice($automation->company_id,$action['assigned_user_id'] ?? Auth::id(),$automation->trigger==='operating.brief'?hash('sha256',$key.':'.\App\Support\CompanyClock::today()->toDateString()):$key,$action['title'],($action['description'] ?? $automation->description ?? '').' · '.$run->context['reference'], $this->registry->url($event->entity_type,(int)$event->entity_id));
            return ['type'=>'notification','id'=>$notification->id];
        }
        if ($action['type']==='request_document_review') {
            $document=$this->registry->source('Document',(int)$event->entity_id);
            app(DocumentService::class)->action($document,'review',[]);
            $version=$document->versions()->where('version',$document->current_version)->firstOrFail();
            return ['type'=>'approval','id'=>$version->approval_request_id,'url'=>'/documents?document='.$document->id];
        }
        if ($action['type']==='purchase_request_draft') {
            $source=$this->registry->source('Product',(int)$event->entity_id);
            app(UnitConversionService::class)->assertPrecision((float)$action['quantity'],$source->unit,'quantity');
            // An unresolved task acts as the durable semantic dedupe guard.
            $guard=OperationalTask::where('dedupe_key',$key)->first();
            if ($guard) {
                $request=\App\Models\PurchaseRequest::find($guard->outcome['draft']['id'] ?? 0);
                // Conversion/cancellation is a purchasing decision, not recovery
                // of the stock condition. Keep its occurrence until source recovery.
                if ($request) return $guard->outcome['draft']+['reused'=>true];
                $guard->update(['dedupe_key'=>null]);
            }
            $open=\App\Models\PurchaseRequest::whereIn('status',['draft','submitted','approved','sourcing'])->whereHas('items',fn($q)=>$q->where('product_id',$source->id))->first();
            if ($open) return ['type'=>'purchase_request','id'=>$open->id,'url'=>'/procurement?request='.$open->id,'reused'=>true];
            $draft=app(ProcurementService::class)->createRequest(['notes'=>$action['title'],'items'=>[['product_id'=>$source->id,'description'=>$source->name,'quantity'=>$action['quantity'],'unit'=>$source->unit,'estimated_unit_price'=>$source->purchase_price ?? 0]]]);
            $result=['type'=>'purchase_request','id'=>$draft->id,'url'=>'/procurement?request='.$draft->id];
            OperationalTask::create(['company_id'=>$automation->company_id,'dedupe_key'=>$key,'title'=>$action['title'],'description'=>'Review purchase request '.$draft->request_number,'automation_id'=>$automation->id,'source_type'=>'Product','source_id'=>$source->id,'created_by'=>Auth::id(),'assigned_user_id'=>Auth::id(),'outcome'=>['draft'=>$result],'due_at'=>now()->addDay()]);
            return $result;
        }
        $this->invalid('action','Unregistered automation action.');
    }

    private function validateExecutionPermissions(Automation $automation, ?array $definition=null): void
    {
        $definition ??= $automation->toArray();
        $trigger=$this->registry->triggers()[$definition['trigger']] ?? null;
        if (!$trigger) $this->invalid('trigger','Trigger is no longer available.');
        $this->authorize($trigger['permission']);
        if (!$this->registry->canAccessSource($trigger['source'])) throw new AuthorizationException('Financial Intelligence requires finance and cash-account access.');
        foreach ($definition['actions'] as $action) {
            $registered=$this->registry->actions()[$action['type']] ?? null;
            if (!$registered) $this->invalid('action','Action is no longer available.');
            $this->authorize($registered['permission']);
        }
    }

    public function validateAssignee(array $data): void
    {
        if (!empty($data['assigned_user_id']) && !User::where('company_id',Auth::user()->company_id)->whereKey($data['assigned_user_id'])->exists()) $this->invalid('assigned_user_id','Assignee must belong to this company.');
        if (!empty($data['assigned_team']) || !empty($data['assigned_role']) || (!empty($data['assigned_user_id']) && (int)$data['assigned_user_id']!==Auth::id())) $this->authorize('tasks.assign');
    }

    private function validateRecipient(array $trigger,array $action): void
    {
        $roles=[];
        if (!empty($action['assigned_user_id'])) {
            $user=User::where('company_id',Auth::user()->company_id)->where('is_active',true)->find($action['assigned_user_id']);
            if (!$user) $this->invalid('assigned_user_id','Choose an active company user.');
            $roles[]=$user->role;
        }
        if (!empty($action['assigned_role'])) $roles[]=$action['assigned_role'];
        foreach($roles as $role) if (!app(PermissionService::class)->roleHasPermission($role,$trigger['permission'])) $this->invalid('assigned_user_id','The recipient must have permission to view this business area.');
        if ($trigger['source']==='CustomerSalesSnapshot') foreach($roles as $role) if (!$this->registry->canAccessSource($trigger['source'],$role)) $this->invalid('assigned_user_id','The recipient must have customer, sales and analytics access.');
        if ($trigger['source']==='FinancialIntelligenceSnapshot') foreach($roles as $role) if (!$this->registry->canAccessSource($trigger['source'],$role)) $this->invalid('assigned_user_id','The recipient must have finance and cash-account access.');
        if ($trigger['source']==='Document') foreach($roles as $role) if (!app(PermissionService::class)->roleHasPermission($role,'documents.manage')) $this->invalid('assigned_user_id','Document automations must target document managers to protect confidential references.');
        if ($trigger['source']==='OperationalTask') foreach($roles as $role) if (!app(PermissionService::class)->roleHasPermission($role,'tasks.manage')) $this->invalid('assigned_user_id','Escalations must target a task manager.');
    }

    public function audit(string $action, $entity, array $details): void
    {
        ActivityLog::create(['company_id'=>$entity->company_id,'user_id'=>Auth::id(),'action'=>$action,'entity'=>class_basename($entity),'entity_id'=>$entity->id,'description'=>str_replace('.',' ',$action),'new_value'=>$details]);
    }
    private function invalid(string $field,string $message): never { throw ValidationException::withMessages([$field=>[$message]]); }
}
