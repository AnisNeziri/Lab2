<?php

namespace App\Integrations\Sef;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\FiscalConfiguration;
use App\Models\FiscalReceipt;
use App\Models\InvoiceProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SefConfigurationService
{
    public function configure(array $data): FiscalConfiguration
    {
        return DB::transaction(function () use ($data) {
            Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            $profile = InvoiceProfile::query()->firstOrFail();
            $nui = (string) $profile->business_registration_number;
            if (! preg_match('/^[1-9][0-9]{0,18}$/', $nui)) {
                $this->fail('Complete the existing invoice profile with the taxpayer NUI.');
            }
            if (($data['environment'] ?? 'test') !== 'test') {
                $this->fail('This build supports ATK TEST configuration only. Production remains unavailable.');
            }
            $config = FiscalConfiguration::where('environment', 'test')->lockForUpdate()->first();
            $values = collect($data)->only(['branch_id', 'pos_id', 'application_id', 'location', 'activity_policy', 'policy_basis', 'enabled', 'signature_format'])->all();
            foreach (['branch_id', 'pos_id', 'application_id'] as $field) {
                if (! preg_match('/^[1-9][0-9]{0,18}$/', (string) $values[$field])) {
                    $this->fail('Use the identifiers issued during ATK TEST onboarding.');
                }
            }
            if (! in_array($values['activity_policy'], ['business_only', 'retail', 'needs_review'], true)) {
                $this->fail('Choose the reviewed business activity.');
            }
            if ($values['activity_policy'] !== 'needs_review' && mb_strlen(trim($values['policy_basis'] ?? '')) < 10) {
                $this->fail('Record the accountant-confirmed activity/policy basis.');
            }
            if (! in_array($values['signature_format'], ['ieee_p1363', 'der'], true)) {
                $this->fail('Choose a published signing profile.');
            }
            if ($config) {
                if (FiscalReceipt::where('configuration_id', $config->id)->exists()) {
                    foreach (['branch_id', 'pos_id', 'application_id', 'taxpayer_nui'] as $field) {
                        if ((string) ($field === 'taxpayer_nui' ? $nui : $values[$field]) !== (string) $config->$field) {
                            $this->fail('Prepared receipts bind this POS identity. A new registration/version workflow is required to change it.');
                        }
                    }
                }
                $config->update([...$values, 'taxpayer_nui' => $nui]);
            } else {
                $start = (string) ($data['starting_coupon_no'] ?? '');
                if (! preg_match('/^[1-9][0-9]{0,17}$/', $start)) {
                    $this->fail('Enter the next unused coupon number confirmed across all business branches.');
                }
                $config = FiscalConfiguration::create([...$values, 'environment' => 'test', 'taxpayer_nui' => $nui, 'next_coupon_no' => $start]);
            }
            $this->audit('fiscalization.configuration.changed', $config, ['environment' => 'test', 'enabled' => $config->enabled, 'policy' => $config->activity_policy, 'signature_format' => $config->signature_format]);

            return $config->fresh();
        });
    }

    public function credentials(FiscalConfiguration $config, string $keyPem, string $certificatePem, ?string $passphrase = null): void
    {
        if ($config->environment !== 'test') {
            $this->fail('Production credentials are not supported in this uncertified build.');
        }
        $key = openssl_pkey_get_private($keyPem, $passphrase ?? '');
        $cert = openssl_x509_read($certificatePem);
        if (! $key || ! $cert || ! openssl_x509_check_private_key($cert, $key)) {
            $this->fail('The certificate and private key do not form a valid pair.');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            $this->fail('The ATK profile requires an ECDSA P-256 key.');
        }
        $info = openssl_x509_parse($cert);
        if (! $info || $info['validFrom_time_t'] > time() || $info['validTo_time_t'] <= time()) {
            $this->fail('The certificate is not currently valid.');
        }
        foreach (['O' => 'taxpayer_nui', 'OU' => 'pos_id', 'L' => 'branch_id'] as $part => $field) {
            if ((string) ($info['subject'][$part] ?? '') !== (string) $config->$field) {
                $this->fail('The certificate identity does not match this taxpayer/branch/POS configuration.');
            }
        }
        $fingerprint = openssl_x509_fingerprint($cert, 'sha256');
        $config->update(['credentials' => ['private_key' => $keyPem, 'passphrase' => $passphrase, 'certificate' => $certificatePem], 'credential_fingerprint' => $fingerprint,
            'certificate_valid_from' => date('Y-m-d H:i:s', $info['validFrom_time_t']), 'certificate_valid_to' => date('Y-m-d H:i:s', $info['validTo_time_t'])]);
        $this->audit('fiscalization.credential.changed', $config, ['environment' => 'test', 'certificate_fingerprint' => $fingerprint, 'source' => 'server_cli', 'official_issuance_verified' => false]);
    }

    public function assertSigning(FiscalConfiguration $config): void
    {
        if (! $config->credential_fingerprint || ! $config->credentials || ! $config->certificate_valid_to || $config->certificate_valid_to->isPast() || $config->certificate_valid_from?->isFuture()) {
            $this->fail('Install a valid ATK TEST credential through the server command. Official interoperability is NOT TESTED until ATK returns evidence.');
        }
    }

    private function audit(string $action, FiscalConfiguration $config, array $facts): void
    {
        ActivityLog::create(['company_id' => $config->company_id, 'user_id' => Auth::id(), 'action' => $action, 'entity' => 'FiscalConfiguration', 'entity_id' => $config->id, 'description' => str_replace('.', ' ', $action), 'new_value' => $facts]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['configuration' => $message]);
    }
}
