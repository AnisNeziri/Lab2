<?php

namespace App\Services;

use App\Models\BusinessEvent;
use App\Support\CompanyClock;
use App\Support\Money;
use App\Services\Platform\PlatformTrace;
use Illuminate\Support\Facades\Auth;

/** Bounded read projection for the existing operating brief. No decisions or writes. */
class OperatingBriefSummary
{
    private array $permissions = [];

    public static function displayTitle(array $item, bool $sq): ?string
    {
        $title = $item['title'] ?? null;
        if (! $sq || ($item['source_type'] ?? null) !== 'FinancialIntelligenceSnapshot') {
            return $title;
        }
        return match ($title) {
            'Review financial intelligence: cash pressure detected' => 'Shqyrto rrezikun e mungesës së parave',
            'Review financial intelligence: receivable risk changed' => 'Shqyrto ndryshimin e rrezikut të arkëtimit',
            'Review financial intelligence: large commitment upcoming' => 'Shqyrto angazhimet e mëdha në afrim',
            'Review financial intelligence: forecast materially changed' => 'Shqyrto ndryshimin e rëndësishëm në parashikim',
            'Review financial intelligence: data quality issue' => 'Shqyrto problemin me cilësinë e të dhënave',
            default => $title,
        };
    }

    private function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function read(?string $team, int $days): array
    {
        abort_unless(Auth::user()?->company_id, 403);
        $this->permissions=app(PermissionService::class)->forRole(Auth::user()->role);
        abort_unless($this->can('tasks.view'),403);
        $from = CompanyClock::today()->subDays($days - 1)->toDateString();
        $to = CompanyClock::today()->toDateString();
        $trace = app(PlatformTrace::class);
        $unavailable = [];
        $read = function ($name, $fn) use (&$unavailable, $trace) {
            try { return $trace->measure('brief.'.$name, $fn); }
            catch (\Throwable $e) {
                report($e);
                $unavailable[] = $name;
                return null;
            }
        };
        $financeAllowed = in_array($team, [null, 'management', 'finance'], true) && $this->can('accounting.reports.view') && $this->can('accounting.integrity.view');
        $financial = $financeAllowed ? $read('finance', fn () => app(FinancialControlService::class)->summary($from, $to)) : null;
        $sales=in_array($team,[null,'management','sales'],true) && $this->can('daily_sales.manage') ? $read('sales',fn()=>app(DailySaleService::class)->activitySummary($from,$to)) : null;
        // A failed source remains unavailable. Do not repeat the expensive failed read.
        $snapshot = $read('attention', fn () => app(ActionCenterService::class)->snapshot(
            ['view' => ($team || $this->can('tasks.manage')) ? 'all' : 'mine', 'team' => $team==='management'?null:$team, 'per_page' => 12],
            $financial ?? ['attention' => []]
        ));
        $areas = match ($team) {
            'sales' => ['customer','order'], 'purchasing' => ['procurement','shipment','stock','approval'],
            'warehouse' => ['stock','order'], 'finance' => ['finance','customer'], 'hr' => ['payroll','workforce'], default => null,
        };
        $issues = collect($snapshot['attention'] ?? [])->filter(fn ($i) => !$areas || in_array($i['kind'], $areas, true));
        $tasks = $snapshot ? collect($snapshot['tasks']->items())->all() : [];
        $linkedTasks=[];
        $issues=$issues->map(function($issue)use($tasks,&$linkedTasks){
            foreach($tasks as $task){
                $trigger=$task['outcome']['automation']['trigger']??null;
                $same=($issue['kind']==='customer' && $trigger==='customer.payment_overdue' && $task['source_type']==='Customer' && (string)$task['source_id']===(string)$issue['id'])
                    || ($issue['kind']==='shipment' && $trigger==='shipment.delayed' && $task['source_type']==='Shipment' && 'logistics-'.$task['source_id']===(string)$issue['id'])
                    || ($issue['kind']==='stock' && $trigger==='inventory.low_stock' && $task['source_type']==='Product' && (string)$task['source_id']===(string)$issue['id']);
                if($same){$issue['task_url']='/action-center?task='.$task['id'].'&status=all';$linkedTasks[]=$task['id'];break;}
            }
            return $issue;
        });
        $attention = $issues->map(function ($i) {
            if ($i['kind'] === 'customer' && $this->can('customers.manage')) $i['url'] = '/customers?customer='.$i['id'];
            return $i + ['identity' => $i['kind'].':'.$i['id'], 'basis' => $i['kind']==='shipment'?'prediction_review':($i['kind']==='stock'&&!is_numeric($i['id'])?'recommendation_review':'current_fact'),
                'next_action' => 'review_source', 'evidence' => $i['values'] ?? [], 'limitation' => 'Source review and current-state validation are required before any action.'];
        })->concat(collect($tasks)->reject(fn($t)=>in_array($t['id'],$linkedTasks,true))->map(fn ($t) => ['identity'=>'task:'.$t['id'], 'kind'=>'task','id'=>$t['id'], 'title'=>$t['title'], 'description'=>$t['description'] ?? '',
            'severity'=>$t['priority'], 'due_at'=>$t['due_at'] ?? null, 'url'=>'/action-center?task='.$t['id'].'&status=all', 'basis'=>'assigned_work', 'next_action'=>'review_source',
            'source_type'=>$t['source_type'] ?? null, 'evidence'=>[], 'limitation'=>'Assigned work is not proof that a recommendation is still current.']))
            ->unique('identity')->sortBy(fn ($i) => [match ($i['severity'] ?? '') { 'critical'=>0,'urgent'=>1,'high'=>2,default=>3 }, empty($i['due_at']) ? '9999' : (string)$i['due_at'], $i['identity']])->values()->take(12)->all();
        // Related control failures share one source review, rather than competing cards.
        $attention=collect($attention)->groupBy(fn($i)=>$i['kind']==='finance'?'finance:'.$i['url']:$i['identity'])->map(function($group){
            $first=$group->first();
            if($group->count()>1)$first['related']=$group->map(fn($r)=>['id'=>$r['id'],'title'=>$r['title'],'evidence'=>$r['evidence']])->values()->all();
            return $first;
        })->values()->all();
        $sections = [];
        $sectionNames = ['customer'=>'collections','order'=>'performance','stock'=>'inventory','procurement'=>'purchasing','shipment'=>'purchasing','finance'=>'accounting','onboarding'=>'accounting','payroll'=>'people','workforce'=>'people','approval'=>'decisions','system'=>'decisions','task'=>'decisions','document'=>'decisions'];
        foreach ($attention as $item) $sections[$sectionNames[$item['kind']] ?? 'decisions'][] = $item['identity'];
        $books = $operations = $forecast = null;
        if ($financial) {
            $trusted = $financial['opening_readiness']['status'] === 'verified' && $financial['controls_reconciled'] && $financial['books_balanced'] && $financial['integrity_status'] !== 'BLOCKED';
            $books = ['basis'=>'posted_books','currency'=>$financial['currency'],'from'=>$from,'to'=>$to,'status'=>$trusted?'verified':'blocked_opening_or_reconciliation',
                'values'=>$trusted?$financial['summary']:null,'working_capital'=>$trusted?$financial['working_capital']:null,'url'=>'/accounting?tab=owner',
                'qualification'=>'Unverified opening balances or control differences prevent reliable company cash/profit conclusions. Inspect the books in Accounting.'];
            $operations = ['basis'=>'operational_activity','as_of'=>$financial['checked_at'],'currency'=>$financial['currency'], 'rows'=>collect($financial['reconciliation']['rows'])->map(fn ($r) => [
                'control'=>$r['control'], 'amount'=>$r['control']==='supplier_advances' && !$trusted ? null : $r['operational_amount'], 'difference'=>$r['control']==='supplier_advances' && !$trusted ? null : $r['difference'],
                'reconciled'=>$r['reconciled'], 'qualification'=>$r['control']==='supplier_advances' && !$trusted?'currency_and_opening_evidence_required':'operational_position_not_posted_books', 'url'=>$r['drilldown_url'] ?? '/accounting?tab=reconciliation'])->values()->all()];
            $forecast = $financial['cash_outlook'];
            if ($forecast) {
                $qualified = $trusted && ($forecast['state'] ?? '')==='ready' && !($forecast['stale'] ?? true) && ($forecast['cash_complete'] ?? false) && $forecast['expected_closing_cash']!==null;
                $forecast = ['basis'=>'forecast','state'=>$qualified?'qualified':(!$trusted?'blocked_opening_or_reconciliation':(($forecast['stale'] ?? false)?'stale':'insufficient_evidence')),
                    'currency'=>$financial['currency'],'as_of'=>$forecast['as_of'] ?? null,'horizon'=>30,'values'=>$qualified?array_intersect_key($forecast,array_flip(['expected_collections','scheduled_outflows','expected_closing_cash'])):null,
                    'url'=>'/financial-intelligence','qualification'=>'Predicted collection timing and recorded scheduled outflows. Not booked accounting or permission to spend.'];
            }
        }
        $payroll = in_array($team,[null,'management','hr'],true) && $this->can('payroll.view') ? $read('payroll', fn () => app(\App\Services\Workforce\PayrollReadService::class)->summary()) : null;
        $readiness = in_array($team,[null,'management'],true) && $this->can('system_integrity.view') ? $read('readiness', fn () => app(SystemIntegrityService::class)->operationalReadiness()) : null;
        $changes = $read('changes', fn () => $this->changes($from, $to, $areas));
        return ['view'=>'management','as_of'=>now()->toIso8601String(),'period'=>compact('from','to','days'),'team'=>$team,'tasks'=>$tasks,'issues'=>$issues->take(12)->values()->all(),
            'attention'=>$attention,'sections'=>$sections,'sales'=>$sales,'books'=>$books,'operations'=>$operations,'forecast'=>$forecast,
            'payroll'=>$payroll?['status'=>$payroll['latest_run']?->status ?? 'not_prepared','missing_terms'=>$payroll['missing_terms'],'url'=>'/payroll']:null,
            'readiness'=>$readiness?['status'=>$readiness['live_company_operation']['status'],'checks'=>$readiness['commissioning']['checks'] ?? [],'url'=>'/system-integrity']:null,
            'changes'=>$changes,'unavailable'=>$unavailable,'priority_basis'=>'Explicit severity, then due date; no AI priority score. Bounded source preview.',
            'qualification'=>'Current source evidence and recorded events only. No historical balance deltas are inferred. No business actions are executed.','read_only'=>true];
    }

