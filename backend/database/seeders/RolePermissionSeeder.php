<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'View Dashboard', 'slug' => 'dashboard.view', 'group' => 'dashboard'],
            ['name' => 'Manage Products', 'slug' => 'products.manage', 'group' => 'inventory'],
            ['name' => 'Delete Products', 'slug' => 'products.delete', 'group' => 'inventory'],
            ['name' => 'Manage Stock', 'slug' => 'stock.manage', 'group' => 'inventory'],
            ['name' => 'Manage Categories', 'slug' => 'categories.manage', 'group' => 'inventory'],
            ['name' => 'Manage Suppliers', 'slug' => 'suppliers.manage', 'group' => 'inventory'],
            ['name' => 'View Reports', 'slug' => 'reports.view', 'group' => 'reports'],
            ['name' => 'Manage Invoices', 'slug' => 'invoices.manage', 'group' => 'finance'],
            ['name' => 'Process Payments', 'slug' => 'payments.process', 'group' => 'finance'],
            ['name' => 'Manage Users', 'slug' => 'users.manage', 'group' => 'admin'],
            ['name' => 'View Activity Logs', 'slug' => 'activity.view', 'group' => 'admin'],
            ['name' => 'Manage CMS', 'slug' => 'cms.manage', 'group' => 'content'],
            ['name' => 'Import Data', 'slug' => 'import.execute', 'group' => 'data'],
            ['name' => 'Export Data', 'slug' => 'export.execute', 'group' => 'data'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['slug' => $permission['slug']], $permission);
        }

        $roles = [
            'admin' => Permission::pluck('id')->all(),
            'manager' => Permission::whereNotIn('slug', ['users.manage', 'activity.view', 'cms.manage'])->pluck('id')->all(),
            'staff' => Permission::whereIn('slug', [
                'dashboard.view',
                'products.manage',
                'stock.manage',
                'reports.view',
                'invoices.manage',
                'export.execute',
            ])->pluck('id')->all(),
        ];

        foreach ($roles as $slug => $permissionIds) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst($slug), 'description' => ucfirst($slug) . ' role']
            );
            $role->permissions()->sync($permissionIds);
        }
    }
}
