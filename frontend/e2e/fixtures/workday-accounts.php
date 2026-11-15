<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../../backend/vendor/autoload.php';
$app = require __DIR__.'/../../../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('e2e') || config('database.default') !== 'sqlite'
    || realpath(config('database.connections.sqlite.database')) !== realpath(__DIR__.'/../../../backend/database/e2e.sqlite')) {
    throw new RuntimeException('The synthetic browser database is required.');
}

$password = getenv('AIMS_E2E_WORKDAY_PASSWORD');
if (! is_string($password) || ! preg_match('/\A[a-f0-9]{48}\z/', $password)) {
    throw new RuntimeException('An ephemeral browser fixture password is required.');
}

$company = Company::where('name', 'Enterprise Demo Co.')->sole();
foreach (['purchasing', 'warehouse', 'sales', 'accountant', 'superadmin'] as $role) {
    User::firstOrCreate(['email' => $role.'@workday.e2e.example'], [
        'company_id' => $role === 'superadmin' ? null : $company->id,
        'name' => ucfirst($role).' Browser Fixture',
        'password' => Hash::make($password),
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
        'must_change_password' => false,
    ]);
}
