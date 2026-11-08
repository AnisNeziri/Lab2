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

    protected function actingAsApiUser(string $role = 'admin'): self
    {
        parent::actingAsApiUser($role);
        $user = User::where('api_token', hash('sha256', 'test-token-'.$role))->firstOrFail();
        return $this->withHeader('Authorization', 'Bearer '.app(\App\Services\JwtService::class)->issueTokens($user)['access_token']);
    }


    public function test_online_superadmin_can_provision_the_first_owner_but_cannot_take_over_existing_users(): void
    {
        Config::set('system.operation_mode', 'online');
        $this->actingAsApiUser('superadmin');
        $company = Company::factory()->create();

        $created = $this->postJson('/api/superadmin/users', [
            'company_id' => $company->id,
            'name' => 'Platform Customer',
            'email' => 'platform@example.com',
            'role' => 'admin',
            'temporary_password' => 'Temporary.Test.2026!',
            'temporary_password_confirmation' => 'Temporary.Test.2026!',
        ])->assertCreated()->assertJsonPath('email', 'platform@example.com');

        $user = User::findOrFail($created->json('id'));
        $this->assertNotNull($user->email_verified_at);

        $this->putJson("/api/superadmin/users/{$user->id}", [
            'company_id' => $company->id,
            'name' => 'Updated Customer',
            'email' => 'updated@example.com',
            'role' => 'manager',
            'is_active' => true,
        ])->assertForbidden();

        $this->postJson("/api/superadmin/users/{$user->id}/reset-password", [
            'temporary_password' => 'Replacement.Test.2026!',
            'temporary_password_confirmation' => 'Replacement.Test.2026!',
        ])->assertForbidden();
        $this->assertTrue(Hash::check('Temporary.Test.2026!', $user->fresh()->password));

        $this->getJson('/api/superadmin/dashboard')
            ->assertOk()
            ->assertJsonPath('total_users', 1)
            ->assertJsonStructure(['companies', 'active_users', 'new_users_30_days', 'logins_30_days']);

        $this->deleteJson("/api/superadmin/users/{$user->id}")->assertForbidden();
        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_offline_superadmin_can_provision_one_desktop_user(): void
    {
        Config::set('system.operation_mode', 'offline');
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->apiCompany = Company::sole();
        $operator = User::factory()->create(['company_id' => null, 'role' => 'superadmin', 'email_verified_at' => now()]);
        $this->withHeader('Authorization', 'Bearer '.app(\App\Services\JwtService::class)->issueTokens($operator)['access_token']);

        $payload = [
            'company_id' => $this->apiCompany->id,
            'name' => 'Desktop Operator',
            'email' => 'desktop.operator@example.com',
            'role' => 'admin',
            'temporary_password' => 'Desktop.User.123',
            'temporary_password_confirmation' => 'Desktop.User.123',
        ];

        $response = $this->postJson('/api/superadmin/users', $payload)
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

        $user = User::findOrFail($response->json('id'));
        $oldToken = app(\App\Services\JwtService::class)->issueTokens($user)['access_token'];
        $this->postJson("/api/superadmin/users/{$user->id}/reset-password", [
            'temporary_password' => 'Replacement.Test.2026!',
            'temporary_password_confirmation' => 'Replacement.Test.2026!',
        ])->assertOk();
        $this->assertTrue(Hash::check('Replacement.Test.2026!', $user->fresh()->password));
        $this->withHeader('Authorization', 'Bearer '.$oldToken)->getJson('/api/me')->assertUnauthorized();
    }
}