    private function changes(string $from, string $to, ?array $areas): array
    {
        $registry = app(AutomationRegistry::class);
        $definitions = $registry->triggers();
        $sources=[];
        foreach(array_unique(array_column($definitions,'source')) as $type) $sources[$type]=$this->can(AutomationRegistry::SOURCES[$type][1]) && $registry->canAccessSource($type);
        $allowed = array_filter($definitions, fn ($d) => $sources[$d['source']] && $this->can($d['permission']));
        $start=\Carbon\CarbonImmutable::parse($from,CompanyClock::timezone())->startOfDay()->utc();
        $end=\Carbon\CarbonImmutable::parse($to,CompanyClock::timezone())->endOfDay()->utc();
        $events = BusinessEvent::where('company_id',Auth::user()->company_id)->whereIn('event_type',array_keys($allowed))
            ->where('occurred_at','>=',$start)->where('occurred_at','<=',$end)->latest('occurred_at')->limit(80)->get(['id','event_type','entity_type','entity_id','reference','occurred_at']);
        $visible = [];
        // One tenant-aware parent query per source type, rather than one per event.
        foreach ($events->groupBy('entity_type') as $type=>$group) {
            if (!isset(AutomationRegistry::SOURCES[$type]) || $type==='Document' || ($type==='OperationalTask' && !$this->can('tasks.manage'))) continue;
            $class = AutomationRegistry::SOURCES[$type][0];
            $visible[$type]=$class::query()->where('company_id',Auth::user()->company_id)->whereIn('id',$group->pluck('entity_id'))->pluck('id')->all();
        }
        $rows=[];
        foreach ($events as $event) {
            $d=$allowed[$event->event_type];
            if ($d['source']!==$event->entity_type || !in_array($event->entity_id,$visible[$event->entity_type]??[])) continue;
            $category=app(AutomationOperations::class)->category($event->event_type);
            $kind=match($category){'sales'=>'customer','inventory'=>'stock','purchasing'=>'procurement','shipments'=>'shipment','workforce'=>'workforce','finance','tax'=>'finance',default=>'system'};
            if ($areas && !in_array($kind,$areas,true)) continue;
            $url=AutomationRegistry::SOURCES[$event->entity_type][2].$event->entity_id;
            if ($event->entity_type==='Customer' && $this->can('customers.manage')) $url='/customers?customer='.$event->entity_id;
            $rows[]=['id'=>$event->id,'event'=>$event->event_type,'label'=>$d['label'],'label_sq'=>$d['label_sq'],'reference'=>$event->reference,'at'=>$event->occurred_at->toIso8601String(),'url'=>$url,'basis'=>'recorded_event'];
            if(count($rows)===20) break;
        }
        return ['rows'=>$rows,'limit'=>20,'candidate_limit'=>80,'basis'=>'Recorded business events, not a complete ledger of all changes. Missing past balances are not reconstructed.'];
    }
}
