<?php

use App\Integrations\Sef\SefConfigurationService;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\InvoiceProfileService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\TaxCodeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;

/** Synthetic local-only signing identity. Never an ATK-issued certificate. */
function dc3LocalFiscalCompany(string $email): User
{
    $company = Company::factory()->create(['base_currency' => 'EUR', 'timezone' => 'UTC', 'name' => 'DC3 synthetic finance company']);
    $user = User::updateOrCreate(['email' => $email], ['company_id' => $company->id, 'name' => 'DC3 Local Test Accountant', 'password' => bcrypt('dc3-local-test-secret'), 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'must_change_password' => false, 'temporary_password_consumed' => false]);
    Auth::login($user);
    (new RolePermissionSeeder)->run(true);
    app(InvoiceProfileService::class)->updateForCurrentCompany([
        'legal_name' => 'DC3 synthetic local company', 'business_registration_number' => (string) random_int(800000000, 899999999), 'fiscal_number' => '600987654', 'is_vat_registered' => true, 'vat_number' => '330987654',
        'registered_address' => 'Test Street 1', 'municipality' => 'Prishtine', 'postal_code' => '10000', 'country_code' => 'XK', 'phone' => '+38338000000', 'email' => 'test@example.test',
        'invoice_prefix' => 'INV', 'credit_note_prefix' => 'CN', 'default_language' => 'bilingual', 'default_payment_terms_days' => 14, 'sales_mode' => 'business_only',
    ]);
    app(TaxCodeService::class)->initialize();
    $config = app(SefConfigurationService::class)->configure(['environment' => 'test', 'branch_id' => '1', 'pos_id' => '1', 'application_id' => '1', 'location' => 'LOCAL SYNTHETIC TEST ONLY', 'activity_policy' => 'retail', 'policy_basis' => 'Synthetic local scenario under reviewed retail policy. Not official registration.', 'enabled' => false, 'signature_format' => 'ieee_p1363', 'starting_coupon_no' => '1']);
    $path = tempnam(sys_get_temp_dir(), 'aims-dc3-public-conf-');
    file_put_contents($path, "[req]\ndefault_bits=2048\ndistinguished_name=dn\n[dn]\n");
    try {
        $options = ['config' => $path, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 2048, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem, null, $options);
        $csr = openssl_csr_new(['countryName' => 'XK', 'organizationName' => $config->taxpayer_nui, 'organizationalUnitName' => '1', 'localityName' => '1', 'commonName' => 'Synthetic local-only certificate'], $key, $options);
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);
        openssl_x509_export($cert, $certPem);
    } finally {
        unlink($path);
    }
    app(SefConfigurationService::class)->credentials($config, $pem, $certPem);

    return $user;
}
function dc3LocalInvoice(): Invoice
{
    $invoice = app(InvoiceService::class)->createAndIssue(['invoice_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'buyer' => ['legal_name' => 'Synthetic customer', 'business_registration_number' => '812222222', 'address' => 'Buyer Street', 'country_code' => 'XK'],
        'items' => [['description' => 'Local test service', 'unit' => 'pcs', 'quantity' => 1, 'unit_price' => 100, 'tax_code_id' => TaxCode::where('code', 'KS18')->value('id'), 'sef_category_id' => 1]]]);
    app(PaymentService::class)->processPayment($invoice, ['amount' => 118, 'payment_method' => 'cash']);

    return $invoice->fresh();
}
