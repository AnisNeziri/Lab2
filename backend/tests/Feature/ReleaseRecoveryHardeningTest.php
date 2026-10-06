<?php

namespace Tests\Feature;

use App\Models\{Company, Permission, Role, User};
use App\Services\{MaintenanceHealthService, SystemIntegrityService};
use Database\Seeders\{DesktopSuperadminSeeder, RolePermissionSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Hash};
use Tests\TestCase;

class ReleaseRecoveryHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('AIMS_DESKTOP_RECOVERY_PASSWORD');
        parent::tearDown();
    }

    public function test_desktop_setup_rejects_web_and_server_connections_before_any_command(): void
    {
        foreach ([['online','sqlite',null], ['offline','mysql',null], ['offline','sqlite','mysql://example.invalid/production']] as [$mode,$driver,$url]) {
            config(['system.operation_mode'=>$mode,'database.default'=>$driver,'database.connections.sqlite.url'=>$url]);
            $command = \Mockery::mock(\App\Console\Commands\SetupDesktopCommand::class)->makePartial();
            $command->shouldReceive('error')->once();
            $command->shouldNotReceive('call');
            $this->assertSame(1,$command->handle());
        }
    }

    public function test_recreated_role_gets_module_defaults_without_changing_existing_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = Role::where('slug','admin')->firstOrFail();
        $revoked = Permission::whereIn('slug',['analytics.view','automations.view'])->pluck('id')->all();
        $admin->permissions()->detach($revoked);
        Role::where('slug','manager')->firstOrFail()->delete();
        app(RolePermissionSeeder::class)->run(preserveExisting:true);
        $manager = Role::where('slug','manager')->firstOrFail();
        $this->assertCount(2,$manager->permissions()->whereIn('permissions.id',$revoked)->get());
        $this->assertCount(0,$admin->permissions()->whereIn('permissions.id',$revoked)->get());
    }

    public function test_desktop_permission_upgrade_preserves_custom_grants_and_revocations(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::where('slug', 'manager')->firstOrFail();
        $revoked = Permission::whereIn('slug', ['products.manage', 'analytics.view', 'automations.view'])->pluck('id')->all();
        $role->permissions()->detach($revoked);
        $custom = Permission::create(['slug'=>'custom.test', 'name'=>'Custom', 'group'=>'custom']);
        $role->permissions()->attach($custom);
        $role->update(['name'=>'My warehouse manager']);
        app(RolePermissionSeeder::class)->run(preserveExisting:true);
        app(RolePermissionSeeder::class)->run(preserveExisting:true);
        $ids = $role->permissions()->pluck('permissions.id')->all();
        $this->assertEmpty(array_intersect($ids, $revoked));
        $this->assertContains($custom->id, $ids);
        $this->assertSame('My warehouse manager', $role->fresh()->name);
    }

    public function test_recovery_password_is_unique_hashed_and_existing_owner_password_is_preserved(): void
    {
        config(['system.operation_mode'=>'offline']);
        $password = bin2hex(random_bytes(24));
        putenv('AIMS_DESKTOP_RECOVERY_PASSWORD='.$password);
        app(DesktopSuperadminSeeder::class)->run();
        $user = User::withoutGlobalScopes()->where('email','aimsadmin@company.com')->firstOrFail();
        $this->assertTrue(Hash::check($password,$user->password));
        $this->assertTrue((bool)$user->must_change_password);
        $user->forceFill(['password'=>Hash::make('Owner-specific-password'), 'must_change_password'=>false])->save();
        putenv('AIMS_DESKTOP_RECOVERY_PASSWORD='.bin2hex(random_bytes(24)));
        app(DesktopSuperadminSeeder::class)->run();
        $this->assertTrue(Hash::check('Owner-specific-password',$user->fresh()->password));
        $this->assertFalse((bool)$user->fresh()->must_change_password);
    }

    public function test_unprovisioned_recovery_fails_closed_without_default_credentials(): void
    {
        config(['system.operation_mode'=>'offline']);
        putenv('AIMS_DESKTOP_RECOVERY_PASSWORD');
        $this->expectException(\RuntimeException::class);
        app(DesktopSuperadminSeeder::class)->run();
    }

    public function test_legacy_recovery_hash_is_rotated_but_web_provisioning_is_rejected(): void
    {
        config(['system.operation_mode'=>'offline']);
        $user = User::factory()->create(['email'=>'aimsadmin@company.com','role'=>'superadmin']);
        DB::table('users')->where('id',$user->id)->update(['password'=>'$2y$12$9CJ3.c5nrp2CA03TDp3wueEVSeA5FO9A0S.Ad6zJrefB21ZKFfnOG']);
        $password = bin2hex(random_bytes(24));
        putenv('AIMS_DESKTOP_RECOVERY_PASSWORD='.$password);
        app(DesktopSuperadminSeeder::class)->run();
        $this->assertTrue(Hash::check($password,$user->fresh()->password));
        $this->assertTrue((bool)$user->fresh()->must_change_password);
        config(['system.operation_mode'=>'online']);
        $this->expectException(\RuntimeException::class);
        app(DesktopSuperadminSeeder::class)->run();
    }

    public function test_health_keeps_last_success_after_failure_and_is_tenant_scoped(): void
    {
        $this->actingAsApiUser();
        Auth::login(User::where('company_id',$this->apiCompany->id)->firstOrFail());
        $service = app(MaintenanceHealthService::class);
        $service->record($this->apiCompany->id,'analytics_capture');
        $success = DB::table('maintenance_health')->value('last_success_at');
        $service->record($this->apiCompany->id,'analytics_capture','RuntimeException');
        $service->record(Company::factory()->create()->id,'analytics_capture');
        $check = collect(app(SystemIntegrityService::class)->snapshot()['checks'])->firstWhere('key','analytics_capture');
        $this->assertSame('attention',$check['status']);
        $this->assertSame('RuntimeException',$check['error_code']);
        $this->assertSame($success,$check['last_success_at']);
    }
}
