<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function actingAsApiUser(string $role = 'admin'): self
    {
        $user = User::factory()->create([
            'role' => $role,
            'api_token' => 'test-token-' . $role,
        ]);

        return $this->withHeader('Authorization', 'Bearer ' . $user->api_token);
    }
}
