<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrderHubPermissionUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_registers_admin_access_without_replacing_existing_grants(): void
    {
        $admin = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $staff = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff']);
        $existing = Permission::firstOrCreate(['slug' => 'products.manage'], ['name' => 'Products', 'group' => 'inventory']);
        $admin->permissions()->sync([$existing->id]);
        $staff->permissions()->sync([$existing->id]);
        Permission::where('slug', 'like', 'fulfillment.%')->delete();
        Cache::put('role_permissions:admin', ['products.manage'], 3600);

        $migration = require database_path('migrations/2026_09_19_000000_register_order_hub_permissions.php');
        $migration->up();
        $migration->up();

        $grants = app(PermissionService::class)->forRole('admin');
        $this->assertContains('products.manage', $grants);
        foreach (['view', 'manage', 'pick', 'dispatch'] as $action) {
            $this->assertContains('fulfillment.'.$action, $grants);
        }
        $this->assertCount(5, $grants);
        $this->assertSame(['products.manage'], $staff->permissions()->pluck('slug')->all());
    }
}
