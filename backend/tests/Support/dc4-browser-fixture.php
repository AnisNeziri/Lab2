<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('e2e') || config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath(base_path('database/dc4-preview.sqlite'))) {
    throw new RuntimeException('DC4 fixture requires isolated dc4-preview.sqlite.');
}
$company = Company::factory()->create(['name' => 'DC4 synthetic payroll company', 'base_currency' => 'EUR', 'timezone' => 'Europe/Belgrade']);
$user = User::updateOrCreate(['email' => 'dc4-payroll@e2e.example'], ['company_id' => $company->id, 'name' => 'DC4 Local Payroll Accountant', 'password' => bcrypt('dc4-local-test-secret'), 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'must_change_password' => false]);
Auth::login($user);
(new RolePermissionSeeder)->run(true);
echo "Prepared synthetic local DC4 payroll login. No bank/EDI integration.\n";
