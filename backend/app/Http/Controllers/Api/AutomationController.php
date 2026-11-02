<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\{Automation, AutomationExecution, OperationalTask, User};
use App\Services\{AutomationService, AutomationRegistry, ActionCenterService, PermissionService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class AutomationController extends Controller {
    public function catalog(AutomationRegistry $registry) {
        $allowed=fn($x)=>app(PermissionService::class)->roleHasPermission(Auth::user()->role,$x['permission']);
        $triggers=array_filter($registry->triggers(),fn($x)=>$allowed($x)&&$registry->canAccessSource($x['source']));
        return ['triggers'=>$triggers,'actions'=>array_filter($registry->actions(),$allowed),'operators'=>\App\Services\AutomationConditionEngine::OPERATORS,
            'templates'=>array_values(array_filter($registry->templates(),fn($t)=>isset($triggers[$t['trigger']]))),
            'users'=>User::where('company_id',Auth::user()->company_id)->where('is_active',true)->get(['id','name'])];
    }
    private function allowedTriggers(): array { return array_keys(array_filter(app(AutomationRegistry::class)->triggers(),fn($t)=>app(PermissionService::class)->roleHasPermission(Auth::user()->role,$t['permission']))); }
    private function permitSource(Automation $automation): void { abort_unless(app(AutomationService::class)->visibleRules()->whereKey($automation->id)->exists(),403); }
    public function index(Request $request, AutomationRegistry $registry) {
        $filters = $request->validate(['search'=>'nullable|string|max:160','area'=>'nullable|string|max:40','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:50']);
        $query = app(AutomationService::class)->visibleRules();
        $triggers = $registry->triggers();
        if ($area = $filters['area'] ?? null) $query->whereIn('trigger', array_keys(array_filter($triggers, fn($trigger)=>($trigger['category'] ?? 'operations') === $area)));
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            // Escape wildcard characters; values are always query bindings.
            $like = '%'.str_replace(['!','%','_'], ['!!','!%','!_'], $search).'%';
            $matching = array_keys(array_filter($triggers, fn($trigger)=>mb_stripos(($trigger['label'] ?? '').' '.($trigger['label_sq'] ?? ''), $search) !== false));
            $query->where(fn($q)=>$q->whereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("description LIKE ? ESCAPE '!'", [$like])->orWhereIn('trigger',$matching));
        }
        return $query->orderByDesc('id')->paginate($filters['per_page'] ?? 50)->withQueryString();
    }
    public function show(Automation $automation) {
        $this->permitSource($automation);
        $counts=AutomationExecution::where('automation_id',$automation->id)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate','status');
        return $automation->toArray()+['created_by_name'=>User::whereKey($automation->created_by)->value('name'),'metrics'=>[
            'statuses'=>$counts,'executions'=>$counts->sum(),
            'tasks_created'=>OperationalTask::where('automation_id',$automation->id)->count(),
            'tasks_completed'=>OperationalTask::where('automation_id',$automation->id)->where('status','completed')->count(),
        ]];
    }
    public function assignees() { return User::where('company_id',Auth::user()->company_id)->where('is_active',true)->get(['id','name']); }
    public function store(Request $r,AutomationService $s) { return response()->json($s->save($r->all()),201); }
    public function update(Request $r,Automation $automation,AutomationService $s) { return $s->save($r->all(),$automation); }
    public function toggle(Request $r,Automation $automation,AutomationService $s) { $d=$r->validate(['enabled'=>'required|boolean']); return $s->toggle($automation,$d['enabled']); }
    public function simulate(Request $r,Automation $automation,AutomationService $s) { $d=$r->validate(['event_id'=>'nullable|integer|min:1']); return $s->simulate($automation,$d['event_id']??null); }
    public function duplicate(Automation $automation,AutomationService $s) { $d=$automation->only(['name','description','trigger','conditions','actions','priority','schedule']); $d['name'].=' (copy)'; return response()->json($s->save($d),201); }
    public function executions(Automation $automation) { $this->permitSource($automation);return AutomationExecution::where('automation_id',$automation->id)->with('event:id,entity_type,entity_id,reference')->latest()->paginate(30)->through(fn($run)=>$run->toArray()+['source_url'=>$run->event ? app(AutomationRegistry::class)->url($run->event->entity_type,$run->event->entity_id) : null]); }
    public function verifyOutcome(Request $r,AutomationExecution $execution,AutomationService $s) { return $s->verifyOutcome($execution,$r->all()); }
    public function retry(AutomationExecution $execution,AutomationService $s) { return $s->retry($execution); }
    public function center(Request $r,ActionCenterService $s) { return $s->snapshot($r->query()); }
    public function tasks(Request $r,ActionCenterService $s) { return $s->tasks($r->query()); }
    public function createTask(Request $r,ActionCenterService $s) { return response()->json($s->present($s->save($r->all())),201); }
    public function updateTask(Request $r,OperationalTask $task,ActionCenterService $s) { return $s->present($s->save($r->all(),$task)); }
    public function health(\App\Services\AutomationOperations $s) { return $s->health(); }
    public function pause(Request $r,\App\Services\AutomationOperations $s) { return $s->pause($r->all()); }
    public function brief(Request $r,\App\Services\AutomationOperations $s) {
        $v=$r->validate(['team'=>'nullable|string|in:'.implode(',',array_keys(\App\Services\AutomationOperations::TEAMS)), 'view'=>'nullable|in:management', 'days'=>'nullable|integer|min:1|max:31']);
        return $s->brief($v['team']??null,false,($v['view']??null)==='management',(int)($v['days']??7));
    }
    public function claim(OperationalTask $task,\App\Services\AutomationTaskWorkflow $s) { return $s->claim($task); }
    public function taskReview(OperationalTask $task,\App\Services\AutomationTaskWorkflow $s) { return $s->review($task); }
    public function prepareTask(OperationalTask $task,\App\Services\AutomationTaskWorkflow $s) { return $s->prepare($task); }
    public function confirmTask(Request $r,OperationalTask $task,\App\Services\AutomationTaskWorkflow $s) { $result=$s->confirmDraft($task,$r->all());return response()->json($result,isset($result['stale'])?409:200); }
    public function promise(Request $r,OperationalTask $task,\App\Services\AutomationTaskWorkflow $s) { return $s->promise($task,$r->all()); }
    public function archive(Automation $automation,AutomationService $s) {
        $s->authorize('automations.disable');$this->permitSource($automation);
        return \Illuminate\Support\Facades\DB::transaction(function()use($automation,$s){
            $automation=Automation::whereKey($automation->id)->lockForUpdate()->firstOrFail();
            $automation->update(['enabled'=>false,'lifecycle_state'=>'archived']);$s->audit('automation.archived',$automation,[]);return $automation;
        });
    }

}
