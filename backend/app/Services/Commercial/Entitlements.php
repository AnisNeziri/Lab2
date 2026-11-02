<?php
namespace App\Services\Commercial;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Entitlements
{
    public const CAPABILITIES=['core','inventory','wms','sales','crm','procurement','shipments','accounting','tax','workforce','payroll','intelligence','automation','integrations','mcp','analytics'];
    public const PACKAGES=['platform'=>self::CAPABILITIES,'distribution'=>['core','inventory','wms','sales','crm','procurement','shipments','accounting'],'core-accounting'=>['core','inventory','accounting']];

    public static function capability(string $permission): string
    {
        $prefix=explode('.',$permission)[0];
        return match($prefix){
            'payroll'=>'payroll','employees','workforce'=>'workforce','tax','fiscalization'=>'tax',
            'accounting','finance','financial_accounts','bank_reconciliation','supplier_invoices','expenses','payments'=>'accounting',
            'debts','customers'=>'crm','invoices','invoice_profile','daily_sales','fulfillment'=>'sales',
            'procurement','purchase_orders','supplier_catalogue','replenishment','landed_costs','suppliers'=>'procurement',
            'shipments','control_tower'=>'shipments','products','stock','inventory','categories','quality','returns'=>'inventory',
            'warehouse_mobile','warehouses','transfers'=>'wms','analytics'=>'analytics','automations'=>'automation',
            'integrations'=>'integrations', default=>'core',
        };
    }
    public function state(int $companyId): array
    {
        if (app()->runningInConsole()) return $this->loadState($companyId);
        $key='company_entitlements:'.$companyId;
        if (!request()->attributes->has($key)) request()->attributes->set($key,$this->loadState($companyId));
        return request()->attributes->get($key);
    }
    public function clear(int $companyId): void { request()->attributes->remove('company_entitlements:'.$companyId); }
    private function loadState(int $companyId): array
    {
        $company=Company::findOrFail($companyId);
        $record=DB::table('company_licenses')->where('company_id',$companyId)->first();
        if (!$record) {
            $desktop=app(DesktopLicense::class)->state();
            return ['status'=>$desktop['status']??($company->capability_settings===null?'legacy':'configured'),'package'=>$company->capability_settings['package']??'platform',
                'capabilities'=>$company->capability_settings['capabilities']??self::CAPABILITIES,'managed'=>false,'write_allowed'=>$desktop['write_allowed']??true];
        }
        try {
            $data=$this->validateLicense(json_decode($record->signed_document,true),$company);
            $now=now(); $seen=\Carbon\Carbon::parse($record->last_seen_at);
            $status=$now->lt($seen->copy()->subMinutes(5))?'clock_review':($now->lt($data['valid_from'])?'not_yet_valid':($now->lte($data['valid_until'])?'active':($now->lte($data['offline_until'])?'grace':'expired')));
            if ($now->gt($seen)) DB::table('company_licenses')->where('id',$record->id)->where('last_seen_at','<',$now)->update(['last_seen_at'=>$now]);
            return ['status'=>$status,'package'=>$data['package'],'license_id'=>$data['license_id'],'valid_until'=>$data['valid_until'],
                'offline_until'=>$data['offline_until'],'capabilities'=>$data['capabilities'],'managed'=>true,'write_allowed'=>in_array($status,['active','grace'],true)];
        } catch (\Throwable) {
            // Do not hide existing company data if a license/key is damaged. Restrict premium writes for review.
            return ['status'=>'verification_required','package'=>'review','capabilities'=>self::CAPABILITIES,'managed'=>true,'write_allowed'=>false];
        }
    }
    public function validateLicense(array $envelope, Company $company): array
    {
        $d=app(SignedDocument::class)->verify($envelope,'license');
        validator($d,['license_id'=>'required|string|max:100','company'=>'required|uuid','installation_id'=>'required|uuid',
            'package'=>'required|string|max:80','capabilities'=>'required|array|max:16','capabilities.*'=>'in:'.implode(',',self::CAPABILITIES),
            'valid_from'=>'required|date','valid_until'=>'required|date|after:valid_from','offline_until'=>'required|date|after_or_equal:valid_until'])->validate();
        if (!hash_equals((string)$company->public_id,$d['company']) || !hash_equals(app(InstallationIdentity::class)->id(),$d['installation_id'])
            || !in_array('core',$d['capabilities'],true) || \Carbon\Carbon::parse($d['offline_until'])->gt(\Carbon\Carbon::parse($d['valid_until'])->addDays(30))) {
            throw ValidationException::withMessages(['license'=>'This license does not match this company/installation or exceeds its signed grace policy.']);
        }
        return $d;
    }
    public function has(string $capability, ?User $user=null): bool
    {
        $user??=auth()->user();
        return $user?->company_id && in_array($capability,$this->state($user->company_id)['capabilities'],true);
    }
    public function filter(array $permissions, User $user): array
    {
        if (!$user->company_id) return $permissions;
        $caps=$this->state($user->company_id)['capabilities'];
        return array_values(array_filter($permissions,fn($p)=>in_array(self::capability($p),$caps,true)));
    }
    public function guardRequest(): void
    {
        $u=auth()->user(); if (!$u?->company_id) return;
        $r=request(); $path=substr($r->path(),4);
        $cap=match(true){
            preg_match('~^(payroll|employee-advances|payroll-payments)~',$path)===1=>'payroll',
            preg_match('~^(workforce|employees|employment-terms)~',$path)===1=>'workforce',
            preg_match('~^(accounting|finance|financial-accounts|money-accounts|bank-|supplier-invoices|expenses)~',$path)===1=>'accounting',
            preg_match('~^(tax|fiscalization)~',$path)===1=>'tax',
            preg_match('~^(platform|integration-api|webhooks|integrations)~',$path)===1=>'integrations',
            preg_match('~^(automations|automation-)~',$path)===1=>'automation',
            preg_match('~^(shipments|control-tower)~',$path)===1=>'shipments',
            preg_match('~^(procurement|purchase-orders|purchase-requests|replenishment|supplier-catalogue|landed-cost)~',$path)===1=>'procurement',
            preg_match('~^(inventory|products|stock|quality|returns)~',$path)===1=>'inventory',
            preg_match('~^(warehouse|transfers|outbound|pick-)~',$path)===1=>'wms',
            preg_match('~^(sales-orders|fulfillment|order-hub|daily-sales|invoices)~',$path)===1=>'sales',
            preg_match('~^(customers|customer-debts|customer-workspace)~',$path)===1=>'crm',
            preg_match('~^(intelligence-assistant|assistant|financial-intelligence|strategic-simulation|supply-optimizer|decision-learning|inventory-intelligence|customer-sales-intelligence)~',$path)===1=>'intelligence',
            preg_match('~^analytics~',$path)===1=>'analytics',
            preg_match('~^reports~',$path)===1=>'inventory',
            default=>'core',
        };
        $state=$this->state($u->company_id);
        if (!in_array($cap,$state['capabilities'],true)) abort(response()->json(['message'=>ucfirst($cap).' is not enabled for this company.','code'=>'CAPABILITY_NOT_ENABLED','capability'=>$cap],403));
        if (!$state['write_allowed'] && !$r->isMethodSafe() && !$r->is('api/security*','api/commercial*','api/logout','api/change-password','api/intelligence-assistant/ask')) abort(response()->json(['message'=>'The license needs administrator review. Existing records remain available.','code'=>'LICENSE_REVIEW_REQUIRED'],403));
    }
}
