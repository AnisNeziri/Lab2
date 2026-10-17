<?php

use App\Models\Company;
use App\Models\OnboardingSession;
use App\Models\Product;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\InventoryIntegrityService;
use App\Services\Onboarding\OnboardingReconciliation;
use App\Services\PortableBackupService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Private subprocess: it can only migrate a newly allocated, owned verification DB. */
require __DIR__.'/../vendor/autoload.php';
set_exception_handler(function (Throwable $error) {
    fwrite(STDOUT, json_encode(['passed' => false, 'code' => 'restore_verification_failed']));
    exit(1);
});
$root = realpath((string) getenv('ONBOARDING_RESTORE_ROOT'));
$parent = realpath((string) getenv('ONBOARDING_RESTORE_PARENT'));
if (! $root || ! $parent || dirname($root) !== $parent || ! preg_match('/^[a-f0-9-]{36}$/', basename($root))) {
    throw new RuntimeException('Invalid owned restore destination.');
}
$db = realpath((string) getenv('DB_DATABASE'));
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || $db !== $root.DIRECTORY_SEPARATOR.'restore.sqlite' || filesize($db) !== 0 || getenv('DB_URL')) {
    throw new RuntimeException('Restore database must be a new owned SQLite file.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$source = $root.DIRECTORY_SEPARATOR.'source.aimsbackup';
if (! hash_equals($input['checksum'], hash_file('sha256', $source))) {
    throw new RuntimeException('Backup checksum differs.');
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) {
    fwrite(STDOUT, json_encode(['passed' => false, 'code' => 'restore_verification_failed']));
    exit(1);
});
Artisan::call('migrate', ['--force' => true]);
$decoy = Company::create(['name' => 'Isolation decoy', 'address' => 'Synthetic verification fixture', 'base_currency' => 'EUR']);
Product::create(['company_id' => $decoy->id, 'sku' => 'RESTORE-DECOY', 'name' => 'Synthetic ID remapping check', 'price' => 0, 'quantity' => 0, 'unit' => 'pcs']);
$company = Company::create(['name' => 'Independent restore verification', 'address' => 'Synthetic verification destination', 'base_currency' => 'EUR']);
$user = User::create(['company_id' => $company->id, 'name' => 'Restore verifier', 'email' => 'verifier@restore.test', 'password' => Hash::make(bin2hex(random_bytes(24))), 'role' => 'admin', 'email_verified_at' => now()]);
Auth::setUser($user);
$file = new UploadedFile($source, 'opening.aimsbackup', null, null, true);
$restore = app(PortableBackupService::class)->restore($file, null, 'replace', $input['passphrase']);
$user->unsetRelation('company');
$session = OnboardingSession::where('public_id', $input['session'])->firstOrFail();
$report = app(OnboardingReconciliation::class)->report($session);
$accounting = app(AccountingService::class)->reconciliation(false);
$products = 0;
foreach (Product::cursor() as $product) {
    app(InventoryIntegrityService::class)->assertProductReconciled($product);
    $products++;
}
$broken = 0;
foreach ($session->rows()->whereNotNull('posted_at')->cursor() as $row) {
    if (! DB::table($row->posted_entity_type)->where('id', $row->posted_entity_id)->where('company_id', $company->id)->exists()) {
        $broken++;
    }
}
$passed = $report['ready_for_review'] && $accounting['all_reconciled'] && $broken === 0;
fwrite(STDOUT, json_encode(['passed' => $passed, 'totals' => $report['actual'], 'source_differences' => $report['blocking'], 'accounting_reconciled' => $accounting['all_reconciled'], 'products_checked' => $products, 'broken_source_links' => $broken, 'database_isolated' => true], JSON_THROW_ON_ERROR));
exit($passed ? 0 : 1);
