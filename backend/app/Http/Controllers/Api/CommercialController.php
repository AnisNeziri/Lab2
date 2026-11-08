<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Commercial\CommercialReadiness;
use App\Services\Commercial\Entitlements;
use App\Services\Security\SecurityEvents;
use App\Services\Security\SessionSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercialController extends Controller
{
    private function permit(string $p='deployment.view',bool $recent=false): void
    {
        abort_unless(auth()->user()->role==='superadmin' && auth()->user()->company_id===null
            && app(\App\Services\PermissionService::class)->roleHasPermission('superadmin',$p),403);
        if ($recent) app(SessionSecurity::class)->recent();
    }
    private function company(Request $request): Company
    {
        $id = $request->validate(['company_id' => 'required|integer|min:1'])['company_id'];
        return Company::findOrFail($id);
    }
    public function show(Request $request, CommercialReadiness $r) { $this->permit(); $c=$this->company($request); app(SecurityEvents::class)->record('commercial.reviewed', subject:$c->id); return $r->health($c); }
    public function support(Request $request, CommercialReadiness $r) { $this->permit('deployment.view',true);$c=$this->company($request);app(SecurityEvents::class)->record('support.bundle_generated', subject:$c->id);return $r->support($c); }
    public function configure(Request $r,Entitlements $e)
    {
        $this->permit('licensing.manage',true);$c=$this->company($r);
        abort_if(DB::table('company_licenses')->where('company_id',$c->id)->exists(),422,'Signed license capabilities can only be changed with a new issuer-signed license.');
        $v=$r->validate(['package'=>'required|in:'.implode(',',array_keys(Entitlements::PACKAGES)),'capabilities'=>'required|array|min:1|max:16','capabilities.*'=>'required|distinct|in:'.implode(',',Entitlements::CAPABILITIES)]);
        abort_unless(in_array('core',$v['capabilities']),422,'Core access must remain available.');
        $c->update(['capability_settings'=>$v]);app(SecurityEvents::class)->record('capabilities.changed',null,['capabilities'=>$v['capabilities']],$c->id);
        $e->clear($c->id); return $e->state($c->id);
    }
    public function license(Request $r,Entitlements $e)
    {
        $this->permit('licensing.manage',true);$v=$r->validate(['document'=>'required|array','document.payload'=>'required|string|max:90000','document.signature'=>'required|string|max:3000']);
        $c=$this->company($r);$data=$e->validateLicense($v['document'],$c);
        DB::transaction(function()use($c,$data,$v){
            Company::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $old=DB::table('company_licenses')->where('company_id',$c->id)->first();
            // Re-importing an old lease cannot reset a clock-rollback warning.
            $seen=$old && \Carbon\Carbon::parse($old->last_seen_at)->gt(now())?$old->last_seen_at:now();
            DB::table('company_licenses')->updateOrInsert(['company_id'=>$c->id],['license_id'=>$data['license_id'],'signed_document'=>json_encode($v['document']),
                'document_hash'=>hash('sha256',json_encode($v['document'])),'last_seen_at'=>$seen,'created_at'=>$old?->created_at??now(),'updated_at'=>now()]);
        });
        app(SecurityEvents::class)->record('license.imported',null,['license_id'=>$data['license_id']],$c->id);$e->clear($c->id); return $e->state($c->id);
    }
    public function backup(Request $r,CommercialReadiness $c)
    {
        $this->permit('deployment.manage',true);$v=$r->validate(['filename'=>'required|string|max:200','passphrase'=>'required|string|min:16|max:1024']);
        $result=$c->verifyBackup($v['filename'],$v['passphrase']);app(SecurityEvents::class)->record('backup.checkpoint_verified');return $result;
    }
    public function preflight(Request $r,CommercialReadiness $c)
    {
        $this->permit('deployment.manage',true);$v=$r->validate(['document'=>'required|array','workers_stopped'=>'required|boolean']);
        $company=$this->company($r);
        $result=$c->preflight($v['document'],$v['workers_stopped'],$company);app(SecurityEvents::class)->record('update.preflight',null,['status'=>$result['status']],$company->id);return $result;
    }
}
