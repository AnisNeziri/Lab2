<?php

namespace App\Console\Commands;

use Database\Seeders\DesktopSuperadminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;

class SetupDesktopCommand extends Command
{
    protected $signature = 'aims:desktop-setup';

    protected $description = 'Apply offline desktop migrations, permissions, and recovery superadmin';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DesktopSuperadminSeeder::class, '--force' => true]);
        // Desktop installations do not rely on an external scheduler. Run a
        // durable catch-up every time AIMS starts after migrations are ready.
        $this->call('inventory:sync-expiry-alerts');

        return self::SUCCESS;
    }
}
