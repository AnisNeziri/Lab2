<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Cache;

class PermissionService
{
    /** Reuse authorization reads only inside a bounded read operation; never across requests or writes. */
    public function readScope(callable $read): mixed
    {
        $request=request();
        if($request->attributes->has('aims.permission_read_scope'))return $read();
        $request->attributes->set('aims.permission_read_scope',[]);
        try{return $read();}finally{$request->attributes->remove('aims.permission_read_scope');}
    }

    public function forRole(string $roleSlug): array
    {
        $request=request();
        if(!$request->attributes->has('aims.permission_read_scope'))return $this->resolveForRole($roleSlug);
        $user=auth()->user();
        $key=hash('sha256',json_encode([$user?->company_id,$user?->id,$user?->role,$roleSlug,$user?->integration_permissions]));
        $scope=$request->attributes->get('aims.permission_read_scope');
        if(array_key_exists($key,$scope))return $scope[$key];
        $result=$this->resolveForRole($roleSlug);
        $scope=$request->attributes->get('aims.permission_read_scope');$scope[$key]=$result;
        $request->attributes->set('aims.permission_read_scope',$scope);
        return $result;
    }

    private function resolveForRole(string $roleSlug): array
    {
        if ($roleSlug === 'integration' && auth()->user()?->role === 'integration') return app(\App\Services\Commercial\Entitlements::class)->filter(auth()->user()->integration_permissions ?? [], auth()->user());
        $permissions = Cache::remember("role_permissions:{$roleSlug}", 3600, function () use ($roleSlug) {
            $role = Role::where('slug', $roleSlug)->with('permissions')->first();

            if (! $role) {
                return [];
            }

            return $role->permissions->pluck('slug')->all();
        });
        $user = auth()->user();
        if (!$user?->company_id) return $permissions;
        $overrides = \App\Models\Company::find($user->company_id)?->role_permissions ?? [];
        return app(\App\Services\Commercial\Entitlements::class)->filter($overrides[$roleSlug] ?? $permissions, $user);
    }

    public function forUser(\App\Models\User $user): array
    {
        $prior = auth()->user();
        try { auth()->setUser($user); return $this->forRole($user->role); }
        finally { if ($prior) auth()->setUser($prior); else auth()->forgetUser(); }
    }

    public function roleHasPermission(string $roleSlug, string $permissionSlug): bool
    {
        return in_array($permissionSlug, $this->forRole($roleSlug), true);
    }

    public function allPermissions(): array
    {
        return Permission::orderBy('group')->orderBy('name')->get()->toArray();
    }
}
