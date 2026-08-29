<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperadminPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_manage_platform_users_and_view_usage(): void
    {
        $this->actingAsApiUser('superadmin');
        $company = Company::factory()->create();

        $created = $this->postJson('/api/superadmin/users', [
            'company_id' => $company->id,
            'name' => 'Platform Customer',
            'email' => 'platform@example.com',
            'role' => 'admin',
            'temporary_password' => 'Temporary.123',
            'temporary_password_confirmation' => 'Temporary.123',
        ])->assertCreated()->assertJsonPath('email', 'platform@example.com');

        $user = User::findOrFail($created->json('id'));
        $this->assertNotNull($user->email_verified_at);

        $this->putJson("/api/superadmin/users/{$user->id}", [
            'company_id' => $company->id,
            'name' => 'Updated Customer',
            'email' => 'updated@example.com',
            'role' => 'manager',
            'is_active' => true,
        ])->assertOk()->assertJsonPath('role', 'manager');

        $this->postJson("/api/superadmin/users/{$user->id}/reset-password", [
            'temporary_password' => 'Replacement.123',
            'temporary_password_confirmation' => 'Replacement.123',
        ])->assertOk();
        $this->assertTrue(Hash::check('Replacement.123', $user->fresh()->password));

        $this->getJson('/api/superadmin/dashboard')
            ->assertOk()
            ->assertJsonPath('total_users', 1)
            ->assertJsonStructure(['companies', 'active_users', 'new_users_30_days', 'logins_30_days']);

        $this->deleteJson("/api/superadmin/users/{$user->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_offline_superadmin_can_provision_one_desktop_user(): void
    {
        Config::set('system.operation_mode', 'offline');
        $this->actingAsApiUser('superadmin');

        $payload = [
            'company_id' => $this->apiCompany->id,
            'name' => 'Desktop Operator',
            'email' => 'desktop.operator@example.com',
            'role' => 'admin',
            'temporary_password' => 'Desktop.User.123',
            'temporary_password_confirmation' => 'Desktop.User.123',
        ];

        $this->postJson('/api/superadmin/users', $payload)
            ->assertCreated()
            ->assertJsonPath('email', $payload['email']);

        $localCompanyId = Company::query()->value('id');
        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'company_id' => $localCompanyId,
            'role' => 'admin',
        ]);

        $this->postJson('/api/superadmin/users', [
            ...$payload,
            'email' => 'second.desktop.operator@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('user');
    }
}
