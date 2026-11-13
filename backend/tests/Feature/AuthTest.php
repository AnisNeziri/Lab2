<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsApiUser(string $role = 'admin'): self
    {
        parent::actingAsApiUser($role);
        $user=\App\Models\User::where('company_id',$this->apiCompany->id)->firstOrFail();
        return $this->withHeader('Authorization','Bearer '.app(\App\Services\JwtService::class)->createAccessToken($user));
    }


    public function test_company_registration_creates_admin_user(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Jane Admin',
            'email' => 'jane@acme.test',
            'password' => 'Registration-test-2026!',
            'password_confirmation' => 'Registration-test-2026!',
            'company_name' => 'Acme Corp',
            'company_address' => '456 Industrial Way, Metro City',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('verification_required', true)
            ->assertJsonPath('email', 'jane@acme.test');

        $this->assertDatabaseHas('companies', ['name' => 'Acme Corp']);
        $this->assertDatabaseHas('users', [
            'email' => 'jane@acme.test',
            'role' => 'admin',
            'email_verified_at' => null,
        ]);
    }

    public function test_concurrent_logins_remain_valid_until_identity_logout(): void
    {
        $company = Company::factory()->create();
        $password = 'Independent-test-sessions-2026!';
        $owner = User::factory()->create(['company_id' => $company->id, 'password' => $password, 'email_verified_at' => now()]);
        $other = User::factory()->create(['company_id' => $company->id, 'password' => $password, 'email_verified_at' => now()]);
        $login = fn (User $user) => $this->postJson('/api/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()->json('access_token');
        $browser = $login($owner);
        $runner = $login($owner);
        $independent = $login($other);

        $this->assertNotSame($browser, $runner);
        $this->withToken($browser)->getJson('/api/me')->assertOk();
        $this->withToken($runner)->getJson('/api/me')->assertOk();
        $this->withToken($browser)->postJson('/api/logout')->assertOk();
        $this->withToken($runner)->getJson('/api/me')->assertUnauthorized();
        $this->withToken($independent)->getJson('/api/me')->assertOk();
    }

    public function test_admin_can_create_user_with_temporary_password(): void
    {
        $this->actingAsApiUser('admin');

        $response = $this->postJson('/api/users', [
            'name' => 'New Staff',
            'email' => 'staff@acme.test',
            'role' => 'staff',
            'temporary_password' => 'Temporary-pass-2026!',
            'temporary_password_confirmation' => 'Temporary-pass-2026!',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.email', 'staff@acme.test');

        $user = User::where('email', 'staff@acme.test')->first();
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->temporary_password_consumed);
    }

    public function test_temporary_password_can_only_be_used_once(): void
    {
        $this->actingAsApiUser('admin');

        $tempPassword = 'Temporary-pass-2026!';

        $this->postJson('/api/users', [
            'name' => 'Temp User',
            'email' => 'temp@acme.test',
            'role' => 'staff',
            'temporary_password' => $tempPassword,
            'temporary_password_confirmation' => $tempPassword,
        ])->assertCreated();

        $user = User::where('email', 'temp@acme.test')->first();
        $user->email_verified_at = now();
        $user->save();

        $firstLogin = $this->postJson('/api/login', [
            'email' => 'temp@acme.test',
            'password' => $tempPassword,
        ]);

        $firstLogin
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        $secondLogin = $this->postJson('/api/login', [
            'email' => 'temp@acme.test',
            'password' => $tempPassword,
        ]);

        $secondLogin->assertStatus(422);
    }

    public function test_user_must_change_password_before_accessing_protected_routes(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $company = Company::factory()->create();
        $tempPassword = 'Temporary-pass-2026!';

        $user = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'forced@acme.test',
            'password' => $tempPassword,
            'role' => 'staff',
            'api_token' => hash('sha256', 'forced-change-token'),
            'must_change_password' => true,
            'temporary_password_consumed' => true,
            'email_verified_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer forced-change-token')
            ->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        $changed = $this->withHeader('Authorization', 'Bearer forced-change-token')
            ->postJson('/api/change-password', [
                'password' => 'newRegistration-test-2026!',
                'password_confirmation' => 'newRegistration-test-2026!',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertFalse($user->must_change_password);

        $this->withHeader('Authorization', 'Bearer forced-change-token')
            ->getJson('/api/dashboard')
            ->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$changed->json('access_token'))
            ->getJson('/api/dashboard')->assertOk();
    }

    public function test_admin_can_update_user_role(): void
    {
        $this->actingAsApiUser('admin');

        $user = User::factory()->create([
            'company_id' => $this->apiCompany->id,
            'email' => 'promote@test.com',
            'role' => 'staff',
        ]);

        $this->putJson("/api/users/{$user->id}", ['role' => 'manager'])
            ->assertOk()
            ->assertJsonPath('user.role', 'manager');

        $this->assertSame('manager', $user->fresh()->role);
    }

    public function test_admin_can_remove_staff_user(): void
    {
        $this->actingAsApiUser('admin');

        $user = User::factory()->create([
            'company_id' => $this->apiCompany->id,
            'email' => 'remove@test.com',
            'role' => 'staff',
        ]);

        $this->deleteJson("/api/users/{$user->id}")
            ->assertNoContent();

        // Users are archived rather than physically erased so invoices,
        // movements and activity history retain a valid actor reference.
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
    }

    public function test_admin_cannot_remove_self(): void
    {
        $this->actingAsApiUser('admin');

        $admin = User::where('company_id', $this->apiCompany->id)->first();

        $this->deleteJson("/api/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
    }

    public function test_users_are_scoped_to_company(): void
    {
        $this->actingAsApiUser('admin');

        User::factory()->create([
            'company_id' => $this->apiCompany->id,
            'email' => 'same-company@test.com',
            'role' => 'staff',
        ]);

        $otherCompany = Company::factory()->create();
        User::factory()->create([
            'company_id' => $otherCompany->id,
            'email' => 'other-company@test.com',
            'role' => 'staff',
        ]);

        $response = $this->getJson('/api/users');

        $response->assertOk();

        $emails = collect($response->json())->pluck('email');
        $this->assertTrue($emails->contains('same-company@test.com'));
        $this->assertFalse($emails->contains('other-company@test.com'));
    }
}
