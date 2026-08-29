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
            'company_id' => $this->apiCompany->id,
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
}
