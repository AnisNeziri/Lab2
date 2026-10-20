<?php

namespace App\Console\Commands;

use App\Integrations\Sef\SefConfigurationService;
use App\Models\FiscalConfiguration;
use Illuminate\Console\Command;

class InstallSefCredentials extends Command
{
    protected $signature = 'sef:credentials {company : Company ID} {--environment=test} {--key-file=} {--certificate-file=} {--passphrase-env= : Name of a server environment variable; never a password value}';

    protected $description = 'Install an encrypted ATK TEST key/certificate pair on the server without exposing it to the browser';

    public function handle(SefConfigurationService $service): int
    {
        if ($this->option('environment') !== 'test') {
            $this->error('Production signing is unavailable.');

            return self::FAILURE;
        }
        $config = FiscalConfiguration::withoutGlobalScopes()->where('company_id', $this->argument('company'))->where('environment', 'test')->first();
        if (! $config) {
            $this->error('Create the company ATK TEST configuration first.');

            return self::FAILURE;
        }
        $files = [];
        foreach (['key-file', 'certificate-file'] as $option) {
            $path = $this->option($option);
            if (! $path || ! is_file($path) || filesize($path) > 65536) {
                $this->error('Supply local PEM files of at most64KiB.');

                return self::FAILURE;
            }
            $files[$option] = file_get_contents($path);
        }
        $name = $this->option('passphrase-env');
        $passphrase = $name ? getenv($name) : null;
        try {
            $service->credentials($config, $files['key-file'], $files['certificate-file'], $passphrase ?: null);
        } catch (\Throwable $e) {
            $this->error('Credential installation failed. Check the key pair, P-256 curve, certificate dates and registered taxpayer/branch/POS identity.');

            return self::FAILURE;
        }
        $this->info('ATK TEST credential encrypted and installed. Fingerprint: '.$config->fresh()->credential_fingerprint);

        return self::SUCCESS;
    }
}
