<?php

namespace App\Services\Platform;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationGateway
{
    public const SCOPES = [
        'inventory.read' => ['inventory.view'], 'customers.read' => ['debts.view'], 'suppliers.read' => ['supplier_catalogue.view'],
        'orders.read' => ['fulfillment.view'], 'procurement.read' => ['purchase_orders.view'], 'shipments.read' => ['shipments.view'],
        'documents.read' => ['documents.view'], 'search.read' => ['dashboard.view'], 'work.read' => ['tasks.view'],
        'accounting.read' => ['accounting.reports.view'], 'payroll.read' => ['payroll.view'],
    ];

    public function permit(string $permission = 'integrations.manage'): void
    {
        abort_unless(Auth::user()?->company_id && app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission), 403);
    }

    public function settings(): array
    {
        return array_replace(['api_enabled' => false, 'mcp_enabled' => false, 'observability_enabled' => false], Company::findOrFail(Auth::user()->company_id)->platform_settings ?? []);
    }

    public function credentials(array $input): array
    {
        $this->permit();
        $v = validator($input, ['name' => 'required|string|max:120', 'scopes' => 'required|array|min:1|max:11', 'scopes.*' => 'required|in:'.implode(',', array_keys(self::SCOPES)), 'expires_days' => 'sometimes|integer|min:1|max:365'])->validate();
        foreach ($v['scopes'] as $scope) {
            foreach (self::SCOPES[$scope] as $permission) {
                $this->permit($permission);
            }
        }

        return DB::transaction(function () use ($v) {
            $u = User::create(['company_id' => Auth::user()->company_id, 'name' => 'Integration: '.$v['name'], 'email' => Str::uuid().'@service.invalid', 'password' => Str::random(64), 'role' => 'integration', 'is_active' => false]);
            $token = 'aims_svc_'.bin2hex(random_bytes(32));
            $account = ServiceAccount::create(['company_id' => Auth::user()->company_id, 'user_id' => $u->id, 'name' => $v['name'], 'scopes' => array_values(array_unique($v['scopes'])), 'token_hash' => hash('sha256', $token), 'created_by' => Auth::id(), 'expires_at' => now()->addDays($v['expires_days'] ?? 90)]);
            $this->audit('integration.credential_created', $account);

            return ['account' => $account, 'credential' => $token, 'shown_once' => true];
        });
    }

    public function rotate(ServiceAccount $account, bool $revoke = false): array
    {
        $this->permit();

        return DB::transaction(function () use ($account, $revoke) {
            $account = ServiceAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $token = 'aims_svc_'.bin2hex(random_bytes(32));
            $account->update(['token_hash' => hash('sha256', $token), 'status' => $revoke ? 'revoked' : 'active', 'rotated_at' => now(), 'expires_at' => $revoke ? now() : now()->addDays(90)]);
            $this->audit($revoke ? 'integration.credential_revoked' : 'integration.credential_rotated', $account);

            return ['account' => $account, 'credential' => $revoke ? null : $token, 'shown_once' => ! $revoke];
        });
    }

    public function authenticate(string $token, string $channel = 'api'): ServiceAccount
    {
        abort_unless(str_starts_with($token, 'aims_svc_') && strlen($token) === 73, 401);
        $account = ServiceAccount::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->where('status', 'active')->where('expires_at', '>', now())->first();
        abort_unless($account, 401);
        $company = Company::findOrFail($account->company_id);
        abort_unless(($company->platform_settings ?? [])[$channel.'_enabled'] ?? false, 403, 'Integration access is disabled.');
        $actor = User::withoutGlobalScopes()->where('company_id', $account->company_id)->whereNull('deleted_at')->findOrFail($account->user_id);
        abort_unless($actor->role === 'integration' && $actor->is_active === false, 403, 'The service identity has changed. Revoke this credential and create a new scoped account.');
        $actor->setAttribute('integration_permissions', array_values(array_unique(array_merge(...array_map(fn ($s) => self::SCOPES[$s] ?? [], $account->scopes)))));
        $actor->setAttribute('integration_scopes', $account->scopes);
        $actor->setAttribute('service_identity', $account->id);
        Auth::setUser($actor);
        abort_unless(app(\App\Services\Commercial\Entitlements::class)->has('integrations',$actor) && ($channel!=='mcp' || app(\App\Services\Commercial\Entitlements::class)->has('mcp',$actor)),403,'This company does not have this integration capability.');
        $account->update(['last_used_at' => now()]);

        return $account;
    }

    public function audit(string $action, ServiceAccount $account): void
    {
        app(\App\Services\Security\SecurityEvents::class)->record($action,Auth::user(),['count'=>count($account->scopes)],$account->id);
        ActivityLog::create(['company_id' => $account->company_id, 'user_id' => Auth::id(), 'action' => $action, 'entity' => 'ServiceAccount', 'entity_id' => $account->id, 'description' => str_replace('.', ' ', $action), 'new_value' => ['scopes' => $account->scopes, 'status' => $account->status]]);
    }
}
