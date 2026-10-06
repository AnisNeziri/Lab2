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
        // Reject a web/server connection before migrations or seeders can write.
        if (config('system.operation_mode') !== 'offline'
            || config('database.default') !== 'sqlite'
            || filled(config('database.connections.sqlite.url'))) {
            $this->error('Desktop setup requires offline mode and the local SQLite database. No changes were made.');
            return self::FAILURE;
        }
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        app(RolePermissionSeeder::class)->run(preserveExisting: true);
        if ($this->call('db:seed', ['--class' => DesktopSuperadminSeeder::class, '--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        // Desktop installations do not rely on an external scheduler. Run a
        // durable catch-up every time AIMS starts after migrations are ready.
        $this->call('inventory:sync-expiry-alerts');
        $this->call('automations:tick');
        $this->call('analytics:capture');

        return self::SUCCESS;
    }
}
