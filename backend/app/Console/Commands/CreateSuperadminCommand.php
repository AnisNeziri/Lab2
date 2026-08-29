<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperadminCommand extends Command
{
    protected $signature = 'users:create-superadmin {email : Login email} {--name=System Superadmin : Display name}';

    protected $description = 'Create or reset the global superadmin account.';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));
        $password = env('SUPERADMIN_PASSWORD') ?: $this->secret('Password (minimum 12 characters)');

        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::withoutGlobalScopes()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'company_id' => null,
            'name' => $this->option('name'),
            'first_name' => explode(' ', $this->option('name'), 2)[0],
            'last_name' => trim(substr($this->option('name'), strlen(explode(' ', $this->option('name'), 2)[0]))),
            'password' => Hash::make($password),
            'role' => 'superadmin',
            'is_active' => true,
            'email_verified_at' => now(),
            'must_change_password' => false,
            'temporary_password_consumed' => false,
        ])->save();

        $role = Role::where('slug', 'superadmin')->firstOrFail();
        UserRole::updateOrCreate(['user_id' => $user->id, 'role_id' => $role->id], ['assigned_at' => now()]);

        $this->info("Superadmin account is ready for {$email}.");

        return self::SUCCESS;
    }
}
