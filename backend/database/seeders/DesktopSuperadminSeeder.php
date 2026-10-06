<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DesktopSuperadminSeeder extends Seeder
{
    public function run(): void
    {
        if (config('system.operation_mode') !== 'offline') {
            throw new \RuntimeException('Desktop recovery provisioning is restricted to offline installations.');
        }
        $user = User::withoutGlobalScopes()->where('email', 'aimsadmin@company.com')->first();
        $legacyHash = '$2y$12$9CJ3.c5nrp2CA03TDp3wueEVSeA5FO9A0S.Ad6zJrefB21ZKFfnOG';
        $password = (string) getenv('AIMS_DESKTOP_RECOVERY_PASSWORD');
        if (! $user || hash_equals($legacyHash, (string) $user->password)) {
            if (strlen($password) < 24) {
                throw new \RuntimeException('Start AIMS from the desktop launcher to securely provision its recovery account.');
            }
            $user ??= new User(['email' => 'aimsadmin@company.com']);
            $user->forceFill([
                'name' => 'AIMS Superadmin',
                'password' => Hash::make($password),
                'role' => 'superadmin',
                'is_active' => true,
                'email_verified_at' => now(),
                'must_change_password' => true,
                'temporary_password_consumed' => false,
            ])->save();
        }
        // The launcher keeps this candidate protected until the owner records it.
        // Repeating startup after a crash must display the same valid credential.
        if ($password !== '' && Hash::check($password, $user->password)) {
            $this->command?->line('AIMS_RECOVERY_PROVISIONED');
        }

        $role = Role::where('slug', 'superadmin')->first();
        if ($role) {
            UserRole::updateOrCreate(['user_id' => $user->id, 'role_id' => $role->id], ['assigned_at' => now()]);
        }
    }
}
