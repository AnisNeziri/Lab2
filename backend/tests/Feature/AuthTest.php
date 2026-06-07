<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_registration_creates_admin_user(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Jane Admin',
            'email' => 'jane@acme.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'company_name' => 'Acme Corp',
            'company_address' => '456 Industrial Way, Metro City',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.company_name', 'Acme Corp')
            ->assertJsonPath('user.must_change_password', false);

        $this->assertDatabaseHas('companies', ['name' => 'Acme Corp']);
        $this->assertDatabaseHas('users', [
            'email' => 'jane@acme.test',
            'role' => 'admin',
        ]);
    }

    public function test_admin_can_create_user_with_temporary_password(): void
    {
        $this->actingAsApiUser('admin');

        $response = $this->postJson('/api/users', [
            'name' => 'New Staff',
            'email' => 'staff@acme.test',
            'role' => 'staff',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.email', 'staff@acme.test')
            ->assertJsonStructure(['temporary_password']);

        $user = User::where('email', 'staff@acme.test')->first();
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->temporary_password_consumed);
    }

    public function test_temporary_password_can_only_be_used_once(): void
    {
        $this->actingAsApiUser('admin');

        $createResponse = $this->postJson('/api/users', [
            'name' => 'Temp User',
            'email' => 'temp@acme.test',
            'role' => 'staff',
        ]);

        $tempPassword = $createResponse->json('temporary_password');

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
        $company = Company::factory()->create();
        $tempPassword = 'TempPass123!';

        $user = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'forced@acme.test',
            'password' => $tempPassword,
            'role' => 'staff',
            'api_token' => 'forced-change-token',
            'must_change_password' => true,
            'temporary_password_consumed' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer forced-change-token')
            ->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        $this->withHeader('Authorization', 'Bearer forced-change-token')
            ->postJson('/api/change-password', [
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertFalse($user->must_change_password);

        $this->withHeader('Authorization', 'Bearer forced-change-token')
            ->getJson('/api/dashboard')
            ->assertOk();
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
