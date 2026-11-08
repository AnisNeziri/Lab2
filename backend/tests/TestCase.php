<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Company $apiCompany;

    protected function actingAsApiUser(string $role = 'admin'): self
    {
        $this->seed(RolePermissionSeeder::class);

        $this->apiCompany = Company::factory()->create();
        $plainToken = 'test-token-'.$role;

        User::factory()->create([
            'company_id' => $role === 'superadmin' ? null : $this->apiCompany->id,
            'role' => $role,
            'api_token' => hash('sha256', $plainToken),
            'email_verified_at' => now(),
        ]);

        return $this->withHeader('Authorization', 'Bearer '.$plainToken);
    }

    protected function tenantAttributes(array $attributes = []): array
    {
        return array_merge(['company_id' => $this->apiCompany->id], $attributes);
    }

    /** Issue a fresh fixture session after role revocation or simulated token expiry. */
    protected function renewApiSession(User $user): void
    {
        $token = 'renewed-'.\Illuminate\Support\Str::uuid();
        $user->forceFill(['api_token' => hash('sha256', $token), 'api_token_expires_at' => now()->addDays(30)])->save();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
