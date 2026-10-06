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
        $triggers=array_filter($registry->triggers(),$allowed);
        return ['triggers'=>$triggers,'actions'=>array_filter($registry->actions(),$allowed),'operators'=>\App\Services\AutomationConditionEngine::OPERATORS,
            'templates'=>array_values(array_filter($registry->templates(),fn($t)=>isset($triggers[$t['trigger']]))),
            'users'=>User::where('company_id',Auth::user()->company_id)->where('is_active',true)->get(['id','name'])];
    }
    private function allowedTriggers(): array { return array_keys(array_filter(app(AutomationRegistry::class)->triggers(),fn($t)=>app(PermissionService::class)->roleHasPermission(Auth::user()->role,$t['permission']))); }
    private function permitSource(Automation $automation): void { abort_unless(in_array($automation->trigger,$this->allowedTriggers(),true),403); }
    public function index() { return Automation::whereIn('trigger',$this->allowedTriggers())->latest()->paginate(50); }
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
    public function executions(Automation $automation) { $this->permitSource($automation);return AutomationExecution::where('automation_id',$automation->id)->latest()->paginate(30); }
    public function retry(AutomationExecution $execution,AutomationService $s) { return $s->retry($execution); }
    public function center(Request $r,ActionCenterService $s) { return $s->snapshot($r->query()); }
    public function tasks(Request $r,ActionCenterService $s) { return $s->tasks($r->query()); }
    public function createTask(Request $r,ActionCenterService $s) { return response()->json($s->present($s->save($r->all())),201); }
    public function updateTask(Request $r,OperationalTask $task,ActionCenterService $s) { return $s->present($s->save($r->all(),$task)); }
}
