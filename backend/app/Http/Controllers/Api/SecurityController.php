<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Permission;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Security\PasskeyService;
use App\Services\Security\SecurityEvents;
use App\Services\Security\SessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SecurityController extends Controller
{
    public function permit(string $permission='security.manage'): void
    {
        abort_unless(auth()->user()?->company_id && app(PermissionService::class)->roleHasPermission(auth()->user()->role,$permission),403);
        if ($permission!=='security.view') abort_unless(auth()->user()->role==='admin',403,'Only the company administrator may change security or export security evidence.');
    }
    public function show(SessionSecurity $s)
    {
        $u=auth()->user(); $admin=$u->company_id && app(PermissionService::class)->roleHasPermission($u->role,'security.view');
        $data=['passkeys'=>DB::table('passkey_credentials')->where('user_id',$u->id)->where('company_id',$u->company_id)->whereNull('revoked_at')->get(['id','name','created_at','last_used_at','backup_eligible']),
            'sessions'=>DB::table('auth_sessions')->where('user_id',$u->id)->where('company_id',$u->company_id)->whereNull('revoked_at')->where('expires_at','>',now())->orderByDesc('last_active_at')->limit(50)->get(['id','device','ip_address','created_at','last_active_at','auth_method'])->map(fn($r)=>(array)$r+['current'=>$r->id===request()->attributes->get('auth_session')?->id]),
            'policy'=>$s->policy(),'administrator'=>$admin,'passkey_site'=>['rp_id'=>config('security.passkeys.rp_id'),'origins'=>config('security.passkeys.origins')],
            'recovery'=>'Keep a second passkey. Password recovery uses verified email when configured. Administrators cannot reset another administrator here; offline owner recovery requires the protected installation recovery credential.'];
        if (!$admin) return $data;
        $users=User::where('company_id',$u->company_id)->where('role','!=','integration')->where('is_active',true)->orderBy('name')->limit(200)->get();
        $counts=DB::table('passkey_credentials')->where('company_id',$u->company_id)->whereNull('revoked_at')->selectRaw('user_id,COUNT(*) AS total')->groupBy('user_id')->pluck('total','user_id');
        $privileged=$users->whereIn('role',['admin','superadmin']);
        $recommendations=[];
        if (!config('security.recovery_key_hash') && !config('production.mail_enabled')) $recommendations[]='Configure verified email recovery or a protected installation-owner recovery key before requiring passkeys.';
        if ($privileged->contains(fn($x)=>!($counts[$x->id]??0))) $recommendations[]='A privileged account has only password authentication. Add a passkey and keep a recovery method.';
        $stale=ServiceAccount::where('company_id',$u->company_id)->where('status','active')->where('created_at','<',now()->subDays(90))->where(fn($q)=>$q->whereNull('last_used_at')->orWhere('last_used_at','<',now()->subDays(90)))->count();
        if ($stale) $recommendations[]="$stale active service credentials have not been used for 90 days. Review or revoke them.";
        $settings=Company::findOrFail($u->company_id)->platform_settings??[];
        if (($settings['mcp_enabled']??false) && !ServiceAccount::where('company_id',$u->company_id)->where('status','active')->where('expires_at','>',now())->exists()) $recommendations[]='MCP is enabled without a current service credential. Disable it if unused.';
        return $data+['summary'=>['privileged_users'=>$privileged->count(),'users_with_passkeys'=>$counts->count(),'active_sessions'=>DB::table('auth_sessions')->where('company_id',$u->company_id)->whereNull('revoked_at')->where('expires_at','>',now())->count()],'users'=>$users->map(function($x)use($counts){
            $permissions=app(PermissionService::class)->forUser($x);
            $warnings=[];
            if (count(array_intersect(['payroll.manage','payroll.approve','payroll.pay'],$permissions))===3) $warnings[]='This user can prepare, approve and pay payroll. A second reviewer is recommended.';
            if (in_array('purchase_orders.payments',$permissions) && in_array('supplier_invoices.approve',$permissions)) $warnings[]='This user can approve supplier bills and record supplier payments. Use independent review.';
            if (in_array('integrations.manage',$permissions) && in_array('accounting.journal.post',$permissions)) $warnings[]='Integration administration and financial posting are combined. Review this responsibility.';
            return ['id'=>$x->id,'name'=>$x->name,'role'=>$x->role,'company_id'=>$x->company_id,'passkeys'=>(int)($counts[$x->id]??0),'groups'=>array_values(array_unique(array_map(fn($p)=>explode('.',$p)[0],$permissions))),'permissions'=>$permissions,'warnings'=>$warnings];
        }),'recommendations'=>$recommendations,'events'=>$this->eventsQuery()->limit(60)->get(),
            'service_accounts'=>ServiceAccount::where('company_id',$u->company_id)->get(['id','name','status','expires_at','last_used_at']),
            'integration_access'=>['api'=>$settings['api_enabled']??false,'mcp'=>$settings['mcp_enabled']??false],
            'role_templates'=>['Owner / Administrator'=>'admin','Manager'=>'manager','Sales'=>'sales','Purchasing'=>'purchasing','Warehouse'=>'warehouse','Accountant'=>'accountant','HR / Payroll'=>'hr','Read Only'=>'read_only']];
    }
    private function eventsQuery()
    {
        return DB::table('security_events')->where('company_id',auth()->user()->company_id)->orderByDesc('id')->select(['id','user_id','event','subject_id','request_id','context','created_at']);
    }
    public function export()
    {
        $this->permit('security.audit.export'); app(SessionSecurity::class)->recent();
        app(SecurityEvents::class)->record('security.audit_exported');
        $company=auth()->user()->company_id;
        $business=\App\Models\ActivityLog::where('company_id',$company)->where(function($q){
            foreach(['%approval%','payroll.%','fiscalization.%','integration.%','user.%'] as $pattern) $q->orWhere('action','like',$pattern);
        })->latest('id')->limit(500)->get(['id','action','entity','entity_id','user_id','created_at']);
        return ['format'=>'aims-security-audit-v1','events'=>$this->eventsQuery()->limit(1000)->get(),'business_decisions'=>$business,'bounded'=>true];
    }
    public function confirm(Request $r)
    {
        $v=$r->validate(['password'=>'required|string|max:1024']); $session=$r->attributes->get('auth_session');
        abort_unless($session,403,'Sign in again to establish a device session.');
        if (!Hash::check($v['password'],auth()->user()->password)) abort(422,'The password is incorrect.');
        DB::table('auth_sessions')->where('id',$session->id)->where('user_id',auth()->id())->update(['authenticated_at'=>now(),'auth_method'=>'password']);
        app(SecurityEvents::class)->record('authentication.confirmed',null,['method'=>'password']);
        return ['confirmed'=>true];
    }
    public function options(Request $r,PasskeyService $p)
    {
        $purpose=$r->route('purpose'); abort_unless(in_array($purpose,['register','step_up'],true),404);
        return $p->options($purpose);
    }
    private function credentialInput(Request $r): array
    {
        return $r->validate(['challenge_id'=>'required|uuid','name'=>'sometimes|string|max:80','rawId'=>'required|string|max:3000',
            'response'=>'required|array','response.clientDataJSON'=>'required|string|max:16000','response.attestationObject'=>'sometimes|string|max:90000',
            'response.authenticatorData'=>'sometimes|string|max:16000','response.signature'=>'sometimes|string|max:3000',
            'response.userHandle'=>'nullable|string|max:300','response.transports'=>'sometimes|array|max:5','response.transports.*'=>'string|max:20']);
    }
    public function finish(Request $r,PasskeyService $p) { return $p->finish($this->credentialInput($r),$r->route('purpose')); }
    public function loginOptions(PasskeyService $p) { return $p->options('login'); }
    public function loginFinish(Request $r,PasskeyService $p) { return $p->finish($this->credentialInput($r),'login'); }
    public function name(Request $r, int $id)
    {
        $v=$r->validate(['name'=>'required|string|max:80']);
        $q=DB::table('passkey_credentials')->where('id',$id)->where('company_id',auth()->user()->company_id)->where('user_id',auth()->id())->whereNull('revoked_at');
        abort_unless($q->exists(),404); $q->update($v+['updated_at'=>now()]); return ['renamed'=>true];
    }
    public function revokePasskey(int $id)
    {
        app(SessionSecurity::class)->recent(); $u=auth()->user();
        $q=DB::table('passkey_credentials')->where('id',$id)->where('company_id',$u->company_id)->where('user_id',$u->id)->whereNull('revoked_at');
        abort_unless($q->exists(),404);
        $policy=app(SessionSecurity::class)->policy();
        if (($policy['privileged_passkey_required']&&$u->role==='admin') || $policy['finance_passkey_required'] || $policy['payroll_passkey_required']) {
            abort_unless(DB::table('passkey_credentials')->where('user_id',$u->id)->whereNull('revoked_at')->count()>1,422,'Add another passkey before removing the last one required by policy.');
        }
        $q->update(['revoked_at'=>now(),'updated_at'=>now()]);
        // A stolen assertion must not leave a privileged passkey-authenticated session alive.
        app(SessionSecurity::class)->revoke($u); $u->forceFill(['api_token'=>null,'token_version'=>(int)$u->token_version+1])->save();
        app(SecurityEvents::class)->record('passkey.removed',$u,[],$id); return ['revoked'=>true,'sign_in_required'=>true];
    }
    public function revokeSession(string $id)
    {
        app(SessionSecurity::class)->recent();
        $u=auth()->user(); $session=DB::table('auth_sessions')->where('company_id',$u->company_id)->where('user_id',$u->id)->where('id',$id)->first();
        abort_unless($session,404); app(SessionSecurity::class)->revoke($u,$id);
        app(SecurityEvents::class)->record('session.revoked'); return ['revoked'=>true];
    }
    public function revokeOthers()
    {
        $u=auth()->user(); $current=request()->attributes->get('auth_session')?->id;
        abort_unless($current,403,'Sign in again to identify this device.');
        app(SessionSecurity::class)->recent();
        DB::table('auth_sessions')->where('user_id',$u->id)->where('id','!=',$current)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
        DB::table('refresh_tokens')->where('user_id',$u->id)->where(fn($q)=>$q->whereNull('auth_session_id')->orWhere('auth_session_id','!=',$current))->whereNull('revoked_at')->update(['revoked_at'=>now()]);
        $u->forceFill(['api_token'=>null,'token_version'=>(int)$u->token_version+1])->save();
        app(SecurityEvents::class)->record('session.others_revoked');
        return app(\App\Services\JwtService::class)->issueTokens($u,app(SessionSecurity::class)->find($current,$u));
    }
    public function policy(Request $r)
    {
        $this->permit(); app(SessionSecurity::class)->recent();
        $v=$r->validate(['session_hours'=>'required|integer|min:1|max:720','privileged_passkey_required'=>'required|boolean','finance_passkey_required'=>'required|boolean','payroll_passkey_required'=>'required|boolean']);
        $u=auth()->user();
        if ($v['privileged_passkey_required'] || $v['finance_passkey_required'] || $v['payroll_passkey_required']) app(SessionSecurity::class)->recent(true);
        if ($v['privileged_passkey_required']) {
            $missing=User::where('company_id',$u->company_id)->where('role','admin')->where('is_active',true)
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('passkey_credentials')->whereColumn('user_id','users.id')->whereNull('revoked_at'))->exists();
            abort_if($missing,422,'Every active administrator must register a passkey before requiring it.');
        }
        Company::findOrFail($u->company_id)->update(['security_policy'=>$v]);
        // Tightening the duration caps existing sessions; never extend them implicitly.
        DB::table('auth_sessions')->where('company_id',$u->company_id)->where('expires_at','>',now()->addHours($v['session_hours']))->update(['expires_at'=>now()->addHours($v['session_hours'])]);
        app(SecurityEvents::class)->record('security.policy_changed',null,['policy'=>$v]); return $v;
    }
    public function role(Request $r,string $role)
    {
        $this->permit(); app(SessionSecurity::class)->recent();
        abort_unless(in_array($role,['manager','sales','purchasing','warehouse','accountant','hr','staff','read_only'],true),422);
        $v=$r->validate(['permissions'=>'present|array|max:300','permissions.*'=>'required|string|distinct']);
        $grantable=app(PermissionService::class)->forRole(auth()->user()->role);
        abort_if(array_diff($v['permissions'],$grantable),403,'You may grant only permissions you currently hold.');
        abort_if(collect($v['permissions'])->contains(fn($p)=>preg_match('/^(security|deployment|licensing)\./',$p)),422,'Security administration remains with the company administrator.');
        DB::transaction(function()use($role,$v){$c=Company::whereKey(auth()->user()->company_id)->lockForUpdate()->firstOrFail();$overrides=$c->role_permissions??[];$overrides[$role]=$v['permissions'];$c->update(['role_permissions'=>$overrides]);});
        app(SecurityEvents::class)->record('permission.changed',null,['role'=>$role,'count'=>count($v['permissions'])]); return ['saved'=>true];
    }
}
