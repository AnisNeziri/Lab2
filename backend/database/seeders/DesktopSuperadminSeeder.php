<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;

class DesktopSuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'aimsadmin@company.com'],
            [
                'name' => 'AIMS Superadmin',
                'password' => '$2y$12$9CJ3.c5nrp2CA03TDp3wueEVSeA5FO9A0S.Ad6zJrefB21ZKFfnOG',
                'role' => 'superadmin',
                'is_active' => true,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_consumed' => false,
            ]
        );

        $role = Role::where('slug', 'superadmin')->first();
        if ($role) {
            UserRole::updateOrCreate(['user_id' => $user->id, 'role_id' => $role->id], ['assigned_at' => now()]);
        }
    }
}
