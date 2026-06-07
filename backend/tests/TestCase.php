<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Company $apiCompany;

    protected function actingAsApiUser(string $role = 'admin'): self
    {
        $this->apiCompany = Company::factory()->create();

        $user = User::factory()->create([
            'company_id' => $this->apiCompany->id,
            'role' => $role,
            'api_token' => 'test-token-'.$role,
        ]);

        return $this->withHeader('Authorization', 'Bearer '.$user->api_token);
    }

    protected function tenantAttributes(array $attributes = []): array
    {
        return array_merge(['company_id' => $this->apiCompany->id], $attributes);
    }
}
