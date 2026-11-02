<?php
namespace App\Services\Security;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SessionSecurity
{
    public function policy(?User $user = null): array
    {
        $user ??= auth()->user();
        return array_replace(['session_hours'=>24,'privileged_passkey_required'=>false,'finance_passkey_required'=>false,'payroll_passkey_required'=>false],
            $user?->company_id ? (Company::find($user->company_id)?->security_policy ?? []) : []);
    }

    public function create(User $user, string $method = 'password'): object
    {
        $id=(string) Str::uuid(); $agent=(string) request()->userAgent();
        $browser=str_contains($agent,'Edg/')?'Edge':(str_contains($agent,'Firefox/')?'Firefox':(str_contains($agent,'Chrome/')?'Chrome':(str_contains($agent,'Safari/')?'Safari':'Client')));
        $os=str_contains($agent,'Windows')?'Windows':(str_contains($agent,'Android')?'Android':(str_contains($agent,'iPhone')?'iPhone':(str_contains($agent,'Mac')?'Mac':'Device')));
        DB::table('auth_sessions')->insert(['id'=>$id,'company_id'=>$user->company_id,'user_id'=>$user->id,'device'=>"$browser · $os",
            'ip_address'=>request()->ip(),'auth_method'=>$method,'authenticated_at'=>now(),'last_active_at'=>now(),
            'expires_at'=>now()->addHours(max(1,min(720,(int)$this->policy($user)['session_hours']))),'created_at'=>now(),'updated_at'=>now()]);
        return DB::table('auth_sessions')->find($id);
    }

    public function find(?string $id, User $user): ?object
    {
        if (!$id) return null;
        return DB::table('auth_sessions')->where('id',$id)->where('user_id',$user->id)->where('company_id',$user->company_id)
            ->whereNull('revoked_at')->where('expires_at','>',now())->first();
    }

    public function recent(bool $passkey = false): void
    {
        // A password-only session cannot enrol a replacement factor or turn off an enforced administrator policy.
        $policy=$this->policy();
        $strongPolicy=$policy['privileged_passkey_required'] || $policy['finance_passkey_required'] || $policy['payroll_passkey_required'];
        $existingFactor=$strongPolicy && DB::table('passkey_credentials')->where('user_id',auth()->id())->where('company_id',auth()->user()?->company_id)->whereNull('revoked_at')->exists();
        $passkey = $passkey || $existingFactor || ($policy['privileged_passkey_required'] && in_array(auth()->user()?->role,['admin','superadmin'],true));
        $session=request()->attributes->get('auth_session');
        if (!$session || \Carbon\Carbon::parse($session->authenticated_at)->lt(now()->subSeconds(config('security.recent_auth_seconds')))
            || ($passkey && $session->auth_method!=='passkey')) {
            abort(response()->json(['message'=>$passkey?'Confirm with a passkey to continue.':'Confirm your identity to continue.',
                'code'=>$passkey?'PASSKEY_REQUIRED':'RECENT_AUTH_REQUIRED'],403));
        }
    }

    public function revoke(User $user, ?string $id = null): void
    {
        $q=DB::table('auth_sessions')->where('user_id',$user->id)->where('company_id',$user->company_id)->whereNull('revoked_at');
        if ($id) $q->where('id',$id);
        $q->update(['revoked_at'=>now(),'updated_at'=>now()]);
        $refresh=DB::table('refresh_tokens')->where('user_id',$user->id)->whereNull('revoked_at');
        if ($id) $refresh->where('auth_session_id',$id);
        $refresh->update(['revoked_at'=>now()]);
    }

    public function guardRequest(): void
    {
        $u=auth()->user(); if (!$u || $u->role==='integration') return;
        $r=request(); $p=$this->policy($u);
        $securitySurface=$r->is('api/security*','api/me','api/logout','api/change-password');
        if ($p['privileged_passkey_required'] && in_array($u->role,['admin','superadmin']) && !$securitySurface) $this->recent(true);
        if ($p['finance_passkey_required'] && $r->is('api/accounting*','api/finance*','api/money-accounts*','api/financial-accounts*','api/tax*','api/fiscalization*')) $this->recent(true);
        if ($p['payroll_passkey_required'] && $r->is('api/payroll*')) $this->recent(true);
        if ($r->is('api/payroll/*/export')) $this->recent();
        if (!$r->isMethodSafe() && ($r->is('api/platform/service-accounts*','api/platform/settings','api/webhooks*','api/users*','api/superadmin/users*','api/payroll/settings*','api/payroll/configuration*','api/fiscalization/configuration*','api/fiscalization/settings*','api/fiscalization/certificates*'))) $this->recent();
    }
}
