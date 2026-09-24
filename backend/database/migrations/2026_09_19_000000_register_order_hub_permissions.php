<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade existing installations without re-seeding or replacing custom grants.
        $permissions = [
            'fulfillment.view' => 'View Fulfillment',
            'fulfillment.manage' => 'Manage Sales Orders and Allocation',
            'fulfillment.pick' => 'Pick and Pack Orders',
            'fulfillment.dispatch' => 'Dispatch and Deliver Orders',
        ];
        $ids = [];
        foreach ($permissions as $slug => $name) {
            $ids[] = Permission::firstOrCreate(['slug' => $slug], [
                'name' => $name, 'group' => 'fulfillment',
            ])->id;
        }
        foreach (Role::whereIn('slug', ['admin', 'superadmin'])->get() as $role) {
            $role->permissions()->syncWithoutDetaching($ids);
        }
        foreach (['admin', 'superadmin'] as $slug) {
            Cache::forget("role_permissions:{$slug}");
        }
    }

    public function down(): void
    {
        // Permissions may predate this migration or be in active use; preserve them.
    }
};
