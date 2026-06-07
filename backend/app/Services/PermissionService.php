<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Cache;

class PermissionService
{
    public function forRole(string $roleSlug): array
    {
        return Cache::remember("role_permissions:{$roleSlug}", 3600, function () use ($roleSlug) {
            $role = Role::where('slug', $roleSlug)->with('permissions')->first();

            if (! $role) {
                return [];
            }

            return $role->permissions->pluck('slug')->all();
        });
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
