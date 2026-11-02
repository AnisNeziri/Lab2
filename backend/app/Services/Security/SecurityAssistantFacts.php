<?php
namespace App\Services\Security;

use App\Models\Company;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SecurityAssistantFacts
{
    public function reply(array $v,string $id): ?array
    {
        $q=mb_strtolower($v['question']);
        if (!preg_match('/(?:which|show|are there|is the|a ka|cil|gjend).*(?:integration|webhook|admin.*passkey|system.*health|security|siguri)/u',$q)) return null;
        $allowed=app(PermissionService::class)->roleHasPermission(auth()->user()->role,'security.view');
        $text='Security administration is available only to authorized company administrators.';
        if ($allowed) {
            $company=auth()->user()->company_id;
            if (str_contains($q,'webhook')) $text=DB::table('webhook_deliveries')->where('company_id',$company)->whereIn('status',['failed','retrying'])->count().' webhook deliveries need review.';
            elseif(str_contains($q,'passkey')) {
                $n=DB::table('users')->where('company_id',$company)->where('role','admin')->where('is_active',true)->whereNull('deleted_at')
                    ->whereNotExists(fn($x)=>$x->selectRaw('1')->from('passkey_credentials')->whereColumn('user_id','users.id')->whereNull('revoked_at'))->count();
                $text="$n active administrator accounts do not have a passkey. Review adoption in Security.";
            } elseif(str_contains($q,'integration')) {
                $s=Company::findOrFail($company)->platform_settings??[];
                $text='Integration API: '.(($s['api_enabled']??false)?'enabled':'disabled').'. MCP: '.(($s['mcp_enabled']??false)?'enabled':'disabled').'. Both require scoped credentials.';
            } else $text='Open System Health for current database, queue, backup, key and license evidence. Configuration alone does not prove delivery or production readiness.';
        }
        $sq=($v['language']??'en')==='sq';
        return ['id'=>(string)Str::uuid(),'conversation_id'=>$id,'intent'=>'security_status','text'=>$text,'cards'=>[],
            'sources'=>$allowed?[['tool'=>'security_status','url'=>'/security']]:[],'limitations'=>[$sq?'Vetëm lexim. Sekretet nuk shfaqen.':'Read only. Secrets and session tokens are never returned.'],
            'choices'=>[],'read_only'=>true,'state'=>$allowed?'success':'restricted','provider'=>'deterministic'];
    }
}
