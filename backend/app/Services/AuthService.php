<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        private JwtService $jwt
    ) {}

    public function attemptLogin(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || $user->is_active === false) {
            return ['error' => 'not_found'];
        }

        if ($user->must_change_password && $user->temporary_password_consumed) {
            return ['error' => 'temp_expired'];
        }

        if (! Hash::check($password, $user->password)) {
            return ['error' => 'invalid_password'];
        }

        if ($user->must_change_password && ! $user->temporary_password_consumed) {
            $user->temporary_password_consumed = true;
            $user->save();
        }

        return $this->tokenResponse($user);
    }

    public function register(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            $company = Company::create([
                'name' => $data['company_name'],
                'address' => $data['company_address'],
            ]);

            $parts = explode(' ', trim($data['name']), 2);

            $user = User::create([
                'company_id' => $company->id,
                'name' => $data['name'],
                'first_name' => $parts[0] ?? $data['name'],
                'last_name' => $parts[1] ?? '',
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'admin',
                'is_active' => true,
                'must_change_password' => false,
                'temporary_password_consumed' => false,
            ]);

            $this->assignRole($user, 'admin');

            return $user;
        });

        $user->load('company');

        return $this->tokenResponse($user);
    }

    public function refresh(string $refreshToken): ?array
    {
        $tokens = $this->jwt->refresh($refreshToken);

        if (! $tokens) {
            return null;
        }

        $payload = $this->jwt->validateAccessToken($tokens['access_token']);
        $user = User::find($payload->sub ?? null);

        if (! $user) {
            return null;
        }

        return array_merge($tokens, [
            'token' => $tokens['access_token'],
            'user' => $this->formatUser($user),
        ]);
    }

    public function logout(User $user): void
    {
        $user->forceFill(['api_token' => null])->save();
        $this->jwt->revokeUserTokens($user);
    }

    public function changePassword(User $user, array $data): User
    {
        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->temporary_password_consumed = false;
        $user->save();

        return $user;
    }

    public function formatUser(User $user): array
    {
        $user->loadMissing('company');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'company_name' => $user->company?->name,
            'must_change_password' => $user->must_change_password,
            'permissions' => app(PermissionService::class)->forRole($user->role),
        ];
    }

    private function tokenResponse(User $user): array
    {
        $tokens = $this->jwt->issueTokens($user);
        $user->forceFill(['api_token' => null])->save();

        return array_merge($tokens, [
            'token' => $tokens['access_token'],
            'user' => $this->formatUser($user),
        ]);
    }

    private function assignRole(User $user, string $slug): void
    {
        $role = Role::where('slug', $slug)->first();

        if ($role) {
            UserRole::updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id],
                ['assigned_at' => now()]
            );
        }
    }
}
